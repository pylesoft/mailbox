<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\OAuth;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pyle\Mailbox\Models\MailboxOAuthToken;

class GmailUserOAuthService
{
    private const PROVIDER = 'gmail-user';

    public function authorizationUrl(?int $connectionId = null, ?string $returnTo = null, ?string $userReference = null): string
    {
        $clientId = (string) config('mailbox.oauth.gmail.client_id', '');
        $redirectUri = $this->redirectUri();
        $scopes = (array) config('mailbox.oauth.gmail.scopes', []);

        if ($clientId === '') {
            throw new \RuntimeException('MAILBOX_OAUTH_GMAIL_CLIENT_ID is not configured.');
        }

        $state = bin2hex(random_bytes(24));
        $normalizedReturnTo = $this->normalizeReturnTo($returnTo) ?? $this->defaultReturnTo();

        Cache::put($this->stateKey($state), [
            'connection_id' => $connectionId,
            'return_to' => $normalizedReturnTo,
            'user_reference' => $this->stringOrNull($userReference),
        ], now()->addSeconds($this->stateTtlSeconds()));

        return sprintf(
            'https://accounts.google.com/o/oauth2/v2/auth?%s',
            http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => implode(' ', $scopes),
                'access_type' => 'offline',
                'include_granted_scopes' => 'true',
                'prompt' => 'consent',
                'state' => $state,
            ]),
        );
    }

    public function handleCallback(string $state, string $code): GmailOAuthCallbackResult
    {
        $statePayload = $this->readStatePayload($state);

        $clientId = (string) config('mailbox.oauth.gmail.client_id', '');
        $clientSecret = (string) config('mailbox.oauth.gmail.client_secret', '');

        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException('Google OAuth credentials are missing.');
        }

        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ]);

        if (! $tokenResponse->successful()) {
            throw new \RuntimeException('Failed to exchange OAuth code for token: '.$tokenResponse->body());
        }

        /** @var array<string, mixed> $tokenData */
        $tokenData = $tokenResponse->json();

        $accessToken = (string) Arr::get($tokenData, 'access_token', '');

        if ($accessToken === '') {
            throw new \RuntimeException('Token response does not include access_token.');
        }

        $idTokenClaims = $this->decodeJwtClaims((string) Arr::get($tokenData, 'id_token', ''));

        $profileResponse = Http::withToken($accessToken)
            ->acceptJson()
            ->get('https://www.googleapis.com/oauth2/v3/userinfo');

        $googleProfile = [];

        if ($profileResponse->successful()) {
            /** @var array<string, mixed> $profile */
            $profile = $profileResponse->json() ?? [];
            $googleProfile = $profile;
        }

        $externalUserId = $this->stringOrNull(Arr::get($googleProfile, 'sub'))
            ?? $this->stringOrNull(Arr::get($idTokenClaims, 'sub'));
        $email = $this->stringOrNull(Arr::get($googleProfile, 'email'))
            ?? $this->stringOrNull(Arr::get($idTokenClaims, 'email'));

        $lookup = ['provider' => self::PROVIDER];

        if ($externalUserId !== null) {
            $lookup['external_user_id'] = $externalUserId;
        } elseif ($email !== null) {
            $lookup['email'] = $email;
        } else {
            throw new \RuntimeException('Unable to identify Google OAuth user from token/profile response.');
        }

        $scopesRaw = (string) Arr::get($tokenData, 'scope', '');
        $scopes = array_values(array_filter(explode(' ', $scopesRaw)));

        if ($scopes === []) {
            $scopes = (array) config('mailbox.oauth.gmail.scopes', []);
        }

        $token = MailboxOAuthToken::query()->updateOrCreate($lookup, [
            'mailbox_connection_id' => isset($statePayload['connection_id']) ? (int) $statePayload['connection_id'] : null,
            'tenant_id' => null,
            'email' => $email,
            'access_token' => $accessToken,
            'refresh_token' => $this->stringOrNull(Arr::get($tokenData, 'refresh_token')),
            'token_type' => (string) Arr::get($tokenData, 'token_type', 'Bearer'),
            'scopes' => $scopes,
            'expires_at' => isset($tokenData['expires_in']) ? now()->addSeconds((int) $tokenData['expires_in']) : null,
            'last_refreshed_at' => now(),
            'meta' => [
                'display_name' => $this->stringOrNull(Arr::get($googleProfile, 'name')),
                'raw_token_fields' => array_keys($tokenData),
            ],
            'revoked_at' => null,
        ]);

        Cache::forget($this->stateKey($state));

        $context = $this->stateContextFromPayload($statePayload);

        return new GmailOAuthCallbackResult($token, $context['return_to'], $context['user_reference']);
    }

    /**
     * @return array{return_to: ?string, user_reference: ?string}|null
     */
    public function stateContext(string $state): ?array
    {
        $payload = Cache::get($this->stateKey($state));

        if (! is_array($payload)) {
            return null;
        }

        return $this->stateContextFromPayload($payload);
    }

    public function stateKey(string $state): string
    {
        return 'mailbox:oauth:gmail:state:'.$state;
    }

    private function redirectUri(): string
    {
        $configured = config('mailbox.oauth.gmail.redirect_uri');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (! app('router')->has('mailbox.oauth.gmail.callback')) {
            throw new \RuntimeException('OAuth callback route is not registered. Enable mailbox.oauth or configure oauth.gmail.redirect_uri.');
        }

        return route('mailbox.oauth.gmail.callback');
    }

    private function stateTtlSeconds(): int
    {
        return max(60, (int) config('mailbox.oauth.state_ttl_seconds', 600));
    }

    /**
     * @return array<string, mixed>
     */
    private function readStatePayload(string $state): array
    {
        $statePayload = Cache::get($this->stateKey($state));

        if (! is_array($statePayload)) {
            throw new \RuntimeException('OAuth state is missing or expired. Restart the sign-in flow.');
        }

        return $statePayload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{return_to: ?string, user_reference: ?string}
     */
    private function stateContextFromPayload(array $payload): array
    {
        return [
            'return_to' => $this->normalizeReturnTo($payload['return_to'] ?? null) ?? $this->defaultReturnTo(),
            'user_reference' => $this->stringOrNull($payload['user_reference'] ?? null),
        ];
    }

    private function defaultReturnTo(): ?string
    {
        $value = config('mailbox.oauth.default_return_url');

        return $this->normalizeReturnTo(is_string($value) ? $value : null);
    }

    private function normalizeReturnTo(?string $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (str_starts_with($value, '/')) {
            return $value;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) $parts['host']);

        if ($host === '') {
            return null;
        }

        return in_array($host, $this->allowedReturnHosts(), true) ? $value : null;
    }

    /** @return array<string, mixed> */
    private function decodeJwtClaims(string $jwt): array
    {
        if ($jwt === '' || ! str_contains($jwt, '.')) {
            return [];
        }

        $parts = explode('.', $jwt);

        if (count($parts) < 2) {
            return [];
        }

        $payload = strtr((string) $parts[1], '-_', '+/');
        $padding = strlen($payload) % 4;

        if ($padding > 0) {
            $payload .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($payload, true);

        if (! is_string($decoded)) {
            return [];
        }

        $claims = json_decode($decoded, true);

        return is_array($claims) ? $claims : [];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<int, string> */
    private function allowedReturnHosts(): array
    {
        $configured = config('mailbox.oauth.allowed_return_hosts', []);

        if (! is_array($configured)) {
            return [];
        }

        $hosts = array_values(array_filter(array_map(function (mixed $host): string {
            if (! is_string($host)) {
                return '';
            }

            $normalized = strtolower(trim($host));

            if ($normalized === '') {
                return '';
            }

            if (str_contains($normalized, '://')) {
                $parsedHost = parse_url($normalized, PHP_URL_HOST);

                return is_string($parsedHost) ? strtolower($parsedHost) : '';
            }

            return $normalized;
        }, $configured), static fn (string $host): bool => $host !== ''));

        return array_values(array_unique($hosts));
    }
}

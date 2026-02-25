<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\OAuth;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pyle\Mailbox\Models\MailboxOAuthToken;

class MsGraphUserOAuthService
{
    private const PROVIDER = 'ms-graph-user';

    public function authorizationUrl(?int $connectionId = null, ?string $returnTo = null, ?string $userReference = null): string
    {
        $tenantId = (string) config('mailbox.drivers.ms-graph.tenant_id', 'common');
        $clientId = (string) config('mailbox.drivers.ms-graph.client_id', '');
        $redirectUri = $this->redirectUri();
        $scopes = (array) config('mailbox.oauth.ms_graph.scopes', []);

        if ($clientId === '') {
            throw new \RuntimeException('MS365_CLIENT_ID is not configured.');
        }

        $state = bin2hex(random_bytes(24));
        $normalizedReturnTo = $this->normalizeReturnTo($returnTo) ?? $this->defaultReturnTo();

        Cache::put($this->stateKey($state), [
            'connection_id' => $connectionId,
            'return_to' => $normalizedReturnTo,
            'user_reference' => $this->stringOrNull($userReference),
        ], now()->addSeconds($this->stateTtlSeconds()));

        return sprintf(
            'https://login.microsoftonline.com/%s/oauth2/v2.0/authorize?%s',
            rawurlencode($tenantId),
            http_build_query([
                'client_id' => $clientId,
                'response_type' => 'code',
                'redirect_uri' => $redirectUri,
                'response_mode' => 'query',
                'scope' => implode(' ', $scopes),
                'state' => $state,
            ]),
        );
    }

    public function handleCallback(string $state, string $code): MsGraphOAuthCallbackResult
    {
        $statePayload = $this->readStatePayload($state);

        $tenantId = (string) config('mailbox.drivers.ms-graph.tenant_id', 'common');
        $clientId = (string) config('mailbox.drivers.ms-graph.client_id', '');
        $clientSecret = (string) config('mailbox.drivers.ms-graph.client_secret', '');

        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException('MS Graph OAuth credentials are missing.');
        }

        $tokenResponse = Http::asForm()->post(
            sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token', $tenantId),
            [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
            ],
        );

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
        $graphProfile = [];
        $profileResponse = Http::withToken($accessToken)
            ->acceptJson()
            ->get('https://graph.microsoft.com/v1.0/me?$select=id,mail,userPrincipalName,displayName');

        if ($profileResponse->successful()) {
            /** @var array<string, mixed> $profile */
            $profile = $profileResponse->json() ?? [];
            $graphProfile = $profile;
        }

        $externalUserId = $this->stringOrNull(Arr::get($graphProfile, 'id'))
            ?? $this->stringOrNull(Arr::get($idTokenClaims, 'oid'));
        $email = $this->stringOrNull(Arr::get($graphProfile, 'mail'))
            ?? $this->stringOrNull(Arr::get($graphProfile, 'userPrincipalName'))
            ?? $this->stringOrNull(Arr::get($idTokenClaims, 'preferred_username'));
        $tenant = $this->stringOrNull(Arr::get($idTokenClaims, 'tid'));

        $lookup = ['provider' => self::PROVIDER];

        if ($externalUserId !== null) {
            $lookup['external_user_id'] = $externalUserId;
        } elseif ($email !== null) {
            $lookup['email'] = $email;
        } else {
            throw new \RuntimeException('Unable to identify OAuth user from token/profile response.');
        }

        $scopesRaw = (string) Arr::get($tokenData, 'scope', '');
        $scopes = array_values(array_filter(explode(' ', $scopesRaw)));

        if ($scopes === []) {
            $scopes = (array) config('mailbox.oauth.ms_graph.scopes', []);
        }

        $token = MailboxOAuthToken::query()->updateOrCreate($lookup, [
            'mailbox_connection_id' => isset($statePayload['connection_id']) ? (int) $statePayload['connection_id'] : null,
            'tenant_id' => $tenant,
            'email' => $email,
            'access_token' => $accessToken,
            'refresh_token' => $this->stringOrNull(Arr::get($tokenData, 'refresh_token')),
            'token_type' => (string) Arr::get($tokenData, 'token_type', 'Bearer'),
            'scopes' => $scopes,
            'expires_at' => isset($tokenData['expires_in']) ? now()->addSeconds((int) $tokenData['expires_in']) : null,
            'last_refreshed_at' => now(),
            'meta' => [
                'display_name' => $this->stringOrNull(Arr::get($graphProfile, 'displayName')),
                'raw_token_fields' => array_keys($tokenData),
            ],
            'revoked_at' => null,
        ]);

        Cache::forget($this->stateKey($state));

        $context = $this->stateContextFromPayload($statePayload);

        return new MsGraphOAuthCallbackResult($token, $context['return_to'], $context['user_reference']);
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
        return 'mailbox:oauth:state:'.$state;
    }

    private function redirectUri(): string
    {
        $configured = config('mailbox.oauth.ms_graph.redirect_uri');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (! app('router')->has('mailbox.oauth.ms-graph.callback')) {
            throw new \RuntimeException('OAuth callback route is not registered. Enable mailbox.oauth or configure oauth.ms_graph.redirect_uri.');
        }

        return route('mailbox.oauth.ms-graph.callback');
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

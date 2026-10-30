<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\OAuth;

use Illuminate\Support\Facades\Http;
use Pyle\Mailbox\Contracts\GrantRefresher;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\StoredGrant;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\GrantRefreshException;
use Throwable;

final class GmailGrantRefresher implements GrantRefresher
{
    use VerifiesGoogleIdentity;

    public function refresh(GrantContext $context, StoredGrant $grant): StoredGrant
    {
        $clientId = config('mailbox.oauth.gmail.client_id');
        $clientSecret = config('mailbox.oauth.gmail.client_secret');
        if (! is_string($clientId) || $clientId === '' || ! is_string($clientSecret) || $clientSecret === '') {
            throw new GrantRefreshException('Google OAuth client credentials are required.', false);
        }
        if (! in_array($context->driver, ['gmail', 'google-workspace'], true) || $grant->identity->provider !== 'gmail'
            || $grant->identity->issuer !== 'https://accounts.google.com' || $grant->identity->clientId !== $clientId
            || preg_match('/\A[\x21-\x7e]{1,255}\z/', $grant->identity->subject) !== 1
            || $grant->identity->tenantId !== null || $this->token($grant->refreshToken()) === null) {
            throw new GrantAccessDeniedException('Renewable authorization for the selected Google identity and client is required.');
        }
        try {
            $response = Http::asForm()->acceptJson()->withOptions(['verify' => true, 'allow_redirects' => false])
                ->timeout(30)->post('https://oauth2.googleapis.com/token', [
                    'client_id' => $clientId, 'client_secret' => $clientSecret,
                    'grant_type' => 'refresh_token', 'refresh_token' => $grant->refreshToken(),
                ]);
        } catch (Throwable) {
            throw new GrantRefreshException('Google refresh transport failed.', true, 1);
        }
        if (! $response->successful()) {
            $error = $response->json('error');
            if ($response->status() === 429 || $response->serverError() || in_array($error, ['server_error', 'temporarily_unavailable'], true)) {
                $delay = filter_var($response->header('Retry-After'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 86400]]);
                throw new GrantRefreshException('Google refresh is temporarily unavailable.', true, $delay === false ? 1 : $delay);
            }
            if (in_array($error, ['invalid_grant', 'insufficient_scope', 'invalid_scope', 'consent_required', 'interaction_required'], true)) {
                throw new GrantAccessDeniedException('Google delegated authorization is invalid or insufficient. Reconnect authorization.');
            }
            throw new GrantRefreshException('Google rejected the refresh exchange.', false);
        }
        $data = $response->json();
        if (is_array($data) && array_key_exists('id_token', $data)) {
            $this->assertIdentity($data['id_token'], $grant);
        }
        $accessToken = is_array($data) ? $this->token($data['access_token'] ?? null) : null;
        $refreshToken = is_array($data) && array_key_exists('refresh_token', $data) ? $this->token($data['refresh_token']) : $grant->refreshToken();
        if (! is_array($data) || $accessToken === null || $refreshToken === null
            || ! is_int($data['expires_in'] ?? null) || $data['expires_in'] <= 30
            || ! is_string($data['token_type'] ?? null) || strcasecmp($data['token_type'], 'Bearer') !== 0) {
            throw new GrantRefreshException('Google returned invalid refresh credentials.', false);
        }
        $scopes = $grant->scopes;
        if (array_key_exists('scope', $data)) {
            if (! is_string($data['scope']) || preg_match('/\A[\x21\x23-\x5b\x5d-\x7e]+(?: [\x21\x23-\x5b\x5d-\x7e]+)*\z/', $data['scope']) !== 1) {
                throw new GrantAccessDeniedException('Google returned invalid delegated scopes. Reconnect authorization.');
            }
            $scopes = explode(' ', $data['scope']);
        }

        return new StoredGrant($grant->identity, $accessToken, $refreshToken,
            now()->addSeconds($data['expires_in'])->toDateTimeImmutable(), $scopes,
            bin2hex(random_bytes(16)), $grant->email, $grant->metadata, $grant->nonceHash());
    }

    private function token(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && preg_match('/[\x00-\x20\x7f]/', $value) !== 1 ? $value : null;
    }

    private function assertIdentity(#[\SensitiveParameter] mixed $jwt, StoredGrant $grant): void
    {
        $claims = $this->googleIdentityClaims($jwt, $grant->identity->clientId);
        if ($claims === null
            || $claims['sub'] !== $grant->identity->subject
            || (array_key_exists('nonce', $claims) && (! is_string($claims['nonce']) || $grant->nonceHash() === null
                || ! hash_equals($grant->nonceHash(), hash('sha256', $claims['nonce']))))) {
            throw new GrantAccessDeniedException('Google refresh identity does not match the selected grant. Reconnect authorization.');
        }
    }
}

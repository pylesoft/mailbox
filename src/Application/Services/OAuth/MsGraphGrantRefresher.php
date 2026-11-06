<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\OAuth;

use Illuminate\Support\Facades\Http;
use Pyle\Mailbox\Contracts\GrantRefresher;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\StoredGrant;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\GrantRefreshException;
use Pyle\Mailbox\Exceptions\OAuthEnrollmentException;
use Throwable;

final class MsGraphGrantRefresher implements GrantRefresher
{
    use VerifiesMsGraphIdentity;

    public function refresh(GrantContext $context, StoredGrant $grant): StoredGrant
    {
        $clientId = config('mailbox.drivers.ms-graph.client_id');
        $clientSecret = config('mailbox.drivers.ms-graph.client_secret');
        try {
            $authority = $this->microsoftAuthority();
        } catch (OAuthEnrollmentException) {
            throw new GrantRefreshException('Microsoft OAuth requires a supported authority or tenant GUID.', false);
        }
        if (! is_string($clientId) || $clientId === '' || ! is_string($clientSecret) || $clientSecret === '') {
            throw new GrantRefreshException('Microsoft OAuth client credentials are required.', false);
        }
        if ($context->driver !== 'ms-graph' || $grant->identity->provider !== 'ms-graph' || $grant->identity->clientId !== $clientId
            || $grant->identity->tenantId === null || ! $this->acceptsMicrosoftIdentity($authority, $grant->identity->issuer, $grant->identity->tenantId)
            || preg_match('/\A[\x21-\x7e]{1,255}\z/', $grant->identity->subject) !== 1
            || ! is_string($grant->metadata['object_id'] ?? null) || preg_match('/\A[\x21-\x7e]{1,255}\z/', $grant->metadata['object_id']) !== 1
            || $this->token($grant->refreshToken()) === null) {
            throw new GrantAccessDeniedException('Renewable authorization for the selected Microsoft identity and client is required.');
        }
        try {
            $response = Http::asForm()->acceptJson()->withOptions(['verify' => true, 'allow_redirects' => false])
                ->timeout(30)->post('https://login.microsoftonline.com/'.$grant->identity->tenantId.'/oauth2/v2.0/token', [
                    'client_id' => $clientId, 'client_secret' => $clientSecret,
                    'grant_type' => 'refresh_token', 'refresh_token' => $grant->refreshToken(),
                ]);
        } catch (Throwable) {
            throw new GrantRefreshException('Microsoft refresh transport failed.', true, 1);
        }
        if (! $response->successful()) {
            $error = $response->json('error');
            if ($response->status() === 429 || $response->serverError() || in_array($error, ['server_error', 'temporarily_unavailable'], true)) {
                $delay = filter_var($response->header('Retry-After'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 86400]]);
                throw new GrantRefreshException('Microsoft refresh is temporarily unavailable.', true, $delay === false ? 1 : $delay);
            }
            if (in_array($error, ['invalid_grant', 'insufficient_scope', 'invalid_scope', 'consent_required', 'interaction_required', 'login_required'], true)) {
                throw new GrantAccessDeniedException('Microsoft delegated authorization is invalid or insufficient. Reconnect authorization.');
            }
            throw new GrantRefreshException('Microsoft rejected the refresh exchange. Check the OAuth client setup.', false);
        }
        $data = $response->json();
        if (is_array($data) && array_key_exists('id_token', $data)) {
            try {
                $claims = $this->microsoftIdentityClaims($data['id_token'], $clientId, $authority);
            } catch (OAuthEnrollmentException) {
                throw new GrantAccessDeniedException('Microsoft refresh identity could not be verified. Reconnect authorization.');
            }
            if ($claims['iss'] !== $grant->identity->issuer || $claims['tid'] !== $grant->identity->tenantId
                || $claims['sub'] !== $grant->identity->subject || $claims['oid'] !== $grant->metadata['object_id']
                || (array_key_exists('nonce', $claims) && (! is_string($claims['nonce']) || $grant->nonceHash() === null
                    || ! hash_equals($grant->nonceHash(), hash('sha256', $claims['nonce']))))) {
                throw new GrantAccessDeniedException('Microsoft refresh identity does not match the selected grant. Reconnect authorization.');
            }
        }
        $accessToken = is_array($data) ? $this->token($data['access_token'] ?? null) : null;
        $refreshToken = is_array($data) && array_key_exists('refresh_token', $data) ? $this->token($data['refresh_token']) : $grant->refreshToken();
        if (! is_array($data) || $accessToken === null || $refreshToken === null
            || ! is_int($data['expires_in'] ?? null) || $data['expires_in'] <= 30
            || ! is_string($data['token_type'] ?? null) || strcasecmp($data['token_type'], 'Bearer') !== 0) {
            throw new GrantRefreshException('Microsoft returned invalid refresh credentials.', false);
        }
        $scopes = $grant->scopes;
        if (array_key_exists('scope', $data)) {
            if (! is_string($data['scope']) || preg_match('/\A[\x21\x23-\x5b\x5d-\x7e]+(?: [\x21\x23-\x5b\x5d-\x7e]+)*\z/', $data['scope']) !== 1) {
                throw new GrantAccessDeniedException('Microsoft returned invalid delegated scopes. Reconnect authorization.');
            }
            $scopes = explode(' ', $data['scope']);
        }

        return new StoredGrant($grant->identity, $accessToken, $refreshToken,
            now()->addSeconds($data['expires_in'])->toDateTimeImmutable(), $scopes,
            bin2hex(random_bytes(16)), $grant->email, $grant->metadata, $grant->nonceHash());
    }
}

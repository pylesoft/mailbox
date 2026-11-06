<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\OAuth;

use Illuminate\Support\Facades\Http;
use Pyle\Mailbox\Contracts\GrantStore;
use Pyle\Mailbox\Contracts\OAuthEnrollmentGuard;
use Pyle\Mailbox\Contracts\OAuthStateStore;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\GrantEnrollmentResult;
use Pyle\Mailbox\DTOs\GrantIdentity;
use Pyle\Mailbox\DTOs\OAuthState;
use Pyle\Mailbox\DTOs\StoredGrant;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\OAuthEnrollmentException;
use SensitiveParameter;
use Throwable;

final class MsGraphGrantOAuthService
{
    use NormalizesReturnTo;
    use VerifiesMsGraphIdentity;

    public function __construct(
        private readonly OAuthEnrollmentGuard $guard,
        private readonly OAuthStateStore $states,
        private readonly GrantStore $grants,
    ) {}

    public function authorizationUrl(GrantContext $context, string $hostContext, ?string $returnTo = null, ?string $userReference = null): string
    {
        $this->assertAllowed($context, $hostContext);
        [$clientId, , $redirectUri] = $this->client();
        $authority = $this->microsoftAuthority();
        $scopes = config('mailbox.oauth.ms_graph.scopes', []);
        if (! is_array($scopes)) {
            throw new OAuthEnrollmentException('Microsoft enrollment scopes are invalid.');
        }
        foreach ($scopes as $scope) {
            if (! is_string($scope) || preg_match('/\A[\x21\x23-\x5b\x5d-\x7e]+\z/', $scope) !== 1) {
                throw new OAuthEnrollmentException('Microsoft enrollment scopes are invalid.');
            }
        }
        if (array_diff(['openid', 'profile', 'offline_access', 'User.Read'], $scopes) !== []) {
            throw new OAuthEnrollmentException('Microsoft enrollment requires openid, profile, offline_access and User.Read scopes.');
        }
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        $this->states->put($state, new OAuthState(
            $context, $hostContext, 'ms-graph', $clientId, $redirectUri,
            now()->addSeconds(max(60, (int) config('mailbox.oauth.state_ttl_seconds', 600)))->toDateTimeImmutable(),
            $this->grants->load($context)?->revision, $nonce, $verifier,
            $this->normalizeReturnTo($returnTo) ?? $this->normalizeReturnTo(config('mailbox.oauth.default_return_url')),
            $userReference, $authority,
        ));

        return 'https://login.microsoftonline.com/'.$authority.'/oauth2/v2.0/authorize?'.http_build_query([
            'client_id' => $clientId, 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'response_mode' => 'query',
            'scope' => implode(' ', $scopes), 'state' => $state, 'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function handleCallback(GrantContext $context, string $hostContext, #[SensitiveParameter] string $state, #[SensitiveParameter] string $code): GrantEnrollmentResult
    {
        $payload = $this->states->consume($state);
        [$clientId, $clientSecret, $redirectUri] = $this->client();
        $authority = $this->microsoftAuthority();
        if ($payload === null || $payload->expiresAt->getTimestamp() <= now()->getTimestamp()
            || ! $payload->context->matches($context) || $payload->hostContext !== $hostContext || $payload->provider !== 'ms-graph'
            || $payload->clientId !== $clientId || $payload->redirectUri !== $redirectUri || $payload->authority !== $authority || trim($code) === '') {
            throw new OAuthEnrollmentException('OAuth state is invalid, expired or already consumed. Restart authorization.');
        }
        $this->assertAllowed($context, $hostContext);
        $previous = $this->grants->load($context);
        if ($previous?->revision !== $payload->expectedRevision) {
            throw new GrantAccessDeniedException('The selected grant changed after authorization started.');
        }
        try {
            $response = Http::asForm()->acceptJson()->withOptions(['verify' => true, 'allow_redirects' => false])
                ->timeout(30)->post('https://login.microsoftonline.com/'.$authority.'/oauth2/v2.0/token', [
                    'client_id' => $clientId, 'client_secret' => $clientSecret, 'grant_type' => 'authorization_code',
                    'code' => $code, 'redirect_uri' => $redirectUri, 'code_verifier' => $payload->codeVerifier(),
                ]);
        } catch (Throwable) {
            throw new OAuthEnrollmentException('Microsoft authorization exchange failed. Restart authorization.');
        }
        if (! $response->successful() || ! is_array($data = $response->json())) {
            throw new OAuthEnrollmentException('Microsoft rejected or returned an invalid authorization exchange. Restart authorization.');
        }
        $claims = $this->microsoftIdentityClaims($data['id_token'] ?? null, $clientId, $authority);
        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($payload->nonce(), $claims['nonce'])) {
            throw new OAuthEnrollmentException('Microsoft authorization nonce could not be verified.');
        }
        $identity = new GrantIdentity('ms-graph', $claims['iss'], $claims['sub'], $clientId, $claims['tid']);
        $refreshToken = $this->token($data['refresh_token'] ?? null);
        $nonceHash = hash('sha256', $payload->nonce());
        if (! array_key_exists('refresh_token', $data) && $previous !== null && $previous->identity->matches($identity)
            && ($previous->metadata['object_id'] ?? null) === $claims['oid']) {
            $refreshToken = $this->token($previous->refreshToken());
            $nonceHash = $previous->nonceHash();
        }
        if ($refreshToken === null) {
            throw new OAuthEnrollmentException('Renewable Microsoft authorization is missing. Reconnect with offline consent.');
        }
        $accessToken = $this->token($data['access_token'] ?? null);
        if ($accessToken === null || ! is_int($data['expires_in'] ?? null) || $data['expires_in'] <= 30
            || ! is_string($data['token_type'] ?? null) || strcasecmp($data['token_type'], 'Bearer') !== 0) {
            throw new OAuthEnrollmentException('Microsoft returned invalid access credentials.');
        }
        $scopes = [];
        if (array_key_exists('scope', $data)) {
            if (! is_string($data['scope']) || preg_match('/\A[\x21\x23-\x5b\x5d-\x7e]+(?: [\x21\x23-\x5b\x5d-\x7e]+)*\z/', $data['scope']) !== 1) {
                throw new OAuthEnrollmentException('Microsoft returned invalid delegated scopes.');
            }
            $scopes = explode(' ', $data['scope']);
        }
        $this->assertAllowed($context, $hostContext);
        try {
            $profile = Http::withToken($accessToken)->acceptJson()->withOptions(['verify' => true, 'allow_redirects' => false])
                ->timeout(30)->get('https://graph.microsoft.com/v1.0/me', ['$select' => 'id,mail,userPrincipalName,displayName']);
        } catch (Throwable) {
            throw new OAuthEnrollmentException('Microsoft Graph identity verification failed. Restart authorization.');
        }
        if (! $profile->successful() || $profile->json('id') !== $claims['oid']) {
            throw new OAuthEnrollmentException('Microsoft Graph identity does not match the authorization. Check User.Read consent.');
        }
        $email = $profile->json('mail') ?? $profile->json('userPrincipalName');
        $grant = new StoredGrant($identity, $accessToken, $refreshToken,
            now()->addSeconds($data['expires_in'])->toDateTimeImmutable(), $scopes, bin2hex(random_bytes(16)),
            is_string($email) ? $email : null,
            ['object_id' => $claims['oid'], 'display_name' => $profile->json('displayName')], $nonceHash,
        );
        $this->assertAllowed($context, $hostContext);
        if (! $this->grants->replace($context, $previous, $grant)) {
            throw new GrantAccessDeniedException('The selected grant changed before authorization could be saved.');
        }

        return new GrantEnrollmentResult($context, $identity, $grant->email, $grant->expiresAt,
            $this->normalizeReturnTo($payload->returnTo), $payload->userReference);
    }

    private function assertAllowed(GrantContext $context, string $hostContext): void
    {
        if ($context->driver !== 'ms-graph' || trim($hostContext) === '') {
            throw new GrantAccessDeniedException('Microsoft enrollment requires an explicitly authorized Microsoft grant.');
        }
        $this->guard->assertAllowed($context, $hostContext);
    }

    /** @return array{string, string, string} */
    private function client(): array
    {
        $clientId = config('mailbox.drivers.ms-graph.client_id');
        $clientSecret = config('mailbox.drivers.ms-graph.client_secret');
        $redirectUri = config('mailbox.oauth.ms_graph.redirect_uri');
        if (! is_string($redirectUri) || $redirectUri === '') {
            $redirectUri = app('router')->has('mailbox.oauth.ms-graph.callback') ? route('mailbox.oauth.ms-graph.callback') : '';
        }
        if (! is_string($clientId) || trim($clientId) === '' || ! is_string($clientSecret) || $clientSecret === '' || $redirectUri === '') {
            throw new OAuthEnrollmentException('Microsoft OAuth client credentials and a registered callback URI are required.');
        }

        return [$clientId, $clientSecret, $redirectUri];
    }
}

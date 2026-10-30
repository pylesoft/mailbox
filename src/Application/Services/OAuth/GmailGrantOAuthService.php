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

final class GmailGrantOAuthService
{
    use NormalizesReturnTo;
    use VerifiesGoogleIdentity;

    public function __construct(
        private readonly OAuthEnrollmentGuard $guard,
        private readonly OAuthStateStore $states,
        private readonly GrantStore $grants,
    ) {}

    public function authorizationUrl(GrantContext $context, string $hostContext, ?string $returnTo = null, ?string $userReference = null): string
    {
        $this->assertAllowed($context, $hostContext);
        [$clientId, , $redirectUri] = $this->client();
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        $scopes = $this->scopes();
        $this->states->put($state, new OAuthState(
            $context, $hostContext, 'gmail', $clientId, $redirectUri,
            now()->addSeconds(max(60, (int) config('mailbox.oauth.state_ttl_seconds', 600)))->toDateTimeImmutable(),
            $this->grants->load($context)?->revision, $nonce, $verifier,
            $this->normalizeReturnTo($returnTo) ?? $this->normalizeReturnTo(config('mailbox.oauth.default_return_url')),
            $userReference,
        ));

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $clientId, 'redirect_uri' => $redirectUri, 'response_type' => 'code',
            'scope' => implode(' ', $scopes), 'access_type' => 'offline', 'include_granted_scopes' => 'true',
            'prompt' => 'consent', 'state' => $state, 'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function handleCallback(GrantContext $context, string $hostContext, #[SensitiveParameter] string $state, #[SensitiveParameter] string $code): GrantEnrollmentResult
    {
        $payload = $this->states->consume($state);
        [$clientId, $clientSecret, $redirectUri] = $this->client();
        if ($payload === null || $payload->expiresAt->getTimestamp() <= now()->getTimestamp()
            || ! $payload->context->matches($context) || $payload->hostContext !== $hostContext || $payload->provider !== 'gmail'
            || $payload->clientId !== $clientId || $payload->redirectUri !== $redirectUri || trim($code) === '') {
            throw new OAuthEnrollmentException('OAuth state is invalid, expired or already consumed. Restart authorization.');
        }
        $this->assertAllowed($context, $hostContext);
        $previous = $this->grants->load($context);
        if ($previous?->revision !== $payload->expectedRevision) {
            throw new GrantAccessDeniedException('The selected grant changed after authorization started.');
        }

        try {
            $response = Http::asForm()->acceptJson()->withOptions(['verify' => true, 'allow_redirects' => false])
                ->timeout(30)->post('https://oauth2.googleapis.com/token', [
                    'client_id' => $clientId, 'client_secret' => $clientSecret,
                    'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri,
                    'code_verifier' => $payload->codeVerifier(),
                ]);
        } catch (Throwable) {
            throw new OAuthEnrollmentException('Google authorization exchange failed. Restart authorization.');
        }
        if (! $response->successful()) {
            throw new OAuthEnrollmentException('Google rejected the authorization exchange. Restart authorization.');
        }
        $data = $response->json();
        if (! is_array($data)) {
            throw new OAuthEnrollmentException('Google returned an invalid authorization response.');
        }
        $claims = $this->identityClaims($data['id_token'] ?? null, $clientId, $payload->nonce());
        $identity = new GrantIdentity('gmail', 'https://accounts.google.com', $claims['sub'], $clientId);
        $refreshToken = $this->token($data['refresh_token'] ?? null);
        $nonceHash = hash('sha256', $payload->nonce());
        if (! array_key_exists('refresh_token', $data) && $previous !== null && $previous->identity->matches($identity)) {
            $refreshToken = $this->token($previous->refreshToken());
            $nonceHash = $previous->nonceHash();
        }
        if ($refreshToken === null) {
            throw new OAuthEnrollmentException('Renewable Google authorization is missing. Reconnect with offline consent.');
        }
        $accessToken = $this->token($data['access_token'] ?? null);
        if ($accessToken === null || ! is_int($data['expires_in'] ?? null) || $data['expires_in'] <= 0
            || ! is_string($data['token_type'] ?? null) || strcasecmp($data['token_type'], 'Bearer') !== 0) {
            throw new OAuthEnrollmentException('Google returned invalid access credentials.');
        }
        $scopes = is_string($data['scope'] ?? null) ? array_values(array_filter(explode(' ', $data['scope']))) : [];
        $grant = new StoredGrant($identity, $accessToken, $refreshToken,
            now()->addSeconds($data['expires_in'])->toDateTimeImmutable(), $scopes, bin2hex(random_bytes(16)),
            is_string($claims['email'] ?? null) ? $claims['email'] : null,
            ['display_name' => $claims['name'] ?? null, 'hosted_domain' => $claims['hd'] ?? null],
            $nonceHash,
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
        if (! in_array($context->driver, ['gmail', 'google-workspace'], true) || trim($hostContext) === '') {
            throw new GrantAccessDeniedException('Gmail enrollment requires an explicitly authorized Google grant.');
        }
        $this->guard->assertAllowed($context, $hostContext);
    }

    /** @return array{string, string, string} */
    private function client(): array
    {
        $clientId = config('mailbox.oauth.gmail.client_id');
        $clientSecret = config('mailbox.oauth.gmail.client_secret');
        $redirectUri = config('mailbox.oauth.gmail.redirect_uri');
        if (! is_string($redirectUri) || $redirectUri === '') {
            $redirectUri = app('router')->has('mailbox.oauth.gmail.callback') ? route('mailbox.oauth.gmail.callback') : '';
        }
        if (! is_string($clientId) || trim($clientId) === '' || ! is_string($clientSecret) || $clientSecret === '' || $redirectUri === '') {
            throw new OAuthEnrollmentException('Google OAuth client credentials and a registered callback URI are required.');
        }

        return [$clientId, $clientSecret, $redirectUri];
    }

    /** @return list<string> */
    private function scopes(): array
    {
        $scopes = config('mailbox.oauth.gmail.scopes', []);
        if (! is_array($scopes) || ! in_array('openid', $scopes, true)) {
            throw new OAuthEnrollmentException('Google enrollment requires the openid scope.');
        }

        return array_values(array_filter($scopes, static fn (mixed $scope): bool => is_string($scope) && $scope !== ''));
    }

    /** @return array{sub: string, email?: mixed, name?: mixed, hd?: mixed} */
    private function identityClaims(#[SensitiveParameter] mixed $jwt, string $clientId, #[SensitiveParameter] string $nonce): array
    {
        $claims = $this->googleIdentityClaims($jwt, $clientId);
        if ($claims === null
            || ! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new OAuthEnrollmentException('Google authorization identity could not be verified.');
        }

        return $claims;
    }
}

<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\OAuth;

use Closure;
use DateTimeImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Pyle\Mailbox\Contracts\GrantAccessGuard;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\ServiceAccountAccessToken;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\GrantRefreshException;
use SensitiveParameter;
use Throwable;

final readonly class GmailServiceAccountTokenExchange
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function __construct(private GrantAccessGuard $guard) {}

    /**
     * @param  list<string>  $scopes
     * @param  Closure(): string  $runtimeAccessToken  Host-owned Google IAM runtime credential, not a mailbox token.
     */
    public function exchange(GrantContext $context, string $serviceAccountEmail, array $scopes, #[SensitiveParameter] Closure $runtimeAccessToken): ServiceAccountAccessToken
    {
        $this->guard->assertActive($context);
        if (! in_array($context->driver, ['gmail', 'google-workspace'], true)
            || strlen($context->mailbox) > 254 || filter_var($context->mailbox, FILTER_VALIDATE_EMAIL) === false
            || strlen($serviceAccountEmail) > 254 || filter_var($serviceAccountEmail, FILTER_VALIDATE_EMAIL) === false
            || ! str_ends_with(strtolower($serviceAccountEmail), '.gserviceaccount.com')) {
            throw new GrantAccessDeniedException('An explicit Gmail mailbox, service account and bounded scopes are required.');
        }
        $this->assertScopes($scopes);
        $issuedAt = now()->getTimestamp();
        $claims = ['iss' => $serviceAccountEmail, 'sub' => $context->mailbox, 'scope' => implode(' ', $scopes),
            'aud' => self::TOKEN_URL, 'iat' => $issuedAt, 'exp' => $issuedAt + 3600];
        $this->guard->assertActive($context);
        try {
            $runtimeToken = $runtimeAccessToken();
        } catch (GrantAccessDeniedException|GrantRefreshException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new GrantRefreshException('Google runtime signing credentials are unavailable.', true, 1);
        }
        $this->guard->assertActive($context);
        if (! $this->validToken($runtimeToken)) {
            throw new GrantRefreshException('Google runtime signing credentials are invalid.', false);
        }
        try {
            $response = Http::withToken($runtimeToken)->acceptJson()->connectTimeout(3)->timeout(10)
                ->withOptions(['verify' => true, 'allow_redirects' => false, 'stream' => true, 'read_timeout' => 5, 'decode_content' => false])
                ->withHeader('Accept-Encoding', 'identity')
                ->post('https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/'.rawurlencode($serviceAccountEmail).':signJwt',
                    ['payload' => json_encode($claims, JSON_THROW_ON_ERROR)]);
        } catch (Throwable) {
            throw new GrantRefreshException('Google assertion signing transport failed.', true, 1);
        }
        $signed = $this->payload($response, 'assertion signing');
        $this->guard->assertActive($context);
        $assertion = $signed['signedJwt'] ?? null;
        $this->assertClaims($assertion, $claims);
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(3)->timeout(10)
                ->withOptions(['verify' => true, 'allow_redirects' => false, 'stream' => true, 'read_timeout' => 5, 'decode_content' => false])
                ->withHeader('Accept-Encoding', 'identity')->post(self::TOKEN_URL,
                    ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion]);
        } catch (Throwable) {
            throw new GrantRefreshException('Google service-account token transport failed.', true, 1);
        }
        $data = $this->payload($response, 'service-account token exchange');
        $this->guard->assertActive($context);
        $accessToken = $data['access_token'] ?? null;
        if (! $this->validToken($accessToken) || ! is_int($data['expires_in'] ?? null) || $data['expires_in'] <= 30
            || $data['expires_in'] > 3600 || ! is_string($data['token_type'] ?? null) || strcasecmp($data['token_type'], 'Bearer') !== 0) {
            throw new GrantRefreshException('Google returned invalid service-account access credentials.', false);
        }
        if (array_key_exists('scope', $data)) {
            $returned = is_string($data['scope']) ? explode(' ', $data['scope']) : [];
            if (count($returned) !== count($scopes) || array_diff($returned, $scopes) !== [] || array_diff($scopes, $returned) !== []) {
                throw new GrantAccessDeniedException('Google returned different service-account scopes.');
            }
        }

        return new ServiceAccountAccessToken($accessToken, (new DateTimeImmutable)->setTimestamp($issuedAt + $data['expires_in']), $scopes);
    }

    private function validToken(#[SensitiveParameter] mixed $token): bool
    {
        return is_string($token) && strlen($token) >= 1 && strlen($token) <= 16_384 && preg_match('/\A[\x21-\x7e]+\z/D', $token) === 1;
    }

    /** @param array<mixed> $scopes */
    private function assertScopes(array $scopes): void
    {
        if (! array_is_list($scopes) || $scopes === [] || count($scopes) > 32) {
            throw new GrantAccessDeniedException('The selected Gmail service-account scopes are invalid.');
        }
        foreach ($scopes as $scope) {
            if (! is_string($scope) || strlen($scope) > 2048 || preg_match('/\A[\x21\x23-\x5b\x5d-\x7e]+\z/D', $scope) !== 1) {
                throw new GrantAccessDeniedException('The selected Gmail service-account scopes are invalid.');
            }
        }
        if (count(array_unique($scopes)) !== count($scopes) || strlen(implode(' ', $scopes)) > 8192) {
            throw new GrantAccessDeniedException('The selected Gmail service-account scopes are invalid.');
        }
    }

    /** @param array<string, int|string> $expected */
    private function assertClaims(#[SensitiveParameter] mixed $assertion, array $expected): void
    {
        try {
            if (! is_string($assertion) || strlen($assertion) > 16_384
                || preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/D', $assertion) !== 1) {
                throw new \UnexpectedValueException;
            }
            $parts = explode('.', $assertion);
            $header = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true, 4, JSON_THROW_ON_ERROR);
            $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true, 4, JSON_THROW_ON_ERROR);
            if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256' || isset($header['crit']) || isset($header['b64']) || ! is_array($claims)) {
                throw new \UnexpectedValueException;
            }
            ksort($claims);
            ksort($expected);
            // This compares the requested assertion; parsed claims are not mailbox identity proof.
            if ($claims !== $expected) {
                throw new \UnexpectedValueException;
            }
        } catch (Throwable) {
            throw new GrantRefreshException('Google signed assertion does not match the selected Gmail request.', false);
        }
    }

    /** @return array<string, mixed> */
    private function payload(#[SensitiveParameter] Response $response, string $operation): array
    {
        $stream = $response->toPsrResponse()->getBody();
        try {
            if ($response->status() !== 200) {
                $retryable = $response->status() === 429 || $response->serverError();
                $delay = filter_var($response->header('Retry-After'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 86400]]);
                throw new GrantRefreshException('Google '.$operation.' was rejected.', $retryable, $retryable ? ($delay === false ? 1 : $delay) : null);
            }
            $body = '';
            $deadline = hrtime(true) + 10_000_000_000;
            while (true) {
                if (hrtime(true) >= $deadline) {
                    throw new GrantRefreshException('Google '.$operation.' response exceeded its time limit.', true, 1);
                }
                $chunk = $stream->read(min(8192, 65_536 - strlen($body)) + 1);
                if ($chunk === '') {
                    if ($stream->eof()) {
                        break;
                    }
                    throw new GrantRefreshException('Google '.$operation.' response stopped streaming.', true, 1);
                }
                $body .= $chunk;
                if (strlen($body) > 65_536) {
                    throw new GrantRefreshException('Google '.$operation.' response exceeded its byte limit.', false);
                }
            }
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($data) || array_is_list($data)) {
                throw new GrantRefreshException('Google '.$operation.' response is invalid.', false);
            }

            return $data;
        } catch (GrantRefreshException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new GrantRefreshException('Google '.$operation.' response is invalid.', false);
        } finally {
            $stream->close();
        }
    }
}

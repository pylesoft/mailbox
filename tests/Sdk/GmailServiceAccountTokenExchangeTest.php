<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\GrantRefreshException;
use Pyle\Mailbox\Services\OAuth\GmailServiceAccountTokenExchange;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

function gmailServiceAccountContext(): GrantContext
{
    return new GrantContext('connection', 'grant', 'generation-1', 'gmail', 'shared@example.com');
}

function gmailServiceAccountGuard(): TestGrantTokenProvider
{
    return new TestGrantTokenProvider(['grant' => ['connection' => 'connection', 'generation' => 'generation-1',
        'mailboxes' => ['shared@example.com'], 'token' => 'unused']]);
}

function gmailServiceAccountSignedJwt(string $payload): string
{
    $encode = static fn (string $part): string => rtrim(strtr(base64_encode($part), '+/', '-_'), '=');

    return $encode('{"alg":"RS256","kid":"google-managed-key"}').'.'.$encode($payload).'.'.$encode('synthetic-signature');
}

it('signs and exchanges the exact selected Gmail assertion without package tables or public token exposure', function (): void {
    $this->freezeTime();
    Http::preventStrayRequests();
    $claims = null;
    Http::fake([
        'https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/mailbox%40sandbox.iam.gserviceaccount.com:signJwt' => function (Request $request, array $options) use (&$claims) {
            $claims = json_decode($request['payload'], true, flags: JSON_THROW_ON_ERROR);
            expect($request->header('Authorization'))->toBe(['Bearer private-runtime-token']);
            expect($request->data())->toHaveKeys(['payload'])->toHaveCount(1);
            expect($options)->toMatchArray(['verify' => true, 'allow_redirects' => false, 'stream' => true,
                'read_timeout' => 5, 'decode_content' => false, 'connect_timeout' => 3, 'timeout' => 10]);

            return Http::response(['signedJwt' => gmailServiceAccountSignedJwt($request['payload'])]);
        },
        'https://oauth2.googleapis.com/token' => function (Request $request, array $options) {
            expect($request->header('Authorization'))->toBe([]);
            expect($request['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:jwt-bearer');
            expect($request['assertion'])->toStartWith('eyJ');
            expect($options)->toMatchArray(['verify' => true, 'allow_redirects' => false, 'stream' => true,
                'read_timeout' => 5, 'decode_content' => false, 'connect_timeout' => 3, 'timeout' => 10]);

            return Http::response(['access_token' => 'private-mailbox-token', 'token_type' => 'Bearer', 'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/gmail.readonly']);
        },
    ]);
    $exchange = new GmailServiceAccountTokenExchange(gmailServiceAccountGuard());
    $scopes = ['https://www.googleapis.com/auth/gmail.readonly', 'https://www.googleapis.com/auth/gmail.send'];

    $token = $exchange->exchange(gmailServiceAccountContext(), 'mailbox@sandbox.iam.gserviceaccount.com', $scopes, fn (): string => 'private-runtime-token');

    expect($claims)->toBe(['iss' => 'mailbox@sandbox.iam.gserviceaccount.com', 'sub' => 'shared@example.com', 'scope' => implode(' ', $scopes),
        'aud' => 'https://oauth2.googleapis.com/token', 'iat' => now()->getTimestamp(), 'exp' => now()->addHour()->getTimestamp()]);
    expect($token->accessToken())->toBe('private-mailbox-token');
    expect($token->expiresAt->getTimestamp())->toBe(now()->addHour()->getTimestamp());
    expect($token->scopes)->toBe($scopes);
    expect(json_encode($token, JSON_THROW_ON_ERROR))->not->toContain('private-mailbox-token');
    expect(print_r($token, true))->not->toContain('private-mailbox-token');
    expect(config('database.default'))->toBe('sdk-forbidden');
    Http::assertSentCount(2);
});

it('rejects a foreign grant before asking for any runtime credential', function (): void {
    Http::preventStrayRequests();
    $requested = false;
    $exchange = new GmailServiceAccountTokenExchange(gmailServiceAccountGuard());
    $context = new GrantContext('other-connection', 'grant', 'generation-1', 'gmail', 'shared@example.com');

    expect(fn () => $exchange->exchange($context, 'mailbox@sandbox.iam.gserviceaccount.com', ['gmail.scope'], function () use (&$requested): string {
        $requested = true;

        return 'private-runtime-token';
    }))->toThrow(GrantAccessDeniedException::class);

    expect($requested)->toBeFalse();
    Http::assertNothingSent();
});

it('rejects malformed service account inputs before acquiring a runtime credential', function (string $driver, string $mailbox, string $serviceAccount, array $scopes): void {
    Http::preventStrayRequests();
    $context = new GrantContext('connection', 'grant', 'generation-1', $driver, $mailbox);
    $guard = new TestGrantTokenProvider(['grant' => ['connection' => 'connection', 'generation' => 'generation-1', 'mailboxes' => [$mailbox], 'token' => 'unused']]);
    $requested = false;
    $exchange = new GmailServiceAccountTokenExchange($guard);

    expect(fn () => $exchange->exchange($context, $serviceAccount, $scopes, function () use (&$requested): string {
        $requested = true;

        return 'private-runtime-token';
    }))->toThrow(GrantAccessDeniedException::class);

    expect($requested)->toBeFalse();
    Http::assertNothingSent();
})->with([
    'wrong driver' => ['ms-graph', 'shared@example.com', 'mailbox@sandbox.iam.gserviceaccount.com', ['gmail.scope']],
    'invalid mailbox' => ['gmail', 'not-a-mailbox', 'mailbox@sandbox.iam.gserviceaccount.com', ['gmail.scope']],
    'wrong service-account domain' => ['gmail', 'shared@example.com', 'mailbox@example.com', ['gmail.scope']],
    'empty scopes' => ['gmail', 'shared@example.com', 'mailbox@sandbox.iam.gserviceaccount.com', []],
    'scope separator injection' => ['gmail', 'shared@example.com', 'mailbox@sandbox.iam.gserviceaccount.com', ["gmail.scope\nother.scope"]],
    'duplicate scopes' => ['gmail', 'shared@example.com', 'mailbox@sandbox.iam.gserviceaccount.com', ['gmail.scope', 'gmail.scope']],
    'non-list scopes' => ['gmail', 'shared@example.com', 'mailbox@sandbox.iam.gserviceaccount.com', ['scope' => 'gmail.scope']],
    'too many scopes' => ['gmail', 'shared@example.com', 'mailbox@sandbox.iam.gserviceaccount.com', array_map(fn (int $i): string => 'scope'.$i, range(1, 33))],
]);

it('stops before signing when runtime credential acquisition changes the authority', function (): void {
    Http::preventStrayRequests();
    $guard = gmailServiceAccountGuard();
    $exchange = new GmailServiceAccountTokenExchange($guard);

    expect(fn () => $exchange->exchange(gmailServiceAccountContext(), 'mailbox@sandbox.iam.gserviceaccount.com', ['gmail.scope'], function () use ($guard): string {
        $guard->grants['grant']['generation'] = 'generation-2';

        return 'private-runtime-token';
    }))->toThrow(GrantAccessDeniedException::class);

    Http::assertNothingSent();
});

it('rejects invalid runtime credentials and wraps acquisition failures without provider calls', function (string $failure): void {
    Http::preventStrayRequests();
    $exchange = new GmailServiceAccountTokenExchange(gmailServiceAccountGuard());

    try {
        $exchange->exchange(gmailServiceAccountContext(), 'mailbox@sandbox.iam.gserviceaccount.com', ['gmail.scope'], function () use ($failure): string {
            if ($failure === 'unavailable') {
                throw new RuntimeException('private-runtime-credential-location');
            }

            return $failure === 'oversized' ? str_repeat('x', 16_385) : "private-token\r\nheader";
        });
        test()->fail('Invalid runtime credentials must not be used.');
    } catch (GrantRefreshException $exception) {
        expect($exception->retryable)->toBe($failure === 'unavailable');
        expect($exception->getPrevious())->toBeNull();
        expect((string) $exception)->not->toContain('private-runtime-credential-location')->not->toContain('private-token');
    }

    Http::assertNothingSent();
})->with(['unavailable', 'oversized', 'header injection']);

it('does not exchange a signed assertion when the selected authority changed during signing', function (): void {
    Http::preventStrayRequests();
    $guard = gmailServiceAccountGuard();
    Http::fake(['https://iamcredentials.googleapis.com/*' => function (Request $request) use ($guard) {
        $guard->grants['grant']['generation'] = 'generation-2';

        return Http::response(['signedJwt' => gmailServiceAccountSignedJwt($request['payload'])]);
    }]);
    $exchange = new GmailServiceAccountTokenExchange($guard);

    expect(fn () => $exchange->exchange(gmailServiceAccountContext(), 'mailbox@sandbox.iam.gserviceaccount.com', ['gmail.scope'], fn (): string => 'runtime-token'))
        ->toThrow(GrantAccessDeniedException::class);

    Http::assertSentCount(1);
});

it('withholds exchanged credentials when authority changes during the token exchange', function (): void {
    Http::preventStrayRequests();
    $guard = gmailServiceAccountGuard();
    Http::fake([
        'https://iamcredentials.googleapis.com/*' => fn (Request $request) => Http::response(['signedJwt' => gmailServiceAccountSignedJwt($request['payload'])]),
        'https://oauth2.googleapis.com/token' => function () use ($guard) {
            $guard->grants['grant']['generation'] = 'generation-2';

            return Http::response(['access_token' => 'private-mailbox-token', 'token_type' => 'Bearer', 'expires_in' => 3600]);
        },
    ]);
    $exchange = new GmailServiceAccountTokenExchange($guard);

    expect(fn () => $exchange->exchange(gmailServiceAccountContext(), 'mailbox@sandbox.iam.gserviceaccount.com', ['gmail.scope'], fn (): string => 'runtime-token'))
        ->toThrow(GrantAccessDeniedException::class);

    Http::assertSentCount(2);
});

it('rejects a signed assertion for a different mailbox or scope without sending it to OAuth', function (array $changed): void {
    Http::preventStrayRequests();
    Http::fake(['https://iamcredentials.googleapis.com/*' => function (Request $request) use ($changed) {
        $claims = array_replace(json_decode($request['payload'], true, flags: JSON_THROW_ON_ERROR), $changed);

        return Http::response(['signedJwt' => gmailServiceAccountSignedJwt(json_encode($claims, JSON_THROW_ON_ERROR))]);
    }]);
    $exchange = new GmailServiceAccountTokenExchange(gmailServiceAccountGuard());

    expect(fn () => $exchange->exchange(gmailServiceAccountContext(), 'mailbox@sandbox.iam.gserviceaccount.com', ['gmail.scope'], fn (): string => 'runtime-token'))
        ->toThrow(GrantRefreshException::class, 'does not match');

    Http::assertSentCount(1);
})->with([
    'mailbox' => [['sub' => 'other@example.com']],
    'issuer' => [['iss' => 'other@sandbox.iam.gserviceaccount.com']],
    'audience' => [['aud' => 'https://foreign.example/token']],
    'scope' => [['scope' => 'broader.scope']],
    'expiry type' => [['exp' => '1234567890']],
    'extra claim' => [['extra' => 'unexpected']],
]);

it('classifies provider errors without retrying or exposing provider response secrets', function (string $phase, int $status, bool $retryable): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://iamcredentials.googleapis.com/*' => fn (Request $request) => $phase === 'signing'
            ? Http::response(['error' => 'private-provider-error'], $status, ['Retry-After' => '7'])
            : Http::response(['signedJwt' => gmailServiceAccountSignedJwt($request['payload'])]),
        'https://oauth2.googleapis.com/token' => Http::response(['error' => 'private-provider-error'], $status, ['Retry-After' => '7']),
    ]);
    $exchange = new GmailServiceAccountTokenExchange(gmailServiceAccountGuard());

    try {
        $exchange->exchange(gmailServiceAccountContext(), 'mailbox@sandbox.iam.gserviceaccount.com', ['gmail.scope'], fn (): string => 'private-runtime-token');
        test()->fail('A rejected token phase must not succeed.');
    } catch (GrantRefreshException $exception) {
        expect($exception->retryable)->toBe($retryable);
        expect($exception->retryAfterSeconds)->toBe($retryable ? 7 : null);
        expect($exception->getPrevious())->toBeNull();
        expect((string) $exception)->not->toContain('private-provider-error')->not->toContain('private-runtime-token');
    }

    Http::assertSentCount($phase === 'signing' ? 1 : 2);
})->with([
    'IAM denied' => ['signing', 403, false],
    'IAM rate limited' => ['signing', 429, true],
    'IAM redirect' => ['signing', 302, false],
    'OAuth invalid grant' => ['exchange', 400, false],
    'OAuth unavailable' => ['exchange', 503, true],
]);

it('rejects bounded malformed successful provider responses', function (string $phase, mixed $payload): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://iamcredentials.googleapis.com/*' => fn (Request $request) => $phase === 'signing'
            ? Http::response($payload)
            : Http::response(['signedJwt' => gmailServiceAccountSignedJwt($request['payload'])]),
        'https://oauth2.googleapis.com/token' => Http::response($payload),
    ]);
    $exchange = new GmailServiceAccountTokenExchange(gmailServiceAccountGuard());

    expect(fn () => $exchange->exchange(gmailServiceAccountContext(), 'mailbox@sandbox.iam.gserviceaccount.com', ['gmail.scope'], fn (): string => 'runtime-token'))
        ->toThrow(GrantRefreshException::class);

    Http::assertSentCount($phase === 'signing' ? 1 : 2);
})->with([
    'oversized signing response' => ['signing', str_repeat('x', 65_537)],
    'malformed signing JSON' => ['signing', 'not-json'],
    'missing signed assertion' => ['signing', ['keyId' => 'key']],
    'malformed signed assertion' => ['signing', ['signedJwt' => 'not-a-jwt']],
    'oversized token response' => ['exchange', str_repeat('x', 65_537)],
    'empty token' => ['exchange', ['access_token' => '', 'token_type' => 'Bearer', 'expires_in' => 3600]],
    'token header injection' => ['exchange', ['access_token' => "token\r\nother", 'token_type' => 'Bearer', 'expires_in' => 3600]],
    'expired token' => ['exchange', ['access_token' => 'token', 'token_type' => 'Bearer', 'expires_in' => 30]],
    'invalid token type' => ['exchange', ['access_token' => 'token', 'token_type' => 'Other', 'expires_in' => 3600]],
    'excessive lifetime' => ['exchange', ['access_token' => 'token', 'token_type' => 'Bearer', 'expires_in' => 3601]],
]);

it('wraps signing and exchange transport failures without retaining private exception details', function (string $phase): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://iamcredentials.googleapis.com/*' => function (Request $request) use ($phase) {
            if ($phase === 'signing') {
                throw new RuntimeException('private-transport-error');
            }

            return Http::response(['signedJwt' => gmailServiceAccountSignedJwt($request['payload'])]);
        },
        'https://oauth2.googleapis.com/token' => function () {
            throw new RuntimeException('private-transport-error');
        },
    ]);
    $exchange = new GmailServiceAccountTokenExchange(gmailServiceAccountGuard());

    try {
        $exchange->exchange(gmailServiceAccountContext(), 'mailbox@sandbox.iam.gserviceaccount.com', ['gmail.scope'], fn (): string => 'private-runtime-token');
        test()->fail('Failed transport must not return credentials.');
    } catch (GrantRefreshException $exception) {
        expect($exception->retryable)->toBeTrue();
        expect($exception->retryAfterSeconds)->toBe(1);
        expect($exception->getPrevious())->toBeNull();
        expect((string) $exception)->not->toContain('private-transport-error')->not->toContain('private-runtime-token');
    }
})->with(['signing', 'exchange']);

it('rejects different returned scopes without exposing access tokens through exception trace arguments', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://iamcredentials.googleapis.com/*' => fn (Request $request) => Http::response(['signedJwt' => gmailServiceAccountSignedJwt($request['payload'])]),
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'private-mailbox-token', 'token_type' => 'Bearer', 'expires_in' => 3600,
            'scope' => 'other.scope']),
    ]);
    $exchange = new GmailServiceAccountTokenExchange(gmailServiceAccountGuard());
    $previous = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');

    try {
        try {
            $exchange->exchange(gmailServiceAccountContext(), 'mailbox@sandbox.iam.gserviceaccount.com', ['gmail.scope'], fn (): string => 'private-runtime-token');
            test()->fail('Different returned scopes must not be accepted.');
        } catch (GrantAccessDeniedException $exception) {
            $trace = array_values(array_filter($exception->getTrace(), fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'Pyle\\Mailbox\\')));
            expect(json_encode($trace, JSON_PARTIAL_OUTPUT_ON_ERROR))->not->toContain('private-mailbox-token')->not->toContain('private-runtime-token');
        }
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }

    Http::assertSentCount(2);
});

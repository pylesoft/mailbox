<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\GrantIdentity;
use Pyle\Mailbox\DTOs\StoredGrant;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\OAuthEnrollmentException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Services\OAuth\GmailGrantOAuthService;
use Pyle\Mailbox\Tests\Support\TestEnrollmentHost;

beforeEach(function (): void {
    config()->set('mailbox.oauth.gmail', [
        'client_id' => 'selected-client',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'https://host.example/oauth/google',
        'scopes' => ['openid', 'email', 'https://www.googleapis.com/auth/gmail.readonly'],
    ]);
    Http::preventStrayRequests();
});

it('enrolls a selected Gmail grant and reads mail without package tables', function (): void {
    $host = new TestEnrollmentHost;
    $context = new GrantContext('connection-a', 'grant-a', '1', 'gmail', 'same@gmail.com');
    $host->bind($context, 'team-a:user-a');
    $oauth = new GmailGrantOAuthService($host, $host, $host);
    parse_str((string) parse_url($oauth->authorizationUrl($context, 'team-a:user-a', '/connections', 'opaque-ref'), PHP_URL_QUERY), $query);
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response(gmailEnrollmentTokens($query['nonce']))]);

    $result = $oauth->handleCallback($context, 'team-a:user-a', $query['state'], 'one-use-code');
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"id":"message-1"}')]));
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack]);
    $message = Mailbox::forGrant($context, $host, $http)->message('message-1')->get();

    expect($message->id)->toBe('message-1')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer enrolled-access')
        ->and((string) $history[0]['request']->getUri())->toBe('https://gmail.googleapis.com/gmail/v1/users/same%40gmail.com/messages/message-1?format=full')
        ->and($result->identity->subject)->toBe('google-subject')
        ->and($result->returnTo)->toBe('/connections')
        ->and($result->userReference)->toBe('opaque-ref')
        ->and($host->load($context)?->refreshToken())->toBe('renewable-token')
        ->and($query)->toMatchArray(['access_type' => 'offline', 'response_type' => 'code', 'code_challenge_method' => 'S256'])
        ->and(config('database.default'))->toBe('sdk-forbidden');
    Http::assertSent(fn ($request): bool => $request['code'] === 'one-use-code'
        && $request['client_id'] === 'selected-client'
        && $request['client_secret'] === 'client-secret'
        && $request['redirect_uri'] === 'https://host.example/oauth/google'
        && rtrim(strtr(base64_encode(hash('sha256', $request['code_verifier'], true)), '+/', '-_'), '=') === $query['code_challenge']);
});

it('does not treat an explicitly invalid refresh token as an omitted rotation', function (): void {
    [$host, $context, $oauth, $query] = gmailEnrollmentFlow();
    $responses = Http::fakeSequence()->push(gmailEnrollmentTokens($query['nonce']));
    $oauth->handleCallback($context, 'host-a', $query['state'], 'first-code');
    $previous = $host->load($context);
    parse_str((string) parse_url($oauth->authorizationUrl($context, 'host-a'), PHP_URL_QUERY), $query);
    $responses->push(array_replace(gmailEnrollmentTokens($query['nonce']), ['refresh_token' => '']));

    expect(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'second-code'))->toThrow(OAuthEnrollmentException::class)
        ->and($host->load($context))->toBe($previous);
});

it('rejects a malformed ID token even in a successful token response', function (): void {
    [$host, $context, $oauth, $query] = gmailEnrollmentFlow();
    $data = gmailEnrollmentTokens($query['nonce']);
    $parts = explode('.', $data['id_token']);
    $data['id_token'] = rtrim(strtr(base64_encode('{"alg":"none"}'), '+/', '-_'), '=').'.'.$parts[1].'.';
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response($data)]);

    expect(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'code'))->toThrow(OAuthEnrollmentException::class)
        ->and($host->load($context))->toBeNull();
});

it('supports personal and Workspace identities without using email to select a grant', function (string $driver, ?string $domain): void {
    [$host, $context, $oauth, $query] = gmailEnrollmentFlow(driver: $driver);
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response(gmailEnrollmentTokens($query['nonce'], ['hd' => $domain]))]);
    $result = $oauth->handleCallback($context, 'host-a', $query['state'], 'code');

    expect($result->identity)->toEqual(new GrantIdentity('gmail', 'https://accounts.google.com', 'google-subject', 'selected-client'))
        ->and($host->load($context)?->metadata['hosted_domain'])->toBe($domain);
})->with([['gmail', null], ['google-workspace', 'workspace.example']]);

it('keeps two connections to the same external subject independent', function (): void {
    [$host, $first, $oauth, $query] = gmailEnrollmentFlow();
    $second = new GrantContext('connection-b', 'grant-b', '1', 'gmail', 'same@gmail.com');
    $host->bind($second, 'host-b');
    parse_str((string) parse_url($oauth->authorizationUrl($second, 'host-b'), PHP_URL_QUERY), $other);
    Http::fakeSequence()->push(gmailEnrollmentTokens($query['nonce']))
        ->push(array_replace(gmailEnrollmentTokens($other['nonce']), ['access_token' => 'second-access', 'refresh_token' => 'second-refresh']));
    $oauth->handleCallback($first, 'host-a', $query['state'], 'first-code');
    $oauth->handleCallback($second, 'host-b', $other['state'], 'second-code');

    expect($host->load($first)?->accessToken())->toBe('enrolled-access')
        ->and($host->load($second)?->accessToken())->toBe('second-access')
        ->and($host->load($first)?->refreshToken())->toBe('renewable-token')
        ->and($host->load($second)?->refreshToken())->toBe('second-refresh');
});

it('requires host approval before initiation and callback exchange', function (): void {
    [$host, $context, $oauth, $query] = gmailEnrollmentFlow();
    $host->denied = true;

    expect(fn () => $oauth->authorizationUrl($context, 'host-a'))->toThrow(GrantAccessDeniedException::class)
        ->and(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'code'))->toThrow(GrantAccessDeniedException::class)
        ->and($host->grants)->toBe([]);
    Http::assertNothingSent();
});

it('rejects expired missing and wrong-context state before exchange', function (string $mismatch): void {
    [$host, $context, $oauth, $query] = gmailEnrollmentFlow();
    $callbackContext = $context;
    $callbackHost = 'host-a';
    if ($mismatch === 'expired') {
        $this->travel(601)->seconds();
    } elseif ($mismatch === 'missing') {
        $query['state'] = 'not-issued';
    } elseif ($mismatch === 'host') {
        $callbackHost = 'foreign-host';
    } else {
        $values = ['connectionId' => $context->connectionId, 'grantId' => $context->grantId, 'lifecycleGeneration' => '1', 'driver' => 'gmail', 'mailbox' => $context->mailbox];
        $values[$mismatch] = 'different';
        $callbackContext = new GrantContext(...$values);
    }

    expect(fn () => $oauth->handleCallback($callbackContext, $callbackHost, $query['state'], 'code'))->toThrow(OAuthEnrollmentException::class)
        ->and($host->grants)->toBe([]);
    Http::assertNothingSent();
})->with(['expired', 'missing', 'host', 'connectionId', 'grantId', 'lifecycleGeneration', 'driver', 'mailbox']);

it('consumes callback state once even when the provider rejects the exchange', function (): void {
    [$host, $context, $oauth, $query] = gmailEnrollmentFlow();
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    expect(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'code'))->toThrow(OAuthEnrollmentException::class)
        ->and(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'code'))->toThrow(OAuthEnrollmentException::class, 'already consumed');
    Http::assertSentCount(1);
});

it('rejects replay after a completed enrollment without another exchange', function (): void {
    [$host, $context, $oauth, $query] = gmailEnrollmentFlow();
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response(gmailEnrollmentTokens($query['nonce']))]);
    $oauth->handleCallback($context, 'host-a', $query['state'], 'code');
    $saved = $host->load($context);

    expect(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'code'))->toThrow(OAuthEnrollmentException::class)
        ->and($host->load($context))->toBe($saved);
    Http::assertSentCount(1);
});

it('rejects unverified Google identity claims before persisting', function (array $claims): void {
    [$host, $context, $oauth, $query] = gmailEnrollmentFlow();
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response(gmailEnrollmentTokens($query['nonce'], $claims))]);

    expect(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'code'))->toThrow(OAuthEnrollmentException::class, 'identity')
        ->and($host->load($context))->toBeNull();
})->with([
    'issuer' => [['iss' => 'https://evil.example']], 'client' => [['aud' => 'other-client']],
    'authorized party' => [['azp' => 'other-client']], 'subject' => [['sub' => '']],
    'subject type' => [['sub' => 123]], 'nonce' => [['nonce' => 'another-initiation']],
    'expiry' => [['exp' => 1]], 'expiry type' => [['exp' => '4102444800']],
    'future issue time' => [['iat' => 4102444800]],
]);

it('preserves omission only for the exact selected identity and replaces rotations', function (string $change): void {
    [$host, $context, $oauth, $query] = gmailEnrollmentFlow();
    $responses = Http::fakeSequence()->push(gmailEnrollmentTokens($query['nonce']));
    $oauth->handleCallback($context, 'host-a', $query['state'], 'first-code');
    $previous = $host->load($context);
    if ($change === 'client') {
        config()->set('mailbox.oauth.gmail.client_id', 'new-client');
    } elseif ($change === 'tenant') {
        $host->grants[$context->grantId] = new StoredGrant(new GrantIdentity('gmail', 'https://accounts.google.com', 'google-subject', 'selected-client', 'foreign-tenant'),
            'enrolled-access', 'renewable-token', $previous->expiresAt, $previous->scopes, 'tenant-revision');
        $previous = $host->load($context);
    }
    parse_str((string) parse_url($oauth->authorizationUrl($context, 'host-a'), PHP_URL_QUERY), $query);
    $data = gmailEnrollmentTokens($query['nonce'], [
        'sub' => $change === 'subject' ? 'different-subject' : 'google-subject',
        'aud' => $change === 'client' ? 'new-client' : 'selected-client',
        'iss' => $change === 'same' ? 'accounts.google.com' : 'https://accounts.google.com',
    ]);
    if ($change === 'rotation') {
        $data['refresh_token'] = 'rotated-refresh';
    } else {
        unset($data['refresh_token']);
    }
    $responses->push($data);

    if (in_array($change, ['subject', 'client', 'tenant'], true)) {
        expect(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'second-code'))->toThrow(OAuthEnrollmentException::class, 'Renewable')
            ->and($host->load($context))->toBe($previous);
    } else {
        $oauth->handleCallback($context, 'host-a', $query['state'], 'second-code');
        expect($host->load($context)?->refreshToken())->toBe($change === 'rotation' ? 'rotated-refresh' : 'renewable-token')
            ->and($host->load($context)?->nonceHash())->toBe($change === 'rotation' ? hash('sha256', $query['nonce']) : $previous->nonceHash())
            ->and($host->load($context)?->revision)->not->toBe($previous->revision)
            ->and($context->lifecycleGeneration)->toBe('1');
    }
})->with(['same', 'rotation', 'subject', 'client', 'tenant']);

it('requires renewable consent for a new grant even when another connection has the same subject', function (): void {
    [$host, $context, $oauth, $query] = gmailEnrollmentFlow();
    $responses = Http::fakeSequence()->push(gmailEnrollmentTokens($query['nonce']));
    $oauth->handleCallback($context, 'host-a', $query['state'], 'first-code');
    $other = new GrantContext('other-connection', 'other-grant', '1', 'gmail', 'same@gmail.com');
    $host->bind($other, 'host-a');
    parse_str((string) parse_url($oauth->authorizationUrl($other, 'host-a'), PHP_URL_QUERY), $query);
    $data = gmailEnrollmentTokens($query['nonce']);
    unset($data['refresh_token']);
    $responses->push($data);

    expect(fn () => $oauth->handleCallback($other, 'host-a', $query['state'], 'second-code'))->toThrow(OAuthEnrollmentException::class, 'Renewable')
        ->and($host->load($other))->toBeNull()
        ->and($host->load($context)?->refreshToken())->toBe('renewable-token');
});

it('rejects lifecycle and conditional-write changes during the exchange', function (string $race): void {
    [$host, $context, $oauth, $query] = gmailEnrollmentFlow();
    Http::fake(['https://oauth2.googleapis.com/token' => function () use ($host, $context, $query, $race) {
        if ($race === 'disconnect') {
            unset($host->bindings[$context->grantId]);
        } elseif ($race === 'reconnect') {
            $host->bindings[$context->grantId]['generation'] = '2';
        } elseif ($race === 'ownership') {
            $host->denied = true;
        } else {
            $host->rejectWrite = true;
        }

        return Http::response(gmailEnrollmentTokens($query['nonce']));
    }]);

    expect(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'code'))->toThrow(GrantAccessDeniedException::class)
        ->and($host->grants)->toBe([]);
    Http::assertSentCount(1);
})->with(['disconnect', 'reconnect', 'ownership', 'write-conflict']);

it('does not overwrite a competing callback that commits during the exchange', function (): void {
    [$host, $context, $oauth, $query] = gmailEnrollmentFlow();
    $competing = new StoredGrant(new GrantIdentity('gmail', 'https://accounts.google.com', 'other-subject', 'selected-client'),
        'competing-access', 'competing-refresh', now()->addHour()->toDateTimeImmutable(), ['openid'], 'competing-revision');
    Http::fake(['https://oauth2.googleapis.com/token' => function () use ($host, $context, $query, $competing) {
        $host->grants[$context->grantId] = $competing;

        return Http::response(gmailEnrollmentTokens($query['nonce']));
    }]);

    expect(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'code'))->toThrow(GrantAccessDeniedException::class)
        ->and($host->load($context))->toBe($competing);
});

it('rejects a changed grant revision before the exchange', function (): void {
    [$host, $context, $oauth, $query] = gmailEnrollmentFlow();
    $host->grants[$context->grantId] = new StoredGrant(new GrantIdentity('gmail', 'https://accounts.google.com', 'subject', 'selected-client'),
        'new-access', 'new-refresh', now()->addHour()->toDateTimeImmutable(), [], 'new-revision');

    expect(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'code'))->toThrow(GrantAccessDeniedException::class);
    Http::assertNothingSent();
});

it('keeps provider responses and transport secrets out of public errors', function (bool $transportFailure): void {
    [$host, $context, $oauth, $query] = gmailEnrollmentFlow();
    Http::fake(['https://oauth2.googleapis.com/token' => $transportFailure ? Http::failedConnection('provider-secret') : Http::response(['error_description' => 'provider-secret'], 400)]);
    try {
        $oauth->handleCallback($context, 'host-a', $query['state'], 'secret-code');
        $this->fail('Expected an enrollment failure.');
    } catch (OAuthEnrollmentException $exception) {
        expect((string) $exception)->not->toContain('provider-secret', 'client-secret', 'secret-code')
            ->and($exception->getPrevious())->toBeNull()
            ->and($host->load($context))->toBeNull();
    }
})->with([false, true]);

it('pins the authenticated token endpoint to verified TLS without redirects', function (): void {
    [$host, $context, $oauth, $query] = gmailEnrollmentFlow();
    $options = [];
    Http::globalOptions(['verify' => false, 'allow_redirects' => true]);
    Http::fake(['https://oauth2.googleapis.com/token' => function ($request, $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response('', 302, ['Location' => 'https://evil.example/token']);
    }]);

    expect(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'code'))->toThrow(OAuthEnrollmentException::class)
        ->and($options['verify'])->toBeTrue()
        ->and($options['allow_redirects'])->toBeFalse();
    Http::assertSentCount(1);
});

it('returns safe destinations and exposes no credentials in results or debug output', function (string $returnTo, string $expected): void {
    [$host, $context, $oauth] = gmailEnrollmentFlow();
    parse_str((string) parse_url($oauth->authorizationUrl($context, 'host-a', $returnTo, 'reference'), PHP_URL_QUERY), $query);
    $state = $host->states[$query['state']];
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response(gmailEnrollmentTokens($query['nonce']))]);
    $result = $oauth->handleCallback($context, 'host-a', $query['state'], 'code');
    $grant = $host->load($context);

    expect($result->returnTo)->toBe($expected)
        ->and(json_encode($result))->not->toContain('enrolled-access', 'renewable-token', 'id_token', 'client-secret')
        ->and(json_encode($grant))->not->toContain('enrolled-access', 'renewable-token')
        ->and(print_r($grant, true))->not->toContain('enrolled-access', 'renewable-token')
        ->and(print_r($state, true))->not->toContain($state->codeVerifier(), $state->nonce());
})->with([['/connections', '/connections'], ['//evil.example', '/'], ['/\\evil.example', '/'], ['https://evil.example/path', '/'], ["/\nevil", '/']]);

it('compares numeric-looking context and subject identifiers exactly', function (string $mismatch): void {
    [$host, $context, $oauth] = gmailEnrollmentFlow();
    $numeric = new GrantContext($context->connectionId, $context->grantId, '01', 'gmail', $context->mailbox);
    $host->bind($numeric, 'host-a');
    parse_str((string) parse_url($oauth->authorizationUrl($numeric, 'host-a'), PHP_URL_QUERY), $query);
    if ($mismatch === 'generation') {
        $host->bind($context, 'host-a');
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(gmailEnrollmentTokens($query['nonce']))]);

        expect(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'code'))->toThrow(OAuthEnrollmentException::class);
        Http::assertNothingSent();
    } else {
        $host->grants[$numeric->grantId] = new StoredGrant(new GrantIdentity('gmail', 'https://accounts.google.com', '01', 'selected-client'),
            'previous-access', 'previous-refresh', now()->addHour()->toDateTimeImmutable(), [], 'previous-revision');
        parse_str((string) parse_url($oauth->authorizationUrl($numeric, 'host-a'), PHP_URL_QUERY), $query);
        $data = gmailEnrollmentTokens($query['nonce'], ['sub' => '1']);
        unset($data['refresh_token']);
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response($data)]);

        expect(fn () => $oauth->handleCallback($numeric, 'host-a', $query['state'], 'code'))->toThrow(OAuthEnrollmentException::class, 'Renewable')
            ->and($host->load($numeric)?->refreshToken())->toBe('previous-refresh');
    }
})->with(['generation', 'subject']);

it('requires a valid stored renewable token when Google omits it', function (): void {
    [$host, $context, $oauth] = gmailEnrollmentFlow();
    $host->grants[$context->grantId] = new StoredGrant(new GrantIdentity('gmail', 'https://accounts.google.com', 'google-subject', 'selected-client'),
        'old-access', '', now()->addHour()->toDateTimeImmutable(), [], 'old-revision');
    parse_str((string) parse_url($oauth->authorizationUrl($context, 'host-a'), PHP_URL_QUERY), $query);
    $data = gmailEnrollmentTokens($query['nonce']);
    unset($data['refresh_token']);
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response($data)]);

    expect(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'code'))->toThrow(OAuthEnrollmentException::class, 'Renewable')
        ->and($host->load($context)?->accessToken())->toBe('old-access');
});

/** @return array{TestEnrollmentHost, GrantContext, GmailGrantOAuthService, array<string, string>} */
function gmailEnrollmentFlow(string $grantId = 'grant-a', string $driver = 'gmail'): array
{
    $host = new TestEnrollmentHost;
    $context = new GrantContext('connection-'.$grantId, $grantId, '1', $driver, 'same@gmail.com');
    $host->bind($context, 'host-a');
    $oauth = new GmailGrantOAuthService($host, $host, $host);
    parse_str((string) parse_url($oauth->authorizationUrl($context, 'host-a'), PHP_URL_QUERY), $query);

    return [$host, $context, $oauth, $query];
}

/** @param array<string, mixed> $claims
 * @return array<string, mixed>
 */
function gmailEnrollmentTokens(string $nonce, array $claims = []): array
{
    $encode = static fn (array $part): string => rtrim(strtr(base64_encode(json_encode($part, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $payload = array_replace([
        'iss' => 'https://accounts.google.com', 'sub' => 'google-subject', 'aud' => 'selected-client',
        'exp' => now()->addHour()->getTimestamp(), 'iat' => now()->getTimestamp(), 'nonce' => $nonce,
        'email' => 'same@gmail.com', 'email_verified' => true,
    ], $claims);

    return ['access_token' => 'enrolled-access', 'refresh_token' => 'renewable-token', 'expires_in' => 3600,
        'scope' => 'openid email https://www.googleapis.com/auth/gmail.readonly', 'token_type' => 'Bearer',
        'id_token' => $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode($payload).'.provider-signature'];
}

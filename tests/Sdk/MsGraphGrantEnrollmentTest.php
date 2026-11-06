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
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\OAuthEnrollmentException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Services\OAuth\MsGraphGrantOAuthService;
use Pyle\Mailbox\Tests\Support\TestEnrollmentHost;

beforeEach(function (): void {
    config()->set('mailbox.drivers.ms-graph.tenant_id', 'common');
    config()->set('mailbox.drivers.ms-graph.client_id', 'selected-client');
    config()->set('mailbox.drivers.ms-graph.client_secret', 'client-secret');
    config()->set('mailbox.oauth.ms_graph', [
        'redirect_uri' => 'https://host.example/oauth/microsoft',
        'scopes' => ['openid', 'profile', 'offline_access', 'User.Read', 'Mail.Read'],
    ]);
    Http::preventStrayRequests();
});

it('enrolls a selected Microsoft account then reads Graph without package tables', function (string $tenant): void {
    $host = new TestEnrollmentHost;
    $context = new GrantContext('connection-a', 'grant-a', 'generation-1', 'ms-graph', 'person@example.com');
    $host->bind($context, 'host-a');
    $oauth = new MsGraphGrantOAuthService($host, $host, $host);
    parse_str((string) parse_url($oauth->authorizationUrl($context, 'host-a', '/connections', 'opaque-ref'), PHP_URL_QUERY), $query);
    Http::fake([
        'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response(msGraphEnrollmentTokens($query['nonce'], $tenant)),
        'https://graph.microsoft.com/v1.0/me*' => Http::response(['id' => '11111111-2222-3333-4444-555555555555', 'mail' => 'person@example.com']),
    ]);

    $result = $oauth->handleCallback($context, 'host-a', $query['state'], 'one-use-code');
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"id":"message-1"}')]));
    $stack->push(Middleware::history($history));
    $message = Mailbox::forGrant($context, $host, new Client(['handler' => $stack]))->message('message-1')->get();

    expect($message->id)->toBe('message-1')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer opaque-graph-access')
        ->and((string) $history[0]['request']->getUri())->toBe('https://graph.microsoft.com/v1.0/users/person%40example.com/messages/message-1')
        ->and($result->identity)->toEqual(new GrantIdentity('ms-graph', 'https://login.microsoftonline.com/'.$tenant.'/v2.0', 'pairwise-subject', 'selected-client', $tenant))
        ->and($result->returnTo)->toBe('/connections')
        ->and($result->userReference)->toBe('opaque-ref')
        ->and($host->load($context)?->metadata['object_id'])->toBe('11111111-2222-3333-4444-555555555555')
        ->and($host->load($context)?->refreshToken())->toBe('renewable-token')
        ->and($query)->toMatchArray(['response_type' => 'code', 'response_mode' => 'query', 'code_challenge_method' => 'S256'])
        ->and(config('database.default'))->toBe('sdk-forbidden');
    Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request['code'] === 'one-use-code'
        && $request['client_id'] === 'selected-client' && $request['client_secret'] === 'client-secret'
        && $request['redirect_uri'] === 'https://host.example/oauth/microsoft'
        && rtrim(strtr(base64_encode(hash('sha256', $request['code_verifier'], true)), '+/', '-_'), '=') === $query['code_challenge']);
})->with(['organizational' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'personal' => '9188040d-6c67-4c5b-b112-36a304b66dad']);

it('binds callback state to the original host grant registration and authority before exchange', function (string $mismatch): void {
    [$host, $context, $oauth, $query] = msGraphEnrollmentFlow();
    $callbackHost = 'host-a';
    if ($mismatch === 'expired') {
        $this->travel(601)->seconds();
    } elseif ($mismatch === 'missing') {
        $query['state'] = 'not-issued';
    } elseif ($mismatch === 'host') {
        $callbackHost = 'foreign-host';
    } elseif (in_array($mismatch, ['client_id', 'tenant_id'], true)) {
        config()->set('mailbox.drivers.ms-graph.'.$mismatch, $mismatch === 'client_id' ? 'other-client' : 'organizations');
    } elseif ($mismatch === 'redirect_uri') {
        config()->set('mailbox.oauth.ms_graph.redirect_uri', 'https://host.example/changed');
    } else {
        $values = ['connectionId' => $context->connectionId, 'grantId' => $context->grantId, 'lifecycleGeneration' => 'generation-1', 'driver' => 'ms-graph', 'mailbox' => $context->mailbox];
        $values[$mismatch] = 'different';
        $context = new GrantContext(...$values);
    }

    expect(fn () => $oauth->handleCallback($context, $callbackHost, $query['state'], 'code'))->toThrow(OAuthEnrollmentException::class)
        ->and($host->grants)->toBe([]);
    Http::assertNothingSent();
})->with(['expired', 'missing', 'host', 'client_id', 'tenant_id', 'redirect_uri', 'connectionId', 'grantId', 'lifecycleGeneration', 'driver', 'mailbox']);

it('requires host approval before Microsoft initiation and exchange', function (): void {
    [$host, $context, $oauth, $query] = msGraphEnrollmentFlow();
    $host->denied = true;

    expect(fn () => $oauth->authorizationUrl($context, 'host-a'))->toThrow(GrantAccessDeniedException::class)
        ->and(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'code'))->toThrow(GrantAccessDeniedException::class);
    Http::assertNothingSent();
});

it('reports invalid Microsoft enrollment setup before storing state or sending credentials', function (string $setting): void {
    $host = new TestEnrollmentHost;
    $context = new GrantContext('connection-a', 'grant-a', 'generation-1', 'ms-graph', 'person@example.com');
    $host->bind($context, 'host-a');
    if ($setting === 'authority') {
        config()->set('mailbox.drivers.ms-graph.tenant_id', 'https://evil.example');
    } elseif ($setting === 'client') {
        config()->set('mailbox.drivers.ms-graph.client_id', '');
    } elseif ($setting === 'required scopes') {
        config()->set('mailbox.oauth.ms_graph.scopes', ['openid', 'profile', 'offline_access', 'Mail.Read']);
    } else {
        config()->set('mailbox.oauth.ms_graph.scopes', ['openid', 'profile', 'offline_access', 'User.Read', ['invalid']]);
    }
    $oauth = new MsGraphGrantOAuthService($host, $host, $host);

    expect(fn () => $oauth->authorizationUrl($context, 'host-a'))->toThrow(OAuthEnrollmentException::class)
        ->and($host->states)->toBe([]);
    Http::assertNothingSent();
})->with(['authority', 'client', 'required scopes', 'malformed scopes']);

it('consumes state once after either an accepted or rejected Microsoft exchange', function (bool $accepted): void {
    [$host, $context, $oauth, $query] = msGraphEnrollmentFlow();
    Http::fake([
        'https://login.microsoftonline.com/*' => $accepted ? Http::response(msGraphEnrollmentTokens($query['nonce'])) : Http::response(['error' => 'invalid_grant', 'secret' => 'private-response'], 400),
        'https://graph.microsoft.com/*' => Http::response(['id' => '11111111-2222-3333-4444-555555555555']),
    ]);
    if ($accepted) {
        $oauth->handleCallback($context, 'host-a', $query['state'], 'code');
    } else {
        try {
            $oauth->handleCallback($context, 'host-a', $query['state'], 'code');
            test()->fail('The rejected exchange must fail.');
        } catch (OAuthEnrollmentException $exception) {
            expect($exception->getMessage())->not->toContain('private-response')
                ->and($exception->getPrevious())->toBeNull();
        }
    }
    expect(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'code'))->toThrow(OAuthEnrollmentException::class, 'already consumed');
    Http::assertSentCount($accepted ? 2 : 1);
})->with([true, false]);

it('rejects unverified Microsoft identity claims without persisting or querying Graph', function (array $changed): void {
    [$host, $context, $oauth, $query] = msGraphEnrollmentFlow();
    Http::fake([
        'https://login.microsoftonline.com/*' => Http::response(msGraphEnrollmentTokens($query['nonce'], claims: $changed)),
        'https://graph.microsoft.com/*' => Http::response(['id' => '11111111-2222-3333-4444-555555555555']),
    ]);

    expect(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'code'))->toThrow(OAuthEnrollmentException::class)
        ->and($host->load($context))->toBeNull();
    Http::assertSentCount(1);
})->with([
    'literal common issuer' => [['iss' => 'https://login.microsoftonline.com/common/v2.0']],
    'wrong issuer tenant' => [['iss' => 'https://login.microsoftonline.com/11111111-2222-3333-4444-555555555555/v2.0']],
    'invalid tenant' => [['tid' => 'organizations']], 'wrong client' => [['aud' => 'other-client']],
    'wrong authorized party' => [['azp' => 'other-client']], 'invalid authorized party' => [['azp' => null]], 'missing subject' => [['sub' => '']],
    'missing object identity' => [['oid' => null]], 'wrong nonce' => [['nonce' => 'other-nonce']],
    'expired' => [['exp' => 0]], 'future issue' => [['iat' => PHP_INT_MAX]],
    'future not before' => [['nbf' => PHP_INT_MAX]], 'wrong version' => [['ver' => '1.0']],
]);

it('enforces the selected Microsoft account authority', function (string $authority, string $tenant, bool $accepted): void {
    config()->set('mailbox.drivers.ms-graph.tenant_id', $authority);
    [$host, $context, $oauth, $query] = msGraphEnrollmentFlow();
    Http::fake([
        'https://login.microsoftonline.com/*' => Http::response(msGraphEnrollmentTokens($query['nonce'], $tenant)),
        'https://graph.microsoft.com/*' => Http::response(['id' => '11111111-2222-3333-4444-555555555555']),
    ]);
    if ($accepted) {
        expect($oauth->handleCallback($context, 'host-a', $query['state'], 'code')->identity->tenantId)->toBe($tenant);
    } else {
        expect(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'code'))->toThrow(OAuthEnrollmentException::class)
            ->and($host->load($context))->toBeNull();
        Http::assertSentCount(1);
    }
})->with([
    ['organizations', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', true],
    ['organizations', '9188040d-6c67-4c5b-b112-36a304b66dad', false],
    ['consumers', '9188040d-6c67-4c5b-b112-36a304b66dad', true],
    ['consumers', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', false],
    ['aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', true],
    ['aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', '11111111-2222-3333-4444-555555555555', false],
]);

it('requires Graph identity to match oid rather than the pairwise OIDC subject', function (string $id, int $status): void {
    [$host, $context, $oauth, $query] = msGraphEnrollmentFlow();
    Http::fake([
        'https://login.microsoftonline.com/*' => Http::response(msGraphEnrollmentTokens($query['nonce'])),
        'https://graph.microsoft.com/*' => Http::response(['id' => $id, 'error' => 'private-profile'], $status),
    ]);

    expect(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'code'))->toThrow(OAuthEnrollmentException::class, 'Graph identity')
        ->and($host->load($context))->toBeNull();
})->with([['pairwise-subject', 200], ['different-object', 200], ['11111111-2222-3333-4444-555555555555', 403]]);

it('preserves omitted Microsoft refresh secrets only for the same verified selected identity', function (string $change): void {
    [$host, $context, $oauth, $query] = msGraphEnrollmentFlow();
    $first = msGraphEnrollmentTokens($query['nonce']);
    $responses = Http::sequence()->push($first);
    Http::fake([
        'https://login.microsoftonline.com/*' => $responses,
        'https://graph.microsoft.com/*' => Http::response(['id' => '11111111-2222-3333-4444-555555555555']),
    ]);
    $oauth->handleCallback($context, 'host-a', $query['state'], 'code');
    $previous = $host->load($context);
    if ($change === 'client') {
        config()->set('mailbox.drivers.ms-graph.client_id', 'new-client');
    }
    parse_str((string) parse_url($oauth->authorizationUrl($context, 'host-a'), PHP_URL_QUERY), $query);
    $tenant = $change === 'tenant' ? '11111111-2222-3333-4444-555555555555' : 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $claims = match ($change) {
        'subject' => ['sub' => 'other-subject'], 'object' => ['oid' => 'other-object'], 'client' => ['aud' => 'new-client'], default => [],
    };
    $replacement = msGraphEnrollmentTokens($query['nonce'], $tenant, $claims);
    unset($replacement['refresh_token']);
    if ($change === 'rotation') {
        $replacement['refresh_token'] = 'rotated-refresh';
    } elseif ($change === 'invalid') {
        $replacement['refresh_token'] = '';
    }
    $responses->push($replacement);
    if (in_array($change, ['same', 'rotation'], true)) {
        $oauth->handleCallback($context, 'host-a', $query['state'], 'new-code');
        expect($host->load($context)?->refreshToken())->toBe($change === 'same' ? 'renewable-token' : 'rotated-refresh')
            ->and($host->load($context)?->nonceHash())->toBe($change === 'same' ? $previous?->nonceHash() : hash('sha256', $query['nonce']));
    } else {
        expect(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'new-code'))->toThrow(OAuthEnrollmentException::class, 'Renewable')
            ->and($host->load($context))->toBe($previous);
    }
})->with(['same', 'rotation', 'invalid', 'subject', 'tenant', 'client', 'object']);

it('rejects reconnect or a conditional write conflict during Microsoft enrollment', function (string $phase): void {
    [$host, $context, $oauth, $query] = msGraphEnrollmentFlow();
    $invalidate = function () use ($host, $context, $phase): void {
        if ($phase === 'conflict') {
            $host->rejectWrite = true;
        } else {
            $host->bind(new GrantContext($context->connectionId, $context->grantId, 'generation-2', 'ms-graph', $context->mailbox), 'host-a');
        }
    };
    Http::fake([
        'https://login.microsoftonline.com/*' => function () use ($query, $phase, $invalidate) {
            if ($phase === 'exchange') {
                $invalidate();
            }

            return Http::response(msGraphEnrollmentTokens($query['nonce']));
        },
        'https://graph.microsoft.com/*' => function () use ($phase, $invalidate) {
            if ($phase !== 'exchange') {
                $invalidate();
            }

            return Http::response(['id' => '11111111-2222-3333-4444-555555555555']);
        },
    ]);

    expect(fn () => $oauth->handleCallback($context, 'host-a', $query['state'], 'code'))->toThrow(GrantAccessDeniedException::class)
        ->and($host->grants)->toBe([]);
    Http::assertSentCount($phase === 'exchange' ? 1 : 2);
})->with(['exchange', 'profile', 'conflict']);

it('keeps Microsoft callback return destinations on the trusted host policy', function (string $returnTo, string $expected): void {
    config()->set('mailbox.oauth.allowed_return_hosts', ['trusted.example']);
    [$host, $context, $oauth] = msGraphEnrollmentFlow();
    parse_str((string) parse_url($oauth->authorizationUrl($context, 'host-a', $returnTo, 'opaque-ref'), PHP_URL_QUERY), $query);
    Http::fake([
        'https://login.microsoftonline.com/*' => Http::response(msGraphEnrollmentTokens($query['nonce'])),
        'https://graph.microsoft.com/*' => Http::response(['id' => '11111111-2222-3333-4444-555555555555']),
    ]);
    $result = $oauth->handleCallback($context, 'host-a', $query['state'], 'code');

    expect($result->returnTo)->toBe($expected)->and($result->userReference)->toBe('opaque-ref');
})->with([
    ['/settings', '/settings'], ['https://trusted.example/settings', 'https://trusted.example/settings'],
    ['//evil.example', '/'], ['/\\evil.example', '/'], ["/\nevil.example", '/'], ['https://evil.example', '/'],
]);

/** @return array{TestEnrollmentHost, GrantContext, MsGraphGrantOAuthService, array<string, string>} */
function msGraphEnrollmentFlow(): array
{
    $host = new TestEnrollmentHost;
    $context = new GrantContext('connection-a', 'grant-a', 'generation-1', 'ms-graph', 'person@example.com');
    $host->bind($context, 'host-a');
    $oauth = new MsGraphGrantOAuthService($host, $host, $host);
    parse_str((string) parse_url($oauth->authorizationUrl($context, 'host-a'), PHP_URL_QUERY), $query);

    return [$host, $context, $oauth, $query];
}

/**
 * @param  array<string, mixed>  $claims
 * @return array<string, mixed>
 */
function msGraphEnrollmentTokens(string $nonce, string $tenant = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', array $claims = []): array
{
    $encode = static fn (array $part): string => rtrim(strtr(base64_encode(json_encode($part, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $payload = array_replace([
        'iss' => 'https://login.microsoftonline.com/'.$tenant.'/v2.0', 'tid' => $tenant,
        'sub' => 'pairwise-subject', 'oid' => '11111111-2222-3333-4444-555555555555', 'aud' => 'selected-client',
        'exp' => now()->addHour()->getTimestamp(), 'iat' => now()->getTimestamp(), 'nbf' => now()->subMinute()->getTimestamp(),
        'nonce' => $nonce, 'ver' => '2.0',
    ], $claims);

    return ['access_token' => 'opaque-graph-access', 'refresh_token' => 'renewable-token', 'expires_in' => 3600,
        'scope' => 'openid profile User.Read Mail.Read', 'token_type' => 'Bearer',
        'id_token' => $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode($payload).'.provider-signature'];
}

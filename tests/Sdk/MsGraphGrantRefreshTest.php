<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pyle\Mailbox\Drivers\MsGraph\TokenManager;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\GrantIdentity;
use Pyle\Mailbox\DTOs\StoredGrant;
use Pyle\Mailbox\Exceptions\AuthenticationException;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\GrantRefreshException;
use Pyle\Mailbox\Exceptions\MailboxAccessDeniedException;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Services\OAuth\MsGraphGrantRefresher;
use Pyle\Mailbox\Support\RefreshingGrantTokenProvider;
use Pyle\Mailbox\Tests\Support\TestEnrollmentHost;
use Pyle\Mailbox\Tests\Support\TestRefreshLocks;

beforeEach(function (): void {
    config()->set('mailbox.drivers.ms-graph.tenant_id', 'common');
    config()->set('mailbox.drivers.ms-graph.client_id', 'selected-client');
    config()->set('mailbox.drivers.ms-graph.client_secret', 'client-secret');
    Http::preventStrayRequests();
});

it('refreshes a selected Microsoft grant then reads its authorized target', function (string $tenant, string $mailbox): void {
    [$host, $context, $tokens] = msGraphRefreshFlow($tenant, $mailbox);
    Http::fake(['https://login.microsoftonline.com/'.$tenant.'/oauth2/v2.0/token' => Http::response([
        'access_token' => 'fresh-opaque-access', 'refresh_token' => 'rotated-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer',
    ])]);
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"id":"message-1"}')]));
    $stack->push(Middleware::history($history));
    $message = Mailbox::forGrant($context, $tokens, new Client(['handler' => $stack]))->message('message-1')->get();

    expect($message->id)->toBe('message-1')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer fresh-opaque-access')
        ->and((string) $history[0]['request']->getUri())->toBe('https://graph.microsoft.com/v1.0/users/'.rawurlencode($mailbox).'/messages/message-1')
        ->and($host->load($context)?->refreshToken())->toBe('rotated-refresh')
        ->and($host->load($context)?->identity->subject)->toBe('pairwise-subject')
        ->and($host->load($context)?->revision)->not->toBe('revision-1')
        ->and($host->load($context)?->nonceHash())->toBe(hash('sha256', 'original-nonce'))
        ->and($host->bindings['grant-a']['generation'])->toBe('generation-1')
        ->and(config('database.default'))->toBe('sdk-forbidden');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'old-refresh' && $request['client_id'] === 'selected-client'
        && $request['client_secret'] === 'client-secret');
})->with([
    'organizational' => ['aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'person@example.com'],
    'personal' => ['9188040d-6c67-4c5b-b112-36a304b66dad', 'person@hotmail.com'],
    'shared' => ['aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'shared@example.com'],
]);

it('uses matching Microsoft refresh identity and nonce evidence while preserving an omitted secret', function (bool $nonce): void {
    [$host, $context, $tokens] = msGraphRefreshFlow();
    Http::fake(['https://login.microsoftonline.com/*' => Http::response(msGraphRefreshTokens($nonce ? ['nonce' => 'original-nonce'] : []))]);
    $history = [];
    $message = Mailbox::forGrant($context, $tokens, msGraphRefreshHttp([new Response(200, [], '{"id":"message-1"}')], $history))->message('message-1')->get();

    expect($message->id)->toBe('message-1')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer fresh-opaque-access')
        ->and($host->load($context)?->refreshToken())->toBe('old-refresh')
        ->and($host->load($context)?->scopes)->toBe(['User.Read', 'Mail.Read'])
        ->and($host->load($context)?->nonceHash())->toBe(hash('sha256', 'original-nonce'));
})->with([true, false]);

it('rejects changed Microsoft refresh identity without replacing credentials or reading Graph', function (array $changed): void {
    [$host, $context, $tokens] = msGraphRefreshFlow();
    $previous = $host->load($context);
    Http::fake(['https://login.microsoftonline.com/*' => Http::response(msGraphRefreshTokens($changed))]);
    $history = [];
    $resource = Mailbox::forGrant($context, $tokens, msGraphRefreshHttp([], $history));

    expect(fn () => $resource->message('message-1')->get())->toThrow(GrantAccessDeniedException::class)
        ->and($host->load($context))->toBe($previous)->and($history)->toBe([]);
    Http::assertSentCount(1);
})->with([
    'issuer' => [['iss' => 'https://login.microsoftonline.com/common/v2.0']],
    'tenant' => [['tid' => '11111111-2222-3333-4444-555555555555', 'iss' => 'https://login.microsoftonline.com/11111111-2222-3333-4444-555555555555/v2.0']],
    'client' => [['aud' => 'other-client']], 'authorized party' => [['azp' => null]], 'subject' => [['sub' => 'other-subject']],
    'object identity' => [['oid' => 'other-object']], 'nonce' => [['nonce' => 'other-nonce']],
    'invalid nonce' => [['nonce' => null]], 'expired identity' => [['exp' => 0]],
    'future identity' => [['nbf' => PHP_INT_MAX]],
]);

it('rejects a returned Microsoft refresh nonce when the original fingerprint is unavailable', function (): void {
    [$host, $context, $tokens] = msGraphRefreshFlow();
    $previous = $host->load($context);
    $host->grants['grant-a'] = new StoredGrant($previous->identity, $previous->accessToken(), $previous->refreshToken(),
        $previous->expiresAt, $previous->scopes, $previous->revision, $previous->email, $previous->metadata);
    Http::fake(['https://login.microsoftonline.com/*' => Http::response(msGraphRefreshTokens(['nonce' => 'original-nonce']))]);
    $history = [];

    expect(fn () => Mailbox::forGrant($context, $tokens, msGraphRefreshHttp([], $history))->message('message-1')->get())->toThrow(GrantAccessDeniedException::class)
        ->and($host->load($context)?->revision)->toBe('revision-1')->and($history)->toBe([]);
});

it('refuses Microsoft refresh when the configured registration no longer authorizes that identity', function (string $setting): void {
    [$host, $context, $tokens] = msGraphRefreshFlow();
    config()->set('mailbox.drivers.ms-graph.'.$setting, $setting === 'tenant_id' ? 'consumers' : 'other-client');
    $history = [];

    expect(fn () => Mailbox::forGrant($context, $tokens, msGraphRefreshHttp([], $history))->message('message-1')->get())->toThrow(GrantAccessDeniedException::class)
        ->and($host->load($context)?->revision)->toBe('revision-1')->and($history)->toBe([]);
    Http::assertNothingSent();
})->with(['client_id', 'tenant_id']);

it('distinguishes transient Microsoft refresh failures client setup and reconnect requirements', function (int $status, string $error, ?bool $retryable): void {
    [$host, $context, $tokens] = msGraphRefreshFlow();
    $previous = $host->load($context);
    Http::fake(['https://login.microsoftonline.com/*' => Http::response(['error' => $error, 'error_description' => 'client-secret old-refresh private-response'], $status, ['Retry-After' => '7'])]);
    $history = [];
    try {
        Mailbox::forGrant($context, $tokens, msGraphRefreshHttp([], $history))->message('message-1')->get();
        test()->fail('A rejected refresh must stop the read.');
    } catch (MailboxException $exception) {
        expect($exception)->toBeInstanceOf($retryable === null ? GrantAccessDeniedException::class : GrantRefreshException::class)
            ->and((string) $exception)->not->toContain('client-secret', 'old-refresh', 'private-response')
            ->and($exception->getPrevious())->toBeNull();
        if ($exception instanceof GrantRefreshException) {
            expect($exception->retryable)->toBe($retryable)->and($exception->retryAfterSeconds)->toBe($retryable ? 7 : null);
        }
    }
    expect($host->load($context))->toBe($previous)->and($history)->toBe([]);
    Http::assertSentCount(1);
})->with([
    [429, 'throttled', true], [503, 'server_error', true], [400, 'temporarily_unavailable', true],
    [400, 'invalid_client', false], [400, 'unauthorized_client', false],
    [400, 'invalid_grant', null], [400, 'interaction_required', null], [400, 'consent_required', null], [400, 'invalid_scope', null],
]);

it('redacts a Microsoft refresh transport failure and permits a later host retry', function (): void {
    [$host, $context, $tokens] = msGraphRefreshFlow();
    $first = true;
    Http::fake(['https://login.microsoftonline.com/*' => function () use (&$first) {
        if ($first) {
            $first = false;
            throw new ConnectionException('client-secret old-refresh');
        }

        return Http::response(['access_token' => 'retry-access', 'expires_in' => 3600, 'token_type' => 'Bearer']);
    }]);
    $history = [];
    $resource = Mailbox::forGrant($context, $tokens, msGraphRefreshHttp([new Response(200, [], '{"id":"message-1"}')], $history));
    try {
        $resource->message('message-1')->get();
        test()->fail('Transport failure must be visible.');
    } catch (GrantRefreshException $exception) {
        expect($exception->retryable)->toBeTrue()->and($exception->getPrevious())->toBeNull()
            ->and((string) $exception)->not->toContain('client-secret', 'old-refresh');
    }
    expect($resource->message('message-1')->get()->id)->toBe('message-1')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer retry-access');
});

it('rejects malformed Microsoft refresh credentials without overwriting the previous grant', function (array $changed): void {
    [$host, $context, $tokens] = msGraphRefreshFlow();
    $previous = $host->load($context);
    Http::fake(['https://login.microsoftonline.com/*' => Http::response(array_replace([
        'access_token' => 'fresh-access', 'expires_in' => 3600, 'token_type' => 'Bearer',
    ], $changed))]);
    $history = [];

    expect(fn () => Mailbox::forGrant($context, $tokens, msGraphRefreshHttp([], $history))->message('message-1')->get())->toThrow(MailboxException::class)
        ->and($host->load($context))->toBe($previous)->and($history)->toBe([]);
})->with([
    [['access_token' => null]], [['access_token' => "bad\r\ntoken"]], [['expires_in' => 0]],
    [['token_type' => 'mac']], [['refresh_token' => '']], [['scope' => []]], [['id_token' => 'malformed']],
]);

it('rejects reconnect and conditional refresh conflicts before a Graph read', function (bool $reconnect): void {
    [$host, $context, $tokens] = msGraphRefreshFlow();
    $previous = $host->load($context);
    Http::fake(['https://login.microsoftonline.com/*' => function () use ($host, $context, $reconnect) {
        if ($reconnect) {
            $host->bind(new GrantContext($context->connectionId, $context->grantId, 'generation-2', 'ms-graph', $context->mailbox), 'host-a');
        } else {
            $host->rejectWrite = true;
        }

        return Http::response(['access_token' => 'stale-access', 'refresh_token' => 'stale-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer']);
    }]);
    $history = [];

    expect(fn () => Mailbox::forGrant($context, $tokens, msGraphRefreshHttp([], $history))->message('message-1')->get())
        ->toThrow($reconnect ? GrantAccessDeniedException::class : GrantRefreshException::class)
        ->and($host->grants['grant-a'])->toBe($previous)->and($history)->toBe([]);
    Http::assertSentCount(1);
})->with([true, false]);

it('coordinates Microsoft refresh once across own and shared mailbox targets', function (): void {
    [$host, $context] = msGraphRefreshFlow();
    $locks = new TestRefreshLocks;
    $tokens = new RefreshingGrantTokenProvider($host, $host, $locks, new MsGraphGrantRefresher);
    $shared = new GrantContext($context->connectionId, $context->grantId, $context->lifecycleGeneration, 'ms-graph', 'shared@example.com');
    $held = $locks->acquire($context);
    $history = [];
    $http = msGraphRefreshHttp([new Response(200, [], '{"id":"own"}'), new Response(200, [], '{"id":"shared"}')], $history);
    expect(fn () => Mailbox::forGrant($shared, $tokens, $http)->message('shared')->get())->toThrow(GrantRefreshException::class)
        ->and($history)->toBe([]);
    Http::assertNothingSent();
    $held->release();
    Http::fake(['https://login.microsoftonline.com/*' => Http::response(['access_token' => 'one-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer'])]);

    expect(Mailbox::forGrant($context, $tokens, $http)->message('own')->get()->id)->toBe('own')
        ->and(Mailbox::forGrant($shared, $tokens, $http)->message('shared')->get()->id)->toBe('shared')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer one-refresh')
        ->and($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer one-refresh');
    Http::assertSentCount(1);
});

it('keeps independently selected Microsoft grants for one account isolated', function (): void {
    [$host, $context, $tokens] = msGraphRefreshFlow();
    $second = new GrantContext('connection-b', 'grant-b', 'generation-1', 'ms-graph', $context->mailbox);
    $host->bind($second, 'host-b');
    $previous = $host->load($context);
    $host->grants['grant-b'] = new StoredGrant($previous->identity, 'other-expired', 'other-refresh', $previous->expiresAt,
        $previous->scopes, 'other-revision', $previous->email, $previous->metadata, $previous->nonceHash());
    Http::fake(['https://login.microsoftonline.com/*' => fn ($request) => Http::response([
        'access_token' => $request['refresh_token'] === 'old-refresh' ? 'first-access' : 'second-access',
        'expires_in' => 3600, 'token_type' => 'Bearer',
    ])]);
    $history = [];
    $http = msGraphRefreshHttp([new Response(200, [], '{"id":"first"}'), new Response(200, [], '{"id":"second"}'), new Response(200, [], '{"id":"first-again"}')], $history);
    Mailbox::forGrant($context, $tokens, $http)->message('first')->get();
    Mailbox::forGrant($second, $tokens, $http)->message('second')->get();
    Mailbox::forGrant($context, $tokens, $http)->message('first-again')->get();

    expect(array_map(fn (array $entry): string => $entry['request']->getHeaderLine('Authorization'), $history))
        ->toBe(['Bearer first-access', 'Bearer second-access', 'Bearer first-access'])
        ->and($host->load($context)?->refreshToken())->toBe('old-refresh')
        ->and($host->load($second)?->refreshToken())->toBe('other-refresh');
    Http::assertSentCount(2);
});

it('does not elevate rejected delegated Graph reads to available configured application authority', function (int $status): void {
    [$host, $context, $tokens] = msGraphRefreshFlow(mailbox: 'shared@example.com');
    Cache::put('mailbox_token:common:selected-client', ['access_token' => 'configured-app-access', 'expires_at' => now()->addHour()->getTimestamp()], 600);
    Http::fake(['https://login.microsoftonline.com/*' => Http::response(['access_token' => 'delegated-access', 'expires_in' => 3600, 'token_type' => 'Bearer'])]);
    $history = [];
    $http = msGraphRefreshHttp([new Response($status, [], '{"error":{"code":"ErrorAccessDenied"}}'), new Response(200, [], '{"id":"forbidden-fallback"}')], $history);

    expect(fn () => Mailbox::forGrant($context, $tokens, $http)->message('message-1')->get())
        ->toThrow($status === 401 ? AuthenticationException::class : MailboxAccessDeniedException::class)
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer delegated-access')
        ->and((new TokenManager(config('mailbox.drivers.ms-graph')))->getToken())->toBe('configured-app-access');
    Http::assertSentCount(1);
})->with([401, 403]);

/** @param list<Response> $responses */
function msGraphRefreshHttp(array $responses, array &$history): Client
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new Client(['handler' => $stack]);
}

/** @param array<string, mixed> $claims
 * @return array<string, mixed>
 */
function msGraphRefreshTokens(array $claims = []): array
{
    $encode = static fn (array $part): string => rtrim(strtr(base64_encode(json_encode($part, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $payload = array_replace([
        'iss' => 'https://login.microsoftonline.com/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/v2.0',
        'tid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'sub' => 'pairwise-subject',
        'oid' => '11111111-2222-3333-4444-555555555555', 'aud' => 'selected-client',
        'exp' => now()->addHour()->getTimestamp(), 'iat' => now()->getTimestamp(), 'nbf' => now()->subMinute()->getTimestamp(), 'ver' => '2.0',
    ], $claims);

    return ['access_token' => 'fresh-opaque-access', 'expires_in' => 3600, 'token_type' => 'Bearer', 'scope' => 'User.Read Mail.Read',
        'id_token' => $encode(['alg' => 'RS256']).'.'.$encode($payload).'.provider-signature'];
}

/** @return array{TestEnrollmentHost, GrantContext, RefreshingGrantTokenProvider} */
function msGraphRefreshFlow(string $tenant = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', string $mailbox = 'person@example.com'): array
{
    $host = new TestEnrollmentHost;
    $context = new GrantContext('connection-a', 'grant-a', 'generation-1', 'ms-graph', $mailbox);
    $host->bind($context, 'host-a');
    $host->grants['grant-a'] = new StoredGrant(
        new GrantIdentity('ms-graph', 'https://login.microsoftonline.com/'.$tenant.'/v2.0', 'pairwise-subject', 'selected-client', $tenant),
        'expired-access', 'old-refresh', now()->subMinute()->toDateTimeImmutable(), ['User.Read', 'Mail.Read.Shared'], 'revision-1',
        'person@example.com', ['object_id' => '11111111-2222-3333-4444-555555555555'], hash('sha256', 'original-nonce'),
    );

    return [$host, $context, new RefreshingGrantTokenProvider($host, $host, new TestRefreshLocks, new MsGraphGrantRefresher)];
}

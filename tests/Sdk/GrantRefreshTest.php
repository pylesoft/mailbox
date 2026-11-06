<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\GrantIdentity;
use Pyle\Mailbox\DTOs\StoredGrant;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\GrantRefreshException;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Services\OAuth\GmailGrantOAuthService;
use Pyle\Mailbox\Services\OAuth\GmailGrantRefresher;
use Pyle\Mailbox\Support\CacheGrantRefreshLock;
use Pyle\Mailbox\Support\RefreshingGrantTokenProvider;
use Pyle\Mailbox\Tests\Support\TestEnrollmentHost;
use Pyle\Mailbox\Tests\Support\TestRefreshLocks;

beforeEach(function (): void {
    config()->set('mailbox.oauth.gmail.client_id', 'selected-client');
    config()->set('mailbox.oauth.gmail.client_secret', 'client-secret');
    Http::preventStrayRequests();
    $this->context = new GrantContext('connection', 'grant', 'generation-1', 'gmail', 'first@gmail.com');
    $this->host = new TestEnrollmentHost;
    $this->host->bind($this->context, 'host');
    $this->host->grants['grant'] = new StoredGrant(new GrantIdentity('gmail', 'https://accounts.google.com', 'subject', 'selected-client'),
        'expired-access', 'old-refresh', now()->subMinute()->toDateTimeImmutable(), ['openid'], 'revision-1');
});

it('redacts rejected Google refresh identity arguments with trace capture enabled', function (): void {
    $encode = static fn (array $part): string => rtrim(strtr(base64_encode(json_encode($part, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $jwt = $encode(['alg' => 'RS256']).'.'.$encode(['iss' => 'https://foreign.example', 'sub' => 'subject', 'aud' => 'selected-client',
        'exp' => now()->addHour()->getTimestamp(), 'iat' => now()->getTimestamp()]).'.private-signature';
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-access', 'expires_in' => 3600,
        'token_type' => 'Bearer', 'id_token' => $jwt])]);
    $tokens = new RefreshingGrantTokenProvider($this->host, $this->host, new TestRefreshLocks, new GmailGrantRefresher);
    $previous = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');

    try {
        try {
            $tokens->accessToken($this->context);
            $this->fail('A foreign Google refresh identity was accepted.');
        } catch (GrantAccessDeniedException $exception) {
            $trace = array_values(array_filter($exception->getTrace(), fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'Pyle\\Mailbox\\')));
            foreach ([$jwt, 'client-secret', 'old-refresh', 'fresh-access'] as $secret) {
                expect(json_encode($trace, JSON_PARTIAL_OUTPUT_ON_ERROR))->not->toContain($secret);
            }
            expect($this->host->load($this->context)?->revision)->toBe('revision-1');
        }
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
});

it('returns retry information without HTTP or job release when the named lock store is unavailable', function (): void {
    $job = Mockery::mock(Job::class);
    $job->shouldNotReceive('release');
    app()->instance('queue.job', $job);
    $tokens = new RefreshingGrantTokenProvider($this->host, $this->host, new CacheGrantRefreshLock('unconfigured'), new GmailGrantRefresher);
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"id":"message"}')]));
    $stack->push(Middleware::history($history));

    try {
        Mailbox::forGrant($this->context, $tokens, new Client(['handler' => $stack]))->message('message')->get();
        test()->fail('Unavailable coordination must stop the read.');
    } catch (GrantRefreshException $exception) {
        expect($exception->retryable)->toBeTrue()->and($exception->retryAfterSeconds)->toBe(1)
            ->and($exception->getPrevious())->toBeNull();
    }
    expect($history)->toBe([]);
    Http::assertNothingSent();
});

it('refreshes an expired selected grant then reads Gmail without package tables', function (): void {
    $host = new TestEnrollmentHost;
    $context = new GrantContext('connection', 'grant', 'generation-1', 'gmail', 'first@gmail.com');
    $host->bind($context, 'host');
    $host->grants['grant'] = new StoredGrant(new GrantIdentity('gmail', 'https://accounts.google.com', 'subject', 'selected-client'),
        'expired-access', 'old-refresh', now()->subMinute()->toDateTimeImmutable(), ['openid'], 'revision-1');
    $tokens = new RefreshingGrantTokenProvider($host, $host, new TestRefreshLocks, new GmailGrantRefresher);
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response([
        'access_token' => 'fresh-access', 'refresh_token' => 'rotated-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer',
    ])]);
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"id":"message"}')]));
    $stack->push(Middleware::history($history));
    $message = Mailbox::forGrant($context, $tokens, new Client(['handler' => $stack]))->message('message')->get();

    expect($message->id)->toBe('message')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer fresh-access')
        ->and($host->load($context)?->refreshToken())->toBe('rotated-refresh')
        ->and($host->load($context)?->revision)->not->toBe('revision-1')
        ->and($host->bindings['grant']['generation'])->toBe('generation-1')
        ->and(config('database.default'))->toBe('sdk-forbidden');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'old-refresh' && $request['client_id'] === 'selected-client'
        && $request['client_secret'] === 'client-secret');
});

it('rejects changed identity claims instead of preserving the old refresh secret', function (array $changed): void {
    $claims = array_replace(['iss' => 'https://accounts.google.com', 'aud' => 'selected-client', 'sub' => 'subject',
        'iat' => now()->getTimestamp(), 'exp' => now()->addHour()->getTimestamp()], $changed);
    $jwt = implode('.', array_map(static fn (array|string $value): string => rtrim(strtr(base64_encode(is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value), '+/', '-_'), '='),
        [['alg' => 'RS256'], $claims, 'direct-endpoint-signature']));
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response([
        'access_token' => 'fresh-access', 'expires_in' => 3600, 'token_type' => 'Bearer', 'id_token' => $jwt,
    ])]);
    $tokens = new RefreshingGrantTokenProvider($this->host, $this->host, new TestRefreshLocks, new GmailGrantRefresher);

    expect(fn () => $tokens->accessToken($this->context))->toThrow(GrantAccessDeniedException::class);
    expect($this->host->load($this->context)?->revision)->toBe('revision-1');
})->with([
    'subject' => [['sub' => 'different-subject']],
    'issuer' => [['iss' => 'https://foreign.example']],
    'client' => [['aud' => 'different-client']],
    'authorized party' => [['azp' => 'different-client']],
    'expired' => [['exp' => 1]],
    'future issuance' => [['iat' => PHP_INT_MAX]],
    'unexpected nonce' => [['nonce' => 'unbound-nonce']],
]);

it('classifies refresh failures without exposing provider secrets', function (int $status, string $error, string $exceptionClass, bool $retryable): void {
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response([
        'error' => $error, 'error_description' => 'client-secret old-refresh fresh-access',
    ], $status, ['Retry-After' => '7'])]);
    $tokens = new RefreshingGrantTokenProvider($this->host, $this->host, new TestRefreshLocks, new GmailGrantRefresher);

    try {
        $tokens->accessToken($this->context);
        test()->fail('Refresh should fail.');
    } catch (MailboxException $exception) {
        expect($exception)->toBeInstanceOf($exceptionClass)
            ->and((string) $exception)->not->toContain('client-secret')->not->toContain('old-refresh')->not->toContain('fresh-access')
            ->and($exception->getPrevious())->toBeNull();
        if ($exception instanceof GrantRefreshException) {
            expect($exception->retryable)->toBe($retryable)
                ->and($exception->retryAfterSeconds)->toBe($retryable ? 7 : null);
        }
    }
    expect($this->host->load($this->context)?->revision)->toBe('revision-1');
})->with([
    'rate limit' => [429, 'rate_limit_exceeded', GrantRefreshException::class, true],
    'server' => [503, 'server_error', GrantRefreshException::class, true],
    'temporary' => [400, 'temporarily_unavailable', GrantRefreshException::class, true],
    'revoked' => [400, 'invalid_grant', GrantAccessDeniedException::class, false],
    'insufficient' => [403, 'insufficient_scope', GrantAccessDeniedException::class, false],
    'consent' => [400, 'consent_required', GrantAccessDeniedException::class, false],
    'client setup' => [401, 'invalid_client', GrantRefreshException::class, false],
    'other rejection' => [400, 'invalid_request', GrantRefreshException::class, false],
]);

it('uses the host named lock store and one strict connection grant tuple across targets and generations', function (): void {
    $atomic = new ArrayStore;
    $store = Mockery::mock(Store::class, LockProvider::class);
    $store->shouldReceive('lock')->andReturnUsing(fn (string $key, int $seconds) => $atomic->lock($key, $seconds));
    Cache::extend('refresh-fixture', fn (): Repository => new Repository($store));
    config()->set('cache.stores.shared-refresh', ['driver' => 'refresh-fixture']);
    $locks = new CacheGrantRefreshLock('shared-refresh');
    $held = $locks->acquire($this->context);

    foreach ([new GrantContext('connection', 'grant', 'generation-2', 'gmail', 'second@gmail.com'), $this->context] as $contender) {
        expect(fn () => $locks->acquire($contender))->toThrow(GrantRefreshException::class);
    }
    foreach ([['connection', 'other'], ['other', 'grant'], ['01', 'grant'], ['1', 'grant'], ['a:b', 'c'], ['a', 'b:c']] as [$connection, $grant]) {
        $independent[] = $locks->acquire(new GrantContext($connection, $grant, 'generation-1', 'gmail', 'first@gmail.com'));
    }
    $held->release();
    $locks->acquire($this->context)->release();
    foreach ($independent as $lock) {
        $lock->release();
    }
    Http::assertNothingSent();
});

it('rejects process local and non atomic stores without an exchange', function (string $driver): void {
    config()->set('cache.stores.rejected-refresh', ['driver' => $driver, 'path' => sys_get_temp_dir().'/unused-refresh-fixture', 'stores' => ['array']]);
    $tokens = new RefreshingGrantTokenProvider($this->host, $this->host, new CacheGrantRefreshLock('rejected-refresh'), new GmailGrantRefresher);
    expect(fn () => $tokens->accessToken($this->context))->toThrow(GrantRefreshException::class);
    Http::assertNothingSent();
})->with(['array', 'file', 'null', 'failover']);

it('reloads a winning workers current credentials after acquiring the lock without another exchange', function (): void {
    $locks = new TestRefreshLocks;
    $locks->beforeAcquire = function (): void {
        $this->host->grants['grant'] = new StoredGrant($this->host->grants['grant']->identity,
            'winner-access', 'winner-refresh', now()->addHour()->toDateTimeImmutable(), ['openid'], 'winner-revision');
    };
    $tokens = new RefreshingGrantTokenProvider($this->host, $this->host, $locks, new GmailGrantRefresher);

    expect($tokens->accessToken($this->context))->toBe('winner-access')
        ->and($this->host->load($this->context)?->refreshToken())->toBe('winner-refresh');
    Http::assertNothingSent();
});

it('blocks competing mailbox targets during refresh and reuses the winner after release', function (): void {
    $locks = new TestRefreshLocks;
    $tokens = new RefreshingGrantTokenProvider($this->host, $this->host, $locks, new GmailGrantRefresher);
    $other = new GrantContext('connection', 'grant', 'generation-1', 'gmail', 'second@gmail.com');
    Http::fake(['https://oauth2.googleapis.com/token' => function () use ($tokens, $other) {
        expect(fn () => $tokens->accessToken($other))->toThrow(GrantRefreshException::class);

        return Http::response(['access_token' => 'winner-access', 'expires_in' => 3600, 'token_type' => 'Bearer']);
    }]);

    expect($tokens->accessToken($this->context))->toBe('winner-access')
        ->and($tokens->accessToken($other))->toBe('winner-access')
        ->and($this->host->load($this->context)?->refreshToken())->toBe('old-refresh');
    Http::assertSentCount(1);
});

it('rejects stale refresh commits and never performs the retained mailbox read', function (string $change): void {
    $tokens = new RefreshingGrantTokenProvider($this->host, $this->host, new TestRefreshLocks, new GmailGrantRefresher);
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"id":"message"}')]));
    $stack->push(Middleware::history($history));
    $resource = Mailbox::forGrant($this->context, $tokens, new Client(['handler' => $stack]));
    Http::fake(['https://oauth2.googleapis.com/token' => function () use ($change) {
        if ($change === 'reconnect') {
            $this->host->bindings['grant']['generation'] = 'generation-2';
        } elseif ($change === 'disconnect') {
            $this->host->denied = true;
        } elseif ($change === 'revision') {
            $previous = $this->host->grants['grant'];
            $this->host->grants['grant'] = new StoredGrant($previous->identity, 'winner-access', 'winner-refresh',
                now()->addHour()->toDateTimeImmutable(), ['openid'], 'winner-revision');
        } else {
            $this->host->rejectWrite = true;
        }

        return Http::response(['access_token' => 'stale-access', 'refresh_token' => 'stale-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer']);
    }]);

    expect(fn () => $resource->message('message')->get())->toThrow(in_array($change, ['reconnect', 'disconnect'], true) ? GrantAccessDeniedException::class : GrantRefreshException::class);
    expect($history)->toBe([])->and($this->host->grants['grant']->refreshToken())->toBe($change === 'revision' ? 'winner-refresh' : 'old-refresh');
    Http::assertSentCount(1);
    if (in_array($change, ['reconnect', 'disconnect'], true)) {
        expect(fn () => $resource->message('another')->get())->toThrow(GrantAccessDeniedException::class);
        Http::assertSentCount(1);
    }
})->with(['reconnect', 'disconnect', 'revision', 'conditional-write']);

it('rejects incompatible stored identity before exchanging its refresh secret', function (string $issuer, string $client, ?string $tenant): void {
    $this->host->grants['grant'] = new StoredGrant(new GrantIdentity('gmail', $issuer, 'subject', $client, $tenant),
        'expired-access', 'old-refresh', now()->subMinute()->toDateTimeImmutable(), ['openid'], 'revision-1');
    $tokens = new RefreshingGrantTokenProvider($this->host, $this->host, new TestRefreshLocks, new GmailGrantRefresher);
    expect(fn () => $tokens->accessToken($this->context))->toThrow(GrantAccessDeniedException::class);
    Http::assertNothingSent();
})->with([
    'issuer' => ['https://foreign.example', 'selected-client', null],
    'client' => ['https://accounts.google.com', 'other-client', null],
    'tenant' => ['https://accounts.google.com', 'selected-client', 'another-tenant'],
]);

it('fails closed with no exchange for failed or non atomic host coordination', function (bool $failed): void {
    $store = $failed ? Mockery::mock(Store::class, LockProvider::class) : Mockery::mock(Store::class);
    if ($failed) {
        $store->shouldReceive('lock')->andThrow(new RuntimeException('redis-secret old-refresh'));
    }
    Cache::extend('refresh-fixture', fn (): Repository => new Repository($store));
    config()->set('cache.stores.shared-refresh', ['driver' => 'refresh-fixture']);
    $tokens = new RefreshingGrantTokenProvider($this->host, $this->host, new CacheGrantRefreshLock('shared-refresh'), new GmailGrantRefresher);

    try {
        $tokens->accessToken($this->context);
        test()->fail('Coordination must fail.');
    } catch (GrantRefreshException $exception) {
        expect($exception->retryable)->toBeTrue()->and((string) $exception)->not->toContain('redis-secret')->not->toContain('old-refresh')
            ->and($exception->getPrevious())->toBeNull();
    }
    Http::assertNothingSent();
})->with([true, false]);

it('preserves the renewable secret for matching refresh identity and stores the returned scopes', function (): void {
    $claims = ['iss' => 'accounts.google.com', 'aud' => 'selected-client', 'sub' => 'subject',
        'iat' => now()->getTimestamp(), 'exp' => now()->addHour()->getTimestamp()];
    $jwt = implode('.', array_map(static fn (array|string $value): string => rtrim(strtr(base64_encode(is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value), '+/', '-_'), '='),
        [['alg' => 'RS256'], $claims, 'direct-endpoint-signature']));
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response([
        'access_token' => 'fresh-access', 'expires_in' => 3600, 'token_type' => 'Bearer', 'id_token' => $jwt, 'scope' => 'openid gmail.readonly',
    ])]);
    $tokens = new RefreshingGrantTokenProvider($this->host, $this->host, new TestRefreshLocks, new GmailGrantRefresher);

    expect($tokens->accessToken($this->context))->toBe('fresh-access')
        ->and($this->host->load($this->context)?->refreshToken())->toBe('old-refresh')
        ->and($this->host->load($this->context)?->scopes)->toBe(['openid', 'gmail.readonly']);
});

it('refreshes using the authoritative revision reloaded under the lock', function (): void {
    $locks = new TestRefreshLocks;
    $locks->beforeAcquire = function (): void {
        $this->host->grants['grant'] = new StoredGrant($this->host->grants['grant']->identity,
            'expired-newer-access', 'newer-refresh', now()->subMinute()->toDateTimeImmutable(), ['openid'], 'newer-revision');
    };
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response([
        'access_token' => 'fresh-access', 'expires_in' => 3600, 'token_type' => 'Bearer',
    ])]);
    $tokens = new RefreshingGrantTokenProvider($this->host, $this->host, $locks, new GmailGrantRefresher);

    expect($tokens->accessToken($this->context))->toBe('fresh-access')
        ->and($this->host->load($this->context)?->refreshToken())->toBe('newer-refresh');
    Http::assertSent(fn ($request): bool => $request['refresh_token'] === 'newer-refresh');
});

it('rejects unusable refresh credentials without replacing the selected grant', function (array $changed): void {
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response(array_replace([
        'access_token' => 'fresh-access', 'expires_in' => 3600, 'token_type' => 'Bearer',
    ], $changed))]);
    $tokens = new RefreshingGrantTokenProvider($this->host, $this->host, new TestRefreshLocks, new GmailGrantRefresher);
    expect(fn () => $tokens->accessToken($this->context))->toThrow(MailboxException::class);
    expect($this->host->load($this->context)?->revision)->toBe('revision-1')
        ->and($this->host->load($this->context)?->refreshToken())->toBe('old-refresh');
})->with([
    'missing access' => [['access_token' => null]],
    'injected access' => [['access_token' => "fresh\r\naccess"]],
    'invalid expiration' => [['expires_in' => -1]],
    'invalid type' => [['token_type' => 'mac']],
    'invalid rotation' => [['refresh_token' => null]],
    'malformed identity' => [['id_token' => 'not-an-id-token']],
    'empty scopes' => [['scope' => '']],
]);

it('redacts refresh transport failures and releases coordination for a later host retry', function (): void {
    $locks = new TestRefreshLocks;
    $tokens = new RefreshingGrantTokenProvider($this->host, $this->host, $locks, new GmailGrantRefresher);
    $first = true;
    Http::fake(['https://oauth2.googleapis.com/token' => function () use (&$first) {
        if ($first) {
            $first = false;
            throw new ConnectionException('client-secret old-refresh');
        }

        return Http::response(['access_token' => 'retry-access', 'expires_in' => 3600, 'token_type' => 'Bearer']);
    }]);
    try {
        $tokens->accessToken($this->context);
        test()->fail('The transport should fail.');
    } catch (GrantRefreshException $exception) {
        expect($exception->retryable)->toBeTrue()->and($exception->getPrevious())->toBeNull()
            ->and((string) $exception)->not->toContain('client-secret')->not->toContain('old-refresh');
    }
    expect($tokens->accessToken($this->context))->toBe('retry-access');
});

it('uses a current selected token without coordination or a configured credential fallback', function (): void {
    $this->host->grants['grant'] = new StoredGrant($this->host->grants['grant']->identity,
        'current-access', 'old-refresh', now()->addHour()->toDateTimeImmutable(), ['openid'], 'current-revision');
    $tokens = new RefreshingGrantTokenProvider($this->host, $this->host, new CacheGrantRefreshLock('unconfigured'), new GmailGrantRefresher);
    expect($tokens->accessToken($this->context))->toBe('current-access');
    $this->host->denied = true;
    expect(fn () => $tokens->accessToken($this->context))->toThrow(GrantAccessDeniedException::class);
    Http::assertNothingSent();
});

it('keeps separate host grants for the same Google identity and target independent', function (): void {
    $other = new GrantContext('other-connection', 'other-grant', 'generation-1', 'gmail', 'first@gmail.com');
    $this->host->bind($other, 'other-host');
    $this->host->grants['other-grant'] = new StoredGrant($this->host->grants['grant']->identity,
        'expired-other', 'other-refresh', now()->subMinute()->toDateTimeImmutable(), ['openid'], 'other-revision');
    Http::fake(['https://oauth2.googleapis.com/token' => fn ($request) => Http::response([
        'access_token' => $request['refresh_token'] === 'other-refresh' ? 'other-access' : 'first-access',
        'expires_in' => 3600, 'token_type' => 'Bearer',
    ])]);
    $tokens = new RefreshingGrantTokenProvider($this->host, $this->host, new TestRefreshLocks, new GmailGrantRefresher);
    expect($tokens->accessToken($this->context))->toBe('first-access')
        ->and($tokens->accessToken($other))->toBe('other-access')
        ->and($this->host->load($this->context)?->refreshToken())->toBe('old-refresh')
        ->and($this->host->load($other)?->refreshToken())->toBe('other-refresh');
    Http::assertSentCount(2);
});

it('verifies the original nonce from enrollment during refresh without exposing it', function (string $mode): void {
    config()->set('mailbox.oauth.gmail.redirect_uri', 'https://host.example/oauth/google');
    config()->set('mailbox.oauth.gmail.scopes', ['openid', 'gmail.readonly']);
    $oauth = new GmailGrantOAuthService($this->host, $this->host, $this->host);
    parse_str((string) parse_url($oauth->authorizationUrl($this->context, 'host'), PHP_URL_QUERY), $query);
    $encode = static fn (array $part): string => rtrim(strtr(base64_encode(json_encode($part, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $claims = ['iss' => 'https://accounts.google.com', 'aud' => 'selected-client', 'sub' => 'subject',
        'iat' => now()->getTimestamp(), 'exp' => now()->addHour()->getTimestamp(), 'nonce' => $query['nonce']];
    $jwt = static fn (array $payload): string => $encode(['alg' => 'RS256']).'.'.$encode($payload).'.direct-endpoint-signature';
    $responses = Http::fakeSequence()->push([
        'access_token' => 'enrolled-access', 'refresh_token' => 'enrolled-refresh', 'expires_in' => 60, 'token_type' => 'Bearer', 'id_token' => $jwt($claims),
    ]);
    $oauth->handleCallback($this->context, 'host', $query['state'], 'one-use-code');
    $enrolled = $this->host->load($this->context);
    $nonceHash = hash('sha256', $query['nonce']);
    expect($enrolled->nonceHash())->toBe($nonceHash)
        ->and(json_encode($enrolled))->not->toContain($query['nonce'])->not->toContain($nonceHash)
        ->and(print_r($enrolled, true))->not->toContain($query['nonce'])->not->toContain($nonceHash);
    if ($mode === 'missing-baseline') {
        $this->host->grants['grant'] = new StoredGrant($enrolled->identity, $enrolled->accessToken(), $enrolled->refreshToken(),
            $enrolled->expiresAt, $enrolled->scopes, $enrolled->revision);
    } elseif ($mode === 'mismatch') {
        $claims['nonce'] = 'different-nonce';
    } elseif ($mode === 'omitted') {
        unset($claims['nonce']);
    }
    $responses->push(['access_token' => 'refreshed-access', 'refresh_token' => 'rotated-refresh',
        'expires_in' => 3600, 'token_type' => 'Bearer', 'id_token' => $jwt($claims)]);
    $this->travel(61)->seconds();
    $tokens = new RefreshingGrantTokenProvider($this->host, $this->host, new TestRefreshLocks, new GmailGrantRefresher);

    if (in_array($mode, ['mismatch', 'missing-baseline'], true)) {
        expect(fn () => $tokens->accessToken($this->context))->toThrow(GrantAccessDeniedException::class)
            ->and($this->host->load($this->context)?->refreshToken())->toBe('enrolled-refresh');
    } else {
        expect($tokens->accessToken($this->context))->toBe('refreshed-access')
            ->and($this->host->load($this->context)?->refreshToken())->toBe('rotated-refresh')
            ->and($this->host->load($this->context)?->nonceHash())->toBe($nonceHash);
    }
    Http::assertSentCount(2);
})->with(['matching', 'mismatch', 'omitted', 'missing-baseline']);

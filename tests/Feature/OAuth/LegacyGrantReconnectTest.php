<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\OAuthEnrollmentException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Models\MailboxConnection;
use Pyle\Mailbox\Models\MailboxOAuthToken;
use Pyle\Mailbox\Services\OAuth\GmailGrantOAuthService;
use Pyle\Mailbox\Services\OAuth\GmailGrantRefresher;
use Pyle\Mailbox\Services\OAuth\MsGraphGrantOAuthService;
use Pyle\Mailbox\Services\OAuth\MsGraphGrantRefresher;
use Pyle\Mailbox\Support\EloquentGrantStore;
use Pyle\Mailbox\Support\RefreshingGrantTokenProvider;
use Pyle\Mailbox\Tests\Support\TestEnrollmentHost;
use Pyle\Mailbox\Tests\Support\TestRefreshLocks;

beforeEach(function (): void {
    config()->set([
        'mailbox.oauth.gmail.client_id' => 'selected-client',
        'mailbox.oauth.gmail.client_secret' => 'private-client-secret',
        'mailbox.oauth.gmail.redirect_uri' => 'https://host.example/oauth/google',
        'mailbox.drivers.ms-graph.tenant_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        'mailbox.drivers.ms-graph.client_id' => 'selected-client',
        'mailbox.drivers.ms-graph.client_secret' => 'private-client-secret',
        'mailbox.oauth.ms_graph.redirect_uri' => 'https://host.example/oauth/microsoft',
    ]);
    Http::preventStrayRequests();
});

it('upgrades and reconnects independent same-account contexts while retaining legacy history and references', function (string $driver): void {
    $migration = require __DIR__.'/../../../database/migrations/2026_10_29_000008_add_selected_authorization_grants.php';
    $migration->down();
    $connections = [];
    $messages = [];
    foreach (['first', 'second'] as $name) {
        $connection = MailboxConnection::query()->create(['name' => $name, 'driver' => $driver, 'config' => ['configured' => $name]]);
        $connections[] = $connection;
        $mailbox = $connection->mailboxes()->create(['email_address' => 'same@example.com']);
        $messages[] = $mailbox->messages()->create(['provider_message_id' => $name, 'canonical_message_key' => $driver.':'.$name,
            'subject' => 'Retained conversation', 'body' => ['content' => 'Retained history']]);
    }
    $legacy = [];
    foreach ([$connections[0]->id, $connections[1]->id, null, $connections[0]->id, $connections[0]->id] as $index => $connectionId) {
        $record = MailboxOAuthToken::query()->create([
            'mailbox_connection_id' => $connectionId, 'provider' => $driver.'-user',
            'external_user_id' => $index === 3 ? null : ($driver === 'gmail' ? 'same-subject' : 'graph-object'),
            'email' => 'same@example.com', 'tenant_id' => $driver === 'gmail' ? null : 'old-unverified-tenant',
            'access_token' => 'legacy-access-'.$index, 'refresh_token' => 'legacy-refresh-'.$index,
            'revoked_at' => $index === 4 ? now() : null, 'meta' => ['historical' => $index],
        ]);
        $legacy[$record->id] = $record->fresh()?->getAttributes();
    }
    $migration->up();
    $store = new EloquentGrantStore;
    $host = new TestEnrollmentHost;
    $oauth = $driver === 'gmail' ? new GmailGrantOAuthService($host, $host, $store) : new MsGraphGrantOAuthService($host, $host, $store);
    $refresher = $driver === 'gmail' ? new GmailGrantRefresher : new MsGraphGrantRefresher;
    $tokens = new RefreshingGrantTokenProvider($host, $store, new TestRefreshLocks, $refresher);
    $nextTokens = [];
    Http::fake([
        '*token' => function () use (&$nextTokens) {
            return Http::response($nextTokens);
        },
        'https://graph.microsoft.com/v1.0/me*' => Http::response(['id' => 'graph-object', 'mail' => 'same@example.com']),
    ]);
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{"id":"first"}'), new Response(200, [], '{"id":"second"}'),
        new Response(200, [], '{"id":"reconnected"}'), new Response(200, [], '{"id":"still-second"}'),
    ]));
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack]);
    $contexts = [];
    $resources = [];
    foreach ($connections as $index => $connection) {
        expect($connection->fresh()?->selected_grant_id)->toBeNull()
            ->and($connection->fresh()?->lifecycle_generation)->toBeNull();
        $connection->update(['selected_grant_id' => 'selected-'.$index, 'lifecycle_generation' => 'generation-1']);
        $context = new GrantContext((string) $connection->id, 'selected-'.$index, 'generation-1', $driver, 'same@example.com');
        $contexts[] = $context;
        $host->bind($context, 'host-'.$index);
        expect($store->load($context))->toBeNull();
        parse_str((string) parse_url($oauth->authorizationUrl($context, 'host-'.$index), PHP_URL_QUERY), $query);
        $nextTokens = legacyReconnectTokens($driver, $query['nonce'], 'access-'.$index);
        $result = $oauth->handleCallback($context, 'host-'.$index, $query['state'], 'code-'.$index);
        $resources[] = Mailbox::forGrant($context, $tokens, $http);

        expect($resources[$index]->message($connection->name)->get()->id)->toBe($connection->name)
            ->and($result->context->matches($context))->toBeTrue()
            ->and($store->load($context)?->identity->subject)->toBe('same-subject')
            ->and($store->load($context)?->refreshToken())->toBe('access-'.$index.'-refresh');
    }
    $previousSelected = MailboxOAuthToken::query()->where('grant_id', 'selected-0')->firstOrFail()->getAttributes();
    DB::transaction(fn () => $connections[0]->update(['selected_grant_id' => 'reconnected', 'lifecycle_generation' => 'generation-2']));
    $reconnected = new GrantContext((string) $connections[0]->id, 'reconnected', 'generation-2', $driver, 'same@example.com');
    $host->bind($reconnected, 'host-0');
    parse_str((string) parse_url($oauth->authorizationUrl($reconnected, 'host-0'), PHP_URL_QUERY), $query);
    $nextTokens = legacyReconnectTokens($driver, $query['nonce'], 'fresh-access');
    $oauth->handleCallback($reconnected, 'host-0', $query['state'], 'reconnect-code');

    expect(fn () => $resources[0]->message('stale')->get())->toThrow(GrantAccessDeniedException::class);
    expect($history)->toHaveCount(2);
    expect(Mailbox::forGrant($reconnected, $tokens, $http)->message('reconnected')->get()->id)->toBe('reconnected')
        ->and($resources[1]->message('still-second')->get()->id)->toBe('still-second')
        ->and(array_map(fn (array $entry): string => $entry['request']->getHeaderLine('Authorization'), $history))
        ->toBe(['Bearer access-0', 'Bearer access-1', 'Bearer fresh-access', 'Bearer access-1'])
        ->and($store->load($contexts[0]))->toBeNull()
        ->and($store->load($contexts[1])?->refreshToken())->toBe('access-1-refresh')
        ->and(MailboxOAuthToken::query()->where('grant_id', 'selected-0')->firstOrFail()->getAttributes())->toBe($previousSelected)
        ->and(MailboxOAuthToken::query()->count())->toBe(8);
    foreach ($legacy as $id => $attributes) {
        $retained = MailboxOAuthToken::query()->findOrFail($id);
        expect(array_intersect_key($retained->getAttributes(), $attributes))->toBe($attributes)
            ->and($retained->grant_id)->toBeNull()
            ->and($retained->lifecycle_generation)->toBeNull();
    }
    foreach ($messages as $index => $message) {
        expect($message->fresh()?->body)->toBe(['content' => 'Retained history'])
            ->and($message->fresh()?->mailbox->connection->id)->toBe($connections[$index]->id)
            ->and($connections[$index]->fresh()?->config)->toBe(['configured' => $connections[$index]->name]);
    }
})->with(['gmail', 'ms-graph']);

it('requires reconnect instead of promoting a selected legacy record without verified identity', function (string $driver): void {
    $connection = MailboxConnection::query()->create(['name' => 'Legacy', 'driver' => $driver,
        'selected_grant_id' => 'selected', 'lifecycle_generation' => 'generation-1']);
    $context = new GrantContext((string) $connection->id, 'selected', 'generation-1', $driver, 'same@example.com');
    $legacy = MailboxOAuthToken::query()->create(['mailbox_connection_id' => $connection->id, 'provider' => $driver.'-user',
        'grant_id' => 'selected', 'lifecycle_generation' => 'generation-1', 'external_user_id' => 'same-subject',
        'email' => 'same@example.com', 'access_token' => 'legacy-access', 'refresh_token' => 'legacy-refresh']);
    $before = $legacy->fresh()?->getAttributes();
    $host = new TestEnrollmentHost;
    $host->bind($context, 'host');
    $store = new EloquentGrantStore;
    $tokens = new RefreshingGrantTokenProvider($host, $store, new TestRefreshLocks,
        $driver === 'gmail' ? new GmailGrantRefresher : new MsGraphGrantRefresher);
    $history = [];
    $stack = HandlerStack::create(new MockHandler([]));
    $stack->push(Middleware::history($history));

    expect(fn () => Mailbox::forGrant($context, $tokens, new Client(['handler' => $stack]))->message('must-not-read')->get())
        ->toThrow(GrantAccessDeniedException::class, 'Reconnect');
    expect($history)->toBe([])
        ->and($store->load($context))->toBeNull()
        ->and($legacy->fresh()?->getAttributes())->toBe($before);
    Http::assertNothingSent();
})->with(['gmail', 'ms-graph']);

it('rejects unproven explicit enrollment without touching historical credentials', function (string $driver, string $failure): void {
    $connection = MailboxConnection::query()->create(['name' => 'Selected', 'driver' => $driver,
        'selected_grant_id' => 'selected', 'lifecycle_generation' => 'generation-1']);
    $context = new GrantContext((string) $connection->id, 'selected', 'generation-1', $driver, 'same@example.com');
    $legacy = MailboxOAuthToken::query()->create(['mailbox_connection_id' => null, 'provider' => $driver.'-user',
        'external_user_id' => 'same-subject', 'email' => 'same@example.com',
        'access_token' => 'historical-access', 'refresh_token' => 'historical-refresh']);
    $before = $legacy->fresh()?->getAttributes();
    $host = new TestEnrollmentHost;
    $host->bind($context, 'authorized-host');
    $store = new EloquentGrantStore;
    $oauth = $driver === 'gmail' ? new GmailGrantOAuthService($host, $host, $store) : new MsGraphGrantOAuthService($host, $host, $store);
    parse_str((string) parse_url($oauth->authorizationUrl($context, 'authorized-host'), PHP_URL_QUERY), $query);
    $claims = match ($failure) {
        'client' => ['aud' => 'another-client'],
        'tenant' => ['tid' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff', 'iss' => 'https://login.microsoftonline.com/bbbbbbbb-cccc-dddd-eeee-ffffffffffff/v2.0'],
        default => [],
    };
    $response = legacyReconnectTokens($driver, $query['nonce'], 'must-not-save', $claims);
    if ($failure === 'refresh') {
        unset($response['refresh_token']);
    }
    Http::fake([
        '*token' => Http::response($response),
        'https://graph.microsoft.com/v1.0/me*' => Http::response(['id' => $failure === 'profile' ? 'another-object' : 'graph-object']),
    ]);

    expect(fn () => $oauth->handleCallback($context, $failure === 'host' ? 'foreign-host' : 'authorized-host', $query['state'], 'one-use-code'))
        ->toThrow(OAuthEnrollmentException::class);
    expect($store->load($context))->toBeNull()
        ->and($legacy->fresh()?->getAttributes())->toBe($before)
        ->and(MailboxOAuthToken::query()->count())->toBe(1);
    if ($failure === 'host') {
        Http::assertNothingSent();
    }
    expect(fn () => $oauth->handleCallback($context, 'authorized-host', $query['state'], 'replay-code'))
        ->toThrow(OAuthEnrollmentException::class, 'already consumed');
})->with([
    ['gmail', 'host'], ['gmail', 'client'], ['gmail', 'refresh'],
    ['ms-graph', 'host'], ['ms-graph', 'client'], ['ms-graph', 'tenant'], ['ms-graph', 'profile'], ['ms-graph', 'refresh'],
]);

it('refuses reconnect credentials if persistent selection changes during provider exchange', function (string $driver): void {
    $connection = MailboxConnection::query()->create(['name' => 'Selected', 'driver' => $driver,
        'selected_grant_id' => 'selected', 'lifecycle_generation' => 'generation-1']);
    $context = new GrantContext((string) $connection->id, 'selected', 'generation-1', $driver, 'same@example.com');
    $legacy = MailboxOAuthToken::query()->create(['mailbox_connection_id' => $connection->id, 'provider' => $driver.'-user',
        'external_user_id' => 'same-subject', 'access_token' => 'historical-access', 'refresh_token' => 'historical-refresh']);
    $before = $legacy->fresh()?->getAttributes();
    $host = new TestEnrollmentHost;
    $host->bind($context, 'host');
    $store = new EloquentGrantStore;
    $oauth = $driver === 'gmail' ? new GmailGrantOAuthService($host, $host, $store) : new MsGraphGrantOAuthService($host, $host, $store);
    parse_str((string) parse_url($oauth->authorizationUrl($context, 'host'), PHP_URL_QUERY), $query);
    Http::fake([
        '*token' => function () use ($connection, $driver, $query) {
            $connection->update(['lifecycle_generation' => 'generation-2']);

            return Http::response(legacyReconnectTokens($driver, $query['nonce'], 'late-access'));
        },
        'https://graph.microsoft.com/v1.0/me*' => Http::response(['id' => 'graph-object']),
    ]);

    expect(fn () => $oauth->handleCallback($context, 'host', $query['state'], 'one-use-code'))
        ->toThrow(GrantAccessDeniedException::class, 'changed before authorization could be saved');
    expect($store->load($context))->toBeNull()
        ->and($legacy->fresh()?->getAttributes())->toBe($before)
        ->and(MailboxOAuthToken::query()->count())->toBe(1);
})->with(['gmail', 'ms-graph']);

/**
 * @param array<string, mixed> $claims
 * @return array<string, mixed>
 */
function legacyReconnectTokens(string $driver, string $nonce, string $accessToken, array $claims = []): array
{
    $tenant = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $identity = $driver === 'gmail' ? ['iss' => 'https://accounts.google.com'] : [
        'iss' => 'https://login.microsoftonline.com/'.$tenant.'/v2.0', 'tid' => $tenant, 'oid' => 'graph-object', 'ver' => '2.0',
        'nbf' => now()->subMinute()->getTimestamp(),
    ];
    $payload = array_replace($identity, [
        'sub' => 'same-subject', 'aud' => 'selected-client', 'exp' => now()->addHour()->getTimestamp(),
        'iat' => now()->getTimestamp(), 'nonce' => $nonce, 'email' => 'same@example.com',
    ], $claims);
    $encode = static fn (array $part): string => rtrim(strtr(base64_encode(json_encode($part, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    return ['access_token' => $accessToken, 'refresh_token' => $accessToken.'-refresh', 'expires_in' => 3600,
        'token_type' => 'Bearer', 'scope' => 'openid profile offline_access User.Read',
        'id_token' => $encode(['alg' => 'RS256']).'.'.$encode($payload).'.provider-signature'];
}

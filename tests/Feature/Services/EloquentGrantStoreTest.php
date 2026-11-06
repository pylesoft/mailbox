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
use Pyle\Mailbox\Enums\ConnectionStatus;
use Pyle\Mailbox\Exceptions\GrantRefreshException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Models\MailboxConnection;
use Pyle\Mailbox\Models\MailboxOAuthToken;
use Pyle\Mailbox\Services\OAuth\GmailGrantRefresher;
use Pyle\Mailbox\Services\OAuth\MsGraphOAuthCallbackResult;
use Pyle\Mailbox\Support\EloquentGrantStore;
use Pyle\Mailbox\Support\RefreshingGrantTokenProvider;
use Pyle\Mailbox\Tests\Support\TestEnrollmentHost;
use Pyle\Mailbox\Tests\Support\TestRefreshLocks;

it('retains a selected verified grant and its private refresh evidence across store instances', function (): void {
    $connection = MailboxConnection::query()->create([
        'name' => 'Selected Microsoft grant',
        'driver' => 'ms-graph',
        'selected_grant_id' => 'grant-a',
        'lifecycle_generation' => 'generation-a',
    ]);
    $context = new GrantContext((string) $connection->id, 'grant-a', 'generation-a', 'ms-graph', 'shared@example.com');
    $grant = new StoredGrant(new GrantIdentity('ms-graph', 'https://login.microsoftonline.com/tenant/v2.0', 'subject', 'client', 'tenant'),
        'private-access', 'private-refresh', now()->addHour()->toDateTimeImmutable(), ['Mail.Read'], 'revision-a',
        'owner@example.com', ['object_id' => 'verified-object-id'], hash('sha256', 'enrollment-nonce'));

    expect((new EloquentGrantStore)->replace($context, null, $grant))->toBeTrue();
    $reloaded = (new EloquentGrantStore)->load($context);

    expect($reloaded)->not->toBeNull()
        ->and($reloaded?->identity->matches($grant->identity))->toBeTrue()
        ->and($reloaded?->accessToken())->toBe('private-access')
        ->and($reloaded?->refreshToken())->toBe('private-refresh')
        ->and($reloaded?->nonceHash())->toBe(hash('sha256', 'enrollment-nonce'))
        ->and($reloaded?->metadata)->toBe(['object_id' => 'verified-object-id'])
        ->and($reloaded?->revision)->toBe('revision-a')
        ->and($reloaded?->scopes)->toBe(['Mail.Read'])
        ->and($reloaded?->expiresAt->getTimestamp())->toBe($grant->expiresAt->getTimestamp());
});

it('rejects a rotation with changed expected or replacement Microsoft object identity', function (bool $changeExpected): void {
    $connection = MailboxConnection::query()->create([
        'name' => 'Selected Microsoft grant', 'driver' => 'ms-graph',
        'selected_grant_id' => 'grant-a', 'lifecycle_generation' => 'generation-a',
    ]);
    $context = new GrantContext((string) $connection->id, 'grant-a', 'generation-a', 'ms-graph', 'shared@example.com');
    $identity = new GrantIdentity('ms-graph', 'https://login.microsoftonline.com/tenant/v2.0', 'subject', 'client', 'tenant');
    $original = new StoredGrant($identity, 'access-a', 'refresh-a', now()->addHour()->toDateTimeImmutable(), [], 'revision-a',
        metadata: ['object_id' => 'verified-object-a']);
    $store = new EloquentGrantStore;
    expect($store->replace($context, null, $original))->toBeTrue();
    $replacement = new StoredGrant($identity, 'access-b', 'refresh-b', $original->expiresAt, [], 'revision-b',
        metadata: ['object_id' => $changeExpected ? 'verified-object-a' : 'other-object']);
    $expected = $changeExpected ? new StoredGrant($identity, 'access-a', 'refresh-a', $original->expiresAt, [], 'revision-a',
        metadata: ['object_id' => 'other-object']) : $store->load($context);

    expect($store->replace($context, $expected, $replacement))->toBeFalse()
        ->and($store->load($context)?->metadata)->toBe(['object_id' => 'verified-object-a'])
        ->and($store->load($context)?->refreshToken())->toBe('refresh-a');
})->with([false, true]);

it('rotates within one generation while rejecting stale null and reused revisions', function (): void {
    [$connection, $context, $original, $store] = eloquentGrantFixture();
    $competingStore = new EloquentGrantStore;
    $captured = $competingStore->load($context);
    $winner = new StoredGrant($original->identity, 'winner-access', 'winner-refresh', $original->expiresAt, ['Mail.ReadWrite'], 'revision-b',
        'renamed@example.com', ['object_id' => 'verified-object', 'display_name' => 'New display name'], hash('sha256', 'new-enrollment-nonce'));
    expect($store->replace($context, $store->load($context), $winner))->toBeTrue();
    $loser = new StoredGrant($original->identity, 'loser-access', 'loser-refresh', $original->expiresAt, [], 'revision-c', metadata: $original->metadata);

    expect($competingStore->replace($context, $captured, $loser))->toBeFalse()
        ->and($competingStore->replace($context, null, $loser))->toBeFalse()
        ->and($store->replace($context, $store->load($context), $original))->toBeFalse()
        ->and($store->replace($context, $store->load($context), $winner))->toBeFalse()
        ->and($store->load($context)?->refreshToken())->toBe('winner-refresh')
        ->and($store->load($context)?->email)->toBe('renamed@example.com')
        ->and($store->load($context)?->metadata['display_name'])->toBe('New display name')
        ->and($store->load($context)?->nonceHash())->toBe(hash('sha256', 'new-enrollment-nonce'))
        ->and($connection->fresh()?->lifecycle_generation)->toBe('generation-a');
});

it('rejects stale loads and both kinds of writes after lifecycle invalidation', function (string $change): void {
    [$connection, $context, $original, $store] = eloquentGrantFixture();
    $captured = $store->load($context);
    match ($change) {
        'generation' => $connection->update(['lifecycle_generation' => 'generation-b']),
        'selection' => $connection->update(['selected_grant_id' => null]),
        'driver' => $connection->update(['driver' => 'gmail']),
        'disabled' => $connection->update(['status' => ConnectionStatus::DISABLED]),
        'deleted' => $connection->delete(),
        'revoked' => $connection->oauthTokens()->update(['revoked_at' => now()]),
    };
    $replacement = new StoredGrant($original->identity, 'late-access', 'late-refresh', $original->expiresAt, [], 'revision-b', metadata: $original->metadata);

    expect($store->load($context))->toBeNull()
        ->and($store->replace($context, $captured, $replacement))->toBeFalse()
        ->and($store->replace($context, null, $replacement))->toBeFalse();
})->with(['generation', 'selection', 'driver', 'disabled', 'deleted', 'revoked']);

it('reactivates only under a fresh generation and retains revision reuse protection', function (): void {
    [$connection, $context, $original, $store] = eloquentGrantFixture();
    $connection->oauthTokens()->update(['revoked_at' => now()]);
    $connection->update(['lifecycle_generation' => 'generation-b']);
    $reconnected = new GrantContext((string) $connection->id, $context->grantId, 'generation-b', $context->driver, $context->mailbox);
    $newGrant = new StoredGrant(new GrantIdentity('ms-graph', $original->identity->issuer, 'new-subject', 'new-client', 'tenant'),
        'new-access', 'new-refresh', $original->expiresAt, [], 'revision-b', metadata: ['object_id' => 'new-object']);

    expect($store->load($reconnected))->toBeNull()
        ->and($store->replace($reconnected, null, $original))->toBeFalse()
        ->and($store->replace($reconnected, null, $newGrant))->toBeTrue()
        ->and($store->load($reconnected)?->identity->subject)->toBe('new-subject')
        ->and($store->load($context))->toBeNull()
        ->and($store->replace($context, $original, $newGrant))->toBeFalse();
});

it('never exposes the earlier providers credentials after the configured driver changes', function (): void {
    [$connection, $context, , $store] = eloquentGrantFixture();
    $connection->update(['driver' => 'gmail']);
    $changed = new GrantContext($context->connectionId, $context->grantId, $context->lifecycleGeneration, 'gmail', $context->mailbox);

    expect($store->load($changed))->toBeNull();
});

it('rejects numeric connection aliases instead of coercing them into another context', function (): void {
    [, $context, $original, $store] = eloquentGrantFixture();
    $alias = new GrantContext('0'.$context->connectionId, $context->grantId, $context->lifecycleGeneration, $context->driver, $context->mailbox);

    expect($store->load($alias))->toBeNull()
        ->and($store->replace($alias, $original, $original))->toBeFalse()
        ->and($store->replace($alias, null, $original))->toBeFalse()
        ->and($store->load($context)?->accessToken())->toBe('private-access');
});

it('refreshes one persisted grant across mailbox targets while keeping same-account contexts independent', function (): void {
    Http::preventStrayRequests();
    config()->set('mailbox.oauth.gmail.client_id', 'selected-client');
    config()->set('mailbox.oauth.gmail.client_secret', 'client-secret');
    $store = new EloquentGrantStore;
    $identity = new GrantIdentity('gmail', 'https://accounts.google.com', 'same-subject', 'selected-client');
    $contexts = [];
    $providers = [];
    $locks = new TestRefreshLocks;
    foreach (['first', 'second'] as $index => $name) {
        $connection = MailboxConnection::query()->create(['name' => $name, 'driver' => 'gmail',
            'selected_grant_id' => 'same-grant-id', 'lifecycle_generation' => 'generation-a']);
        $context = new GrantContext((string) $connection->id, 'same-grant-id', 'generation-a', 'gmail', 'same@gmail.com');
        $contexts[] = $context;
        $grant = new StoredGrant($identity, $name.'-access', $name.'-refresh',
            ($index === 0 ? now()->subMinute() : now()->addHour())->toDateTimeImmutable(), ['openid'], 'revision-'.$name,
            nonceHash: hash('sha256', $name.'-nonce'));
        expect($store->replace($context, null, $grant))->toBeTrue();
        $guard = new TestEnrollmentHost;
        $guard->bind($context, $name);
        $providers[] = new RefreshingGrantTokenProvider($guard, $store, $locks, new GmailGrantRefresher);
    }
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response([
        'access_token' => 'rotated-first-access', 'refresh_token' => 'rotated-first-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer',
    ])]);
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"id":"one"}'), new Response(200, [], '{"id":"two"}'),
        new Response(200, [], '{"id":"three"}')]));
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack]);
    $otherMailbox = new GrantContext($contexts[0]->connectionId, 'same-grant-id', 'generation-a', 'gmail', 'permitted-shared@example.com');

    expect(Mailbox::forGrant($contexts[0], $providers[0], $http)->message('one')->get()->id)->toBe('one')
        ->and(Mailbox::forGrant($otherMailbox, $providers[0], $http)->message('two')->get()->id)->toBe('two')
        ->and(Mailbox::forGrant($contexts[1], $providers[1], $http)->message('three')->get()->id)->toBe('three')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer rotated-first-access')
        ->and($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer rotated-first-access')
        ->and($history[2]['request']->getHeaderLine('Authorization'))->toBe('Bearer second-access')
        ->and((string) $history[1]['request']->getUri())->toContain('/users/permitted-shared%40example.com/messages/two')
        ->and($store->load($otherMailbox)?->refreshToken())->toBe('rotated-first-refresh')
        ->and($store->load($otherMailbox)?->nonceHash())->toBe(hash('sha256', 'first-nonce'))
        ->and($store->load($contexts[1])?->refreshToken())->toBe('second-refresh');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request['refresh_token'] === 'first-refresh');
});

it('refuses a stale refresh commit when persistent lifecycle changes during provider IO', function (): void {
    Http::preventStrayRequests();
    config()->set('mailbox.oauth.gmail.client_id', 'selected-client');
    config()->set('mailbox.oauth.gmail.client_secret', 'client-secret');
    $connection = MailboxConnection::query()->create(['name' => 'First', 'driver' => 'gmail',
        'selected_grant_id' => 'grant-a', 'lifecycle_generation' => 'generation-a']);
    $context = new GrantContext((string) $connection->id, 'grant-a', 'generation-a', 'gmail', 'same@gmail.com');
    $store = new EloquentGrantStore;
    $original = new StoredGrant(new GrantIdentity('gmail', 'https://accounts.google.com', 'subject', 'selected-client'),
        'expired-access', 'original-refresh', now()->subMinute()->toDateTimeImmutable(), [], 'revision-a');
    expect($store->replace($context, null, $original))->toBeTrue();
    $guard = new TestEnrollmentHost;
    $guard->bind($context, 'host');
    $tokens = new RefreshingGrantTokenProvider($guard, $store, new TestRefreshLocks, new GmailGrantRefresher);
    Http::fake(['https://oauth2.googleapis.com/token' => function () use ($connection) {
        $connection->update(['lifecycle_generation' => 'generation-b']);

        return Http::response(['access_token' => 'late-access', 'refresh_token' => 'late-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer']);
    }]);
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"id":"must-not-read"}')]));
    $stack->push(Middleware::history($history));

    expect(fn () => Mailbox::forGrant($context, $tokens, new Client(['handler' => $stack]))->message('message')->get())
        ->toThrow(GrantRefreshException::class, 'changed before refresh could be saved');
    expect($history)->toBe([])
        ->and($store->load($context))->toBeNull()
        ->and($connection->oauthTokens()->firstOrFail()->refresh_token)->toBe('original-refresh');
    Http::assertSentCount(1);
});

it('keeps encrypted credentials and revision evidence out of model and DTO diagnostics', function (): void {
    [, $context, $original, $store] = eloquentGrantFixture();
    $record = MailboxOAuthToken::query()->firstOrFail();

    expect($record->access_token)->toBe($original->accessToken())
        ->and($record->refresh_token)->toBe($original->refreshToken());
    foreach (['access_token' => 'private-access', 'refresh_token' => 'private-refresh', 'nonce_hash' => hash('sha256', 'nonce'),
        'grant_revision_history' => hash('sha256', 'revision-a')] as $column => $privateValue) {
        expect($record->getRawOriginal($column))->not->toContain($privateValue);
        expect($record->toArray())->not->toHaveKey($column);
        foreach ([json_encode($record, JSON_THROW_ON_ERROR), print_r($record, true), json_encode($store->load($context), JSON_THROW_ON_ERROR),
            print_r($store->load($context), true), json_encode(new MsGraphOAuthCallbackResult($record, null, null), JSON_THROW_ON_ERROR)] as $diagnostic) {
            expect($diagnostic)->not->toContain($privateValue);
        }
    }
});

it('adds selected storage without binding or modifying existing legacy credentials', function (): void {
    $migration = require __DIR__.'/../../../database/migrations/2026_10_29_000008_add_selected_authorization_grants.php';
    $migration->down();
    $connection = MailboxConnection::query()->create(['name' => 'Legacy configured connection', 'driver' => 'ms-graph',
        'config' => ['configured' => 'retained']]);
    $legacy = MailboxOAuthToken::query()->create(['mailbox_connection_id' => $connection->id, 'provider' => 'ms-graph-user',
        'external_user_id' => 'same-subject', 'access_token' => 'legacy-access', 'refresh_token' => 'legacy-refresh']);

    $migration->up();
    $connection->refresh();
    $legacy->refresh();

    expect($legacy->mailbox_connection_id)->toBe($connection->id)
        ->and($legacy->access_token)->toBe('legacy-access')
        ->and($legacy->refresh_token)->toBe('legacy-refresh')
        ->and($legacy->grant_id)->toBeNull()
        ->and($legacy->grant_revision)->toBeNull()
        ->and($connection->selected_grant_id)->toBeNull()
        ->and($connection->lifecycle_generation)->toBeNull()
        ->and($connection->config)->toBe(['configured' => 'retained']);
    $connection->update(['selected_grant_id' => 'explicit-new-grant', 'lifecycle_generation' => 'generation-a']);
    $context = new GrantContext((string) $connection->id, 'explicit-new-grant', 'generation-a', 'ms-graph', 'same@example.com');
    expect((new EloquentGrantStore)->load($context))->toBeNull();
});

/** @return array{MailboxConnection, GrantContext, StoredGrant, EloquentGrantStore} */
function eloquentGrantFixture(): array
{
    $connection = MailboxConnection::query()->create([
        'name' => 'Selected Microsoft grant', 'driver' => 'ms-graph',
        'selected_grant_id' => 'grant-a', 'lifecycle_generation' => 'generation-a',
    ]);
    $context = new GrantContext((string) $connection->id, 'grant-a', 'generation-a', 'ms-graph', 'shared@example.com');
    $grant = new StoredGrant(new GrantIdentity('ms-graph', 'https://login.microsoftonline.com/tenant/v2.0', 'subject', 'client', 'tenant'),
        'private-access', 'private-refresh', now()->addHour()->toDateTimeImmutable(), ['Mail.Read'], 'revision-a',
        'owner@example.com', ['object_id' => 'verified-object'], hash('sha256', 'nonce'));
    $store = new EloquentGrantStore;
    expect($store->replace($context, null, $grant))->toBeTrue();

    return [$connection, $context, $grant, $store];
}

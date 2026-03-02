<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Pyle\Mailbox\Enums\ConnectionStatus;
use Pyle\Mailbox\Enums\SyncStatus;
use Pyle\Mailbox\Models\Folder;
use Pyle\Mailbox\Models\Mailbox;
use Pyle\Mailbox\Models\MailboxConnection;
use Pyle\Mailbox\Models\MailboxOAuthToken;

it('applies model scopes and casts correctly', function (): void {
    $connection = MailboxConnection::query()->create([
        'name' => 'Primary',
        'driver' => 'ms-graph',
        'status' => ConnectionStatus::CONNECTED,
        'config' => ['key' => 'value'],
        'last_connected_at' => CarbonImmutable::now(),
    ]);

    $mailbox = Mailbox::query()->create([
        'mailbox_connection_id' => $connection->id,
        'email_address' => 'invoices@example.com',
        'is_active' => true,
        'last_synced_at' => CarbonImmutable::now()->subHour(),
    ]);

    Folder::query()->create([
        'mailbox_id' => $mailbox->id,
        'folder_id' => 'inbox',
        'display_name' => 'Inbox',
        'is_active' => true,
        'sync_status' => SyncStatus::IDLE,
        'last_synced_at' => CarbonImmutable::now()->subHour(),
    ]);

    expect(MailboxConnection::query()->active()->count())->toBe(1);
    expect(Mailbox::query()->active()->forEmail('invoices@example.com')->count())->toBe(1);
    expect(Mailbox::query()->stale(30)->count())->toBe(1);
    expect(Folder::query()->needsSync(30)->count())->toBe(1);

    $fresh = $connection->fresh();
    expect($fresh?->status)->toBe(ConnectionStatus::CONNECTED);
    expect($fresh?->config)->toBeArray();
});

it('applies oauth token scopes and encrypted casts correctly', function (): void {
    MailboxOAuthToken::query()->create([
        'provider' => 'ms-graph-user',
        'external_user_id' => 'user-1',
        'email' => 'user1@example.com',
        'access_token' => 'token-1',
        'refresh_token' => 'refresh-1',
        'expires_at' => CarbonImmutable::now()->addMinutes(1),
    ]);

    MailboxOAuthToken::query()->create([
        'provider' => 'ms-graph-user',
        'external_user_id' => 'user-2',
        'email' => 'user2@example.com',
        'access_token' => 'token-2',
        'refresh_token' => 'refresh-2',
        'expires_at' => CarbonImmutable::now()->addHours(2),
        'revoked_at' => CarbonImmutable::now(),
    ]);

    expect(MailboxOAuthToken::query()->provider('ms-graph-user')->count())->toBe(2);
    expect(MailboxOAuthToken::query()->active()->count())->toBe(1);
    expect(MailboxOAuthToken::query()->expiringSoon(120)->count())->toBe(1);

    $token = MailboxOAuthToken::query()->where('external_user_id', 'user-1')->first();
    expect($token?->access_token)->toBe('token-1');
    expect($token?->refresh_token)->toBe('refresh-1');
});

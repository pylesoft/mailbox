<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Pyle\Mailbox\Enums\ConnectionStatus;
use Pyle\Mailbox\Enums\SyncStatus;
use Pyle\Mailbox\Models\MailboxConnection;
use Pyle\Mailbox\Models\MonitoredFolder;
use Pyle\Mailbox\Models\MonitoredMailbox;

it('applies model scopes and casts correctly', function (): void {
    $connection = MailboxConnection::query()->create([
        'name' => 'Primary',
        'driver' => 'ms-graph',
        'status' => ConnectionStatus::CONNECTED,
        'config' => ['key' => 'value'],
        'last_connected_at' => CarbonImmutable::now(),
    ]);

    $mailbox = MonitoredMailbox::query()->create([
        'mailbox_connection_id' => $connection->id,
        'email_address' => 'invoices@example.com',
        'is_active' => true,
        'last_synced_at' => CarbonImmutable::now()->subHour(),
    ]);

    MonitoredFolder::query()->create([
        'monitored_mailbox_id' => $mailbox->id,
        'folder_id' => 'inbox',
        'display_name' => 'Inbox',
        'is_active' => true,
        'sync_status' => SyncStatus::IDLE,
        'last_synced_at' => CarbonImmutable::now()->subHour(),
    ]);

    expect(MailboxConnection::query()->active()->count())->toBe(1);
    expect(MonitoredMailbox::query()->active()->forEmail('invoices@example.com')->count())->toBe(1);
    expect(MonitoredMailbox::query()->stale(30)->count())->toBe(1);
    expect(MonitoredFolder::query()->needsSync(30)->count())->toBe(1);

    $fresh = $connection->fresh();
    expect($fresh?->status)->toBe(ConnectionStatus::CONNECTED);
    expect($fresh?->config)->toBeArray();
});

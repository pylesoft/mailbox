<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Facades\Mailbox as MailboxFacade;
use Pyle\Mailbox\Models\MailboxMessage;
use Pyle\Mailbox\Models\Mailbox;
use Pyle\Mailbox\Services\Folders\FolderLookupService;
use Pyle\Mailbox\Services\Persistence\MessageMoveService;
use Pyle\Mailbox\Services\Persistence\MessageSyncService;

afterEach(function (): void {
    \Mockery::close();
});

it('delegates sync and move APIs through the facade manager', function (): void {
    $mailbox = new Mailbox([
        'mailbox_connection_id' => 1,
        'email_address' => 'service@example.com',
        'is_active' => true,
    ]);

    $message = new MailboxMessage([
        'mailbox_id' => 1,
        'provider_message_id' => 'provider-id',
        'canonical_message_key' => 'provider:provider-id',
        'subject' => 'Subject',
        'is_read' => false,
        'is_draft' => false,
        'has_attachments' => false,
        'importance' => 'normal',
    ]);

    $syncResult = collect([$message]);
    $syncService = \Mockery::mock(MessageSyncService::class);
    $moveService = \Mockery::mock(MessageMoveService::class);

    $syncService->shouldReceive('syncMailbox')
        ->once()
        ->with(
            \Mockery::on(fn (Mailbox $model): bool => $model->email_address === 'service@example.com'),
            ['limit' => 10],
        )
        ->andReturn($syncResult);

    $moveService->shouldReceive('move')
        ->once()
        ->with(
            \Mockery::on(fn (MailboxMessage $model): bool => $model->provider_message_id === 'provider-id'),
            WellKnownFolder::ARCHIVE,
        )
        ->andReturn($message);

    app()->instance(MessageSyncService::class, $syncService);
    app()->instance(MessageMoveService::class, $moveService);

    expect(MailboxFacade::syncMailbox($mailbox, ['limit' => 10]))->toBeInstanceOf(Collection::class)
        ->and(MailboxFacade::moveMessage($message, WellKnownFolder::ARCHIVE))->toBeInstanceOf(MailboxMessage::class);
});

it('delegates folder lookup APIs through the facade manager', function (): void {
    $mailbox = new Mailbox([
        'mailbox_connection_id' => 1,
        'email_address' => 'folders@example.com',
        'is_active' => true,
    ]);

    $tree = collect([
        [
            'id' => 'inbox',
            'display_name' => 'Inbox',
            'path' => 'Inbox',
            'parent_id' => null,
            'child_folder_count' => 1,
        ],
    ]);
    $found = [
        'id' => 'processed',
        'display_name' => 'Processed',
        'path' => 'Inbox/Processed',
        'parent_id' => 'inbox',
        'child_folder_count' => 0,
    ];

    $service = \Mockery::mock(FolderLookupService::class);
    $service->shouldReceive('listTree')
        ->once()
        ->with(
            \Mockery::on(fn (Mailbox $model): bool => $model->email_address === 'folders@example.com'),
            5,
        )
        ->andReturn($tree);

    $service->shouldReceive('findByName')
        ->once()
        ->with(
            \Mockery::on(fn (Mailbox $model): bool => $model->email_address === 'folders@example.com'),
            'Processed',
            WellKnownFolder::INBOX,
            false,
        )
        ->andReturn($found);

    app()->instance(FolderLookupService::class, $service);

    expect(MailboxFacade::listFolderTree($mailbox, 5))->toBeInstanceOf(Collection::class)
        ->and(MailboxFacade::findFolderByName($mailbox, 'Processed', WellKnownFolder::INBOX, false))
        ->toBe($found);
});

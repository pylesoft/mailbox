<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\Models\MailboxAttachment;
use Pyle\Mailbox\Models\MailboxMessage;
use Pyle\Mailbox\Services\Persistence\MessageSyncService;

require_once __DIR__.'/Support/MessagePersistenceTestSupport.php';

afterEach(function (): void {
    \Mockery::close();
});

it('syncs and upserts mailbox messages and attachments using canonical keys', function (): void {
    $mailbox = createTestMailbox(
        connectionName: 'Sync Connection',
        emailAddress: 'sync@example.com',
        displayName: 'Sync Mailbox',
    );

    $firstMessage = testMailboxMessageDto(
        id: 'provider-message-1',
        internetMessageId: '<internet-1@example.com>',
        parentFolderId: 'inbox',
    );

    $secondMessage = testMailboxMessageDto(
        id: 'provider-message-2',
        internetMessageId: '<internet-1@example.com>',
        parentFolderId: 'archive-folder',
    );

    $attachment = new AttachmentDto(
        id: 'attachment-1',
        name: 'invoice.pdf',
        contentType: 'application/pdf',
        size: 1024,
        isInline: false,
        contentId: null,
    );

    $resourceFirst = new TestMailboxResource(
        query: new TestMessageQueryBuilder(collect([$firstMessage])),
        messages: [
            'provider-message-1' => new TestMessageResource(
                dto: $firstMessage,
                attachments: collect([$attachment]),
                streams: ['attachment-1' => 'first-binary-content'],
            ),
        ],
    );

    $resourceSecond = new TestMailboxResource(
        query: new TestMessageQueryBuilder(collect([$secondMessage])),
        messages: [
            'provider-message-2' => new TestMessageResource(
                dto: $secondMessage,
                attachments: collect([$attachment]),
                streams: ['attachment-1' => 'second-binary-content'],
            ),
        ],
    );

    expectMailboxFacadeForMailbox($mailbox, $resourceFirst, $resourceSecond);

    $service = new MessageSyncService;

    $firstPersisted = $service->syncMailbox($mailbox, [
        'folder_reference' => 'wk:inbox',
        'filters' => ['limit' => 25],
    ]);

    expect($firstPersisted)->toHaveCount(1);
    expect(MailboxMessage::query()->count())->toBe(1);
    expect(MailboxAttachment::query()->count())->toBe(1);

    $secondPersisted = $service->syncMailbox($mailbox, [
        'folder_reference' => 'wk:inbox',
        'filters' => ['limit' => 25],
    ]);

    expect($secondPersisted)->toHaveCount(1);
    expect(MailboxMessage::query()->count())->toBe(1);
    expect(MailboxAttachment::query()->count())->toBe(1);

    $storedMessage = MailboxMessage::query()->first();
    expect($storedMessage)->not->toBeNull()
        ->and($storedMessage?->provider_message_id)->toBe('provider-message-2')
        ->and($storedMessage?->canonical_message_key)->toBe('internet:<internet-1@example.com>')
        ->and($storedMessage?->parent_folder_id)->toBe('archive-folder');

    $storedAttachment = MailboxAttachment::query()->first();
    expect($storedAttachment)->not->toBeNull()
        ->and($storedAttachment?->provider_attachment_id)->toBe('attachment-1')
        ->and($storedAttachment?->content_bytes)->toBe(base64_encode('second-binary-content'));

    expect($mailbox->fresh()?->last_synced_at)->not->toBeNull();
});

it('does not resolve message resources for messages without attachments', function (): void {
    $mailbox = createTestMailbox(
        connectionName: 'No Attachment Connection',
        emailAddress: 'no-attachments@example.com',
        displayName: 'No Attachments Mailbox',
    );

    $message = testMailboxMessageDto(
        id: 'provider-message-no-attachments',
        internetMessageId: '<internet-no-attachments@example.com>',
        parentFolderId: 'inbox',
        hasAttachments: false,
    );

    $resource = new TrackingMailboxResource(
        query: new TestMessageQueryBuilder(collect([$message])),
        messages: [],
    );

    expectMailboxFacadeForMailbox($mailbox, $resource);

    $service = new MessageSyncService;
    $persisted = $service->syncMailbox($mailbox, [
        'filters' => ['limit' => 10],
    ]);

    expect($persisted)->toHaveCount(1);
    expect($resource->messageCalls)->toBe(0);
});

it('does not resolve message resources for attachment rules when message has no attachments', function (): void {
    $mailbox = createTestMailbox(
        connectionName: 'Attachment Rule No Metadata Connection',
        emailAddress: 'attachment-rule-no-metadata@example.com',
        displayName: 'Attachment Rule No Metadata Mailbox',
    );

    $message = testMailboxMessageDto(
        id: 'provider-message-attachment-rule',
        internetMessageId: '<internet-attachment-rule@example.com>',
        parentFolderId: 'inbox',
        hasAttachments: false,
    );

    $resource = new TrackingMailboxResource(
        query: new TestMessageQueryBuilder(collect([$message])),
        messages: [
            'provider-message-attachment-rule' => new TestMessageResource(
                dto: $message,
                attachments: collect(),
                streams: [],
            ),
        ],
    );

    expectMailboxFacadeForMailbox($mailbox, $resource);

    $service = new MessageSyncService;
    $persisted = $service->syncMailbox($mailbox, [
        'rule_tree' => [
            'operator' => 'AND',
            'conditions' => [
                ['field' => 'attachmentName', 'operator' => 'contains', 'value' => 'invoice'],
            ],
        ],
        'filters' => ['limit' => 10],
    ]);

    expect($persisted)->toHaveCount(0);
    expect($resource->messageCalls)->toBe(0);
});

it('requires an explicit mailbox connection driver when syncing', function (): void {
    $mailbox = createTestMailbox(
        connectionName: 'Missing Driver Connection',
        emailAddress: 'missing-driver@example.com',
        displayName: 'Missing Driver Mailbox',
        driver: '',
    );

    $service = new MessageSyncService;

    expect(fn (): Collection => $service->syncMailbox($mailbox, [
        'filters' => ['limit' => 10],
    ]))->toThrow(\RuntimeException::class, 'Mailbox connection driver is required.');
});

<?php

declare(strict_types=1);

use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Models\MailboxMessage;
use Pyle\Mailbox\Services\Persistence\MessageMoveService;

require_once __DIR__.'/Support/MessagePersistenceTestSupport.php';

afterEach(function (): void {
    Mockery::close();
});

it('moves a mailbox message and updates provider id and folder metadata', function (): void {
    $mailbox = createTestMailbox(
        connectionName: 'Move Connection',
        emailAddress: 'move@example.com',
        displayName: 'Move Mailbox',
    );

    $message = MailboxMessage::query()->create([
        'mailbox_id' => $mailbox->id,
        'provider_message_id' => 'provider-before-move',
        'canonical_message_key' => 'provider:provider-before-move',
        'internet_message_id' => null,
        'subject' => 'Move test',
        'is_read' => false,
        'is_draft' => false,
        'has_attachments' => false,
        'importance' => 'normal',
    ]);

    $movedDto = testMailboxMessageDto(
        id: 'provider-after-move',
        internetMessageId: null,
        parentFolderId: 'archive-folder',
    );

    $resource = new TestMailboxResource(
        query: new TestMessageQueryBuilder(collect()),
        messages: [
            'provider-before-move' => new TestMessageResource(
                dto: $movedDto,
                attachments: collect(),
                streams: [],
            ),
        ],
    );

    expectMailboxFacadeForMailbox($mailbox, $resource);

    $service = new MessageMoveService;
    $moved = $service->move($message, WellKnownFolder::ARCHIVE);

    expect($moved->provider_message_id)->toBe('provider-after-move')
        ->and($moved->canonical_message_key)->toBe('provider:provider-after-move')
        ->and($moved->parent_folder_id)->toBe('archive-folder');
});

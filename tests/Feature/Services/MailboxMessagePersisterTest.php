<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\Services\Persistence\MailboxMessagePersister;

require_once __DIR__.'/Support/MessagePersistenceTestSupport.php';

it('persists message fields and attachment streams', function (): void {
    $mailbox = createTestMailbox(
        connectionName: 'Persister Connection',
        emailAddress: 'attachments@example.com',
        displayName: 'Attachments Mailbox',
    );

    $message = testMailboxMessageDto(
        id: 'provider-1',
        internetMessageId: '<internet-1@example.com>',
        parentFolderId: 'inbox',
    );

    $resource = new TestMessageResource(
        $message,
        collect([
            new AttachmentDto('att-1', 'invoice.pdf', 'application/pdf', 512, false, null),
            new AttachmentDto('', 'skip-me.txt', 'text/plain', 10, false, null),
            new AttachmentDto('att-2', 'logo.png', 'image/png', 128, true, 'cid:logo'),
        ]),
        [
            'att-1' => 'pdf-bytes',
            'att-2' => 'png-bytes',
        ],
    );

    $mailboxResource = new TrackingMailboxResource(
        new TestMessageQueryBuilder(collect([$message])),
        ['provider-1' => $resource],
    );

    $stored = (new MailboxMessagePersister)->upsert($mailboxResource, $mailbox->id, $message);

    expect($mailboxResource->messageCalls)->toBe(1);
    expect($stored->canonical_message_key)->toBe('internet:<internet-1@example.com>');
    expect($stored->provider_message_id)->toBe('provider-1');
    expect($stored->internet_message_id)->toBe('<internet-1@example.com>');
    expect($stored->parent_folder_id)->toBe('inbox');
    expect($stored->from_address)->toBe([
        'name' => 'Sender',
        'address' => 'sender@example.com',
    ]);
    expect($stored->to_recipients)->toBe([
        [
            'name' => 'Receiver',
            'address' => 'receiver@example.com',
        ],
    ]);
    expect($stored->attachments)->toHaveCount(2);
    expect($stored->attachments->pluck('provider_attachment_id')->all())->toBe(['att-1', 'att-2']);
    expect($stored->attachments->firstWhere('provider_attachment_id', 'att-1')?->content_bytes)->toBe(base64_encode('pdf-bytes'));
    expect($stored->attachments->firstWhere('provider_attachment_id', 'att-2')?->content_bytes)->toBe(base64_encode('png-bytes'));
});

it('skips attachment hydration when disabled and clears stored attachments when a message no longer has them', function (): void {
    $mailbox = createTestMailbox(
        connectionName: 'Persister Cleanup Connection',
        emailAddress: 'cleanup@example.com',
        displayName: 'Cleanup Mailbox',
    );

    $persister = new MailboxMessagePersister;
    $messageWithAttachments = testMailboxMessageDto(
        id: 'provider-2',
        internetMessageId: null,
        parentFolderId: 'inbox',
    );

    $initialResource = new TrackingMailboxResource(
        new TestMessageQueryBuilder(collect([$messageWithAttachments])),
        [
            'provider-2' => new TestMessageResource(
                $messageWithAttachments,
                collect([
                    new AttachmentDto('att-1', 'statement.pdf', 'application/pdf', 1024, false, null),
                ]),
                ['att-1' => 'statement-bytes'],
            ),
        ],
    );

    $persisted = $persister->upsert($initialResource, $mailbox->id, $messageWithAttachments);
    expect($persisted->canonical_message_key)->toBe('provider:provider-2');
    expect($persisted->attachments)->toHaveCount(1);

    $disabledResource = new TrackingMailboxResource(
        new TestMessageQueryBuilder(collect([$messageWithAttachments])),
        [
            'provider-2' => new TestMessageResource(
                $messageWithAttachments,
                collect([
                    new AttachmentDto('att-2', 'replacement.pdf', 'application/pdf', 2048, false, null),
                ]),
                ['att-2' => 'replacement-bytes'],
            ),
        ],
    );

    $unchanged = $persister->upsert(
        $disabledResource,
        $mailbox->id,
        $messageWithAttachments,
        persistAttachments: false,
    );

    expect($disabledResource->messageCalls)->toBe(0);
    expect($unchanged->attachments)->toHaveCount(1);
    expect($unchanged->attachments->first()?->provider_attachment_id)->toBe('att-1');

    $messageWithoutAttachments = testMailboxMessageDto(
        id: 'provider-2',
        internetMessageId: null,
        parentFolderId: 'archive',
        hasAttachments: false,
    );

    $cleared = $persister->upsert(
        new TrackingMailboxResource(new TestMessageQueryBuilder(collect([$messageWithoutAttachments])), []),
        $mailbox->id,
        $messageWithoutAttachments,
    );

    expect($cleared->parent_folder_id)->toBe('archive');
    expect($cleared->attachments)->toHaveCount(0);
});

it('does not retain raw attachment bytes while updating an existing attachment', function (): void {
    $attachmentSize = 12_931_662;
    $attachmentHash = '77896f2637288f1e096ac120f652ce5a4339593eb0409e8555e4c4eae68e9409';
    $providerStreamMemory = null;
    $attachmentSelectMemory = null;

    $mailbox = createTestMailbox(
        connectionName: 'Attachment Memory Connection',
        emailAddress: 'memory@example.com',
        displayName: 'Memory Mailbox',
    );
    $mailbox->getConnection()->beforeExecuting(function (string $sql) use (&$attachmentSelectMemory): void {
        $sql = strtolower(ltrim($sql));

        if (
            str_starts_with($sql, 'select')
            && str_contains($sql, 'mailbox_attachments')
            && str_contains($sql, 'provider_attachment_id')
        ) {
            $attachmentSelectMemory = memory_get_usage();
        }
    });

    $message = testMailboxMessageDto(
        id: 'provider-memory',
        internetMessageId: '<internet-memory@example.com>',
        parentFolderId: 'inbox',
    );
    $resource = new TestMessageResource(
        $message,
        collect([new AttachmentDto('att-large', 'large.bin', 'application/octet-stream', $attachmentSize, false, null)]),
        [
            'att-large' => function () use (&$providerStreamMemory, $attachmentSize): StreamInterface {
                $stream = Utils::streamFor(str_repeat('A', $attachmentSize));
                $providerStreamMemory = memory_get_usage();

                return $stream;
            },
        ],
    );
    $mailboxResource = new TrackingMailboxResource(
        new TestMessageQueryBuilder(collect([$message])),
        ['provider-memory' => $resource],
    );
    $persister = new MailboxMessagePersister;

    $first = $persister->upsert($mailboxResource, $mailbox->id, $message);
    unset($first);
    gc_collect_cycles();
    $providerStreamMemory = null;
    $attachmentSelectMemory = null;

    $stored = $persister->upsert($mailboxResource, $mailbox->id, $message);
    $contentBytes = $stored->attachments->first()?->content_bytes;
    $expectedContentBytesSize = 4 * intdiv($attachmentSize + 2, 3);

    expect($providerStreamMemory)->toBeInt();
    expect($attachmentSelectMemory)->toBeInt();
    expect($attachmentSelectMemory - $providerStreamMemory)->toBeLessThan($expectedContentBytesSize + 2_000_000);
    expect($contentBytes)->toBeString();
    expect(strlen($contentBytes))->toBe($expectedContentBytesSize);
    expect(strlen(base64_decode($contentBytes, true)))->toBe($attachmentSize);
    expect(hash('sha256', base64_decode($contentBytes, true)))->toBe($attachmentHash);
});

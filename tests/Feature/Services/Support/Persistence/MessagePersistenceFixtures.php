<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\DTOs\BodyDto;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\ConnectionStatus;
use Pyle\Mailbox\Enums\Importance;
use Pyle\Mailbox\Facades\Mailbox as MailboxFacade;
use Pyle\Mailbox\Models\Mailbox;
use Pyle\Mailbox\Models\MailboxConnection;

function createTestMailbox(
    string $connectionName,
    string $emailAddress,
    string $displayName,
    string $driver = 'ms-graph',
): Mailbox {
    $connection = MailboxConnection::query()->create([
        'name' => $connectionName,
        'driver' => $driver,
        'status' => ConnectionStatus::CONNECTED,
        'config' => [],
    ]);

    return Mailbox::query()->create([
        'mailbox_connection_id' => $connection->id,
        'email_address' => $emailAddress,
        'display_name' => $displayName,
        'is_active' => true,
    ]);
}

function expectMailboxFacadeForMailbox(Mailbox $mailbox, MailboxResource ...$resources): void
{
    MailboxFacade::shouldReceive('forMailbox')
        ->times(count($resources))
        ->with(Mockery::on(fn (Mailbox $model): bool => $model->is($mailbox)))
        ->andReturn(...$resources);
}

function testMailboxMessageDto(
    string $id,
    ?string $internetMessageId,
    ?string $parentFolderId,
    bool $hasAttachments = true,
    string $fromAddress = 'sender@example.com',
): MessageDto {
    return new MessageDto(
        id: $id,
        subject: 'Invoice',
        bodyPreview: 'Preview',
        body: new BodyDto(contentType: 'text', content: 'Body'),
        from: new EmailAddressDto(name: 'Sender', address: $fromAddress),
        sender: new EmailAddressDto(name: 'Sender', address: $fromAddress),
        toRecipients: [new EmailAddressDto(name: 'Receiver', address: 'receiver@example.com')],
        ccRecipients: [],
        bccRecipients: [],
        receivedAt: CarbonImmutable::parse('2026-03-01 11:00:00', 'UTC'),
        sentAt: CarbonImmutable::parse('2026-03-01 10:59:00', 'UTC'),
        isRead: false,
        isDraft: false,
        hasAttachments: $hasAttachments,
        importance: Importance::NORMAL,
        conversationId: 'conversation-1',
        internetMessageId: $internetMessageId,
        parentFolderId: $parentFolderId,
        raw: ['id' => $id],
    );
}

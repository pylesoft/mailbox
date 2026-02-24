<?php

declare(strict_types=1);

use Pyle\Mailbox\DTOs\MessageDto;

function msgraphMessageFixture(): array
{
    return [
        'id' => 'AAMkAG',
        'subject' => 'Invoice #1234',
        'bodyPreview' => 'Please see attached.',
        'from' => ['emailAddress' => ['name' => 'Vendor', 'address' => 'vendor@example.com']],
        'sender' => ['emailAddress' => ['name' => 'Vendor', 'address' => 'vendor@example.com']],
        'toRecipients' => [['emailAddress' => ['name' => 'Finance', 'address' => 'finance@example.com']]],
        'ccRecipients' => [],
        'bccRecipients' => [],
        'receivedDateTime' => '2026-01-01T12:00:00Z',
        'sentDateTime' => '2026-01-01T11:55:00Z',
        'isRead' => false,
        'isDraft' => false,
        'hasAttachments' => true,
        'importance' => 'normal',
        'conversationId' => 'abc123',
        'internetMessageId' => '<msg-123@example.com>',
        'parentFolderId' => 'inbox',
    ];
}

function messageDto(string $subject = 'Hello'): MessageDto
{
    $data = msgraphMessageFixture();
    $data['subject'] = $subject;

    return MessageDto::fromMsGraph($data);
}

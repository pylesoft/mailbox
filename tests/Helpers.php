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

function gmailMessageFixture(array $overrides = []): array
{
    $base = [
        'id' => 'gmail-msg-1',
        'threadId' => 'gmail-thread-1',
        'labelIds' => ['INBOX', 'UNREAD'],
        'snippet' => 'Invoice body preview',
        'internalDate' => '1735732800000',
        'payload' => [
            'mimeType' => 'multipart/alternative',
            'headers' => [
                ['name' => 'Subject', 'value' => 'Invoice #5678'],
                ['name' => 'From', 'value' => 'Vendor <vendor@example.com>'],
                ['name' => 'To', 'value' => 'Finance <finance@example.com>'],
                ['name' => 'Date', 'value' => 'Wed, 01 Jan 2026 12:00:00 +0000'],
                ['name' => 'Message-ID', 'value' => '<gmail-5678@example.com>'],
            ],
            'parts' => [
                [
                    'mimeType' => 'text/plain',
                    'body' => [
                        'data' => rtrim(strtr(base64_encode('Invoice details here'), '+/', '-_'), '='),
                    ],
                ],
            ],
        ],
    ];

    return array_replace_recursive($base, $overrides);
}

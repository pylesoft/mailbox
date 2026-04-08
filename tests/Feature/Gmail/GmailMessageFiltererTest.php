<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\Gmail\GmailMessageFilterer;
use Pyle\Mailbox\DTOs\MessageDto;

it('applies single and any filters against dto fields and raw payload values', function (): void {
    $messages = collect([
        gmailFiltererMessage('m-1', 'Invoice April', 'Vendor <vendor@example.com>', ['customTag' => 'finance']),
        gmailFiltererMessage('m-2', 'Invoice April', 'Ops <ops@example.com>', ['customTag' => 'finance']),
        gmailFiltererMessage('m-3', 'Newsletter', 'Vendor <vendor@example.com>', ['customTag' => 'marketing']),
    ]);

    $filterer = new GmailMessageFilterer([
        [
            'type' => 'single',
            'field' => 'subject',
            'operator' => 'contains',
            'value' => 'invoice',
        ],
        [
            'type' => 'any',
            'field' => 'from.address',
            'operator' => 'contains',
            'values' => ['vendor@example.com', 'billing@example.com'],
        ],
        [
            'type' => 'single',
            'field' => 'customTag',
            'operator' => 'eq',
            'value' => 'finance',
        ],
    ]);

    expect($filterer->apply($messages)->pluck('id')->all())->toBe(['m-1']);
});

it('supports shorthand equality filters and scalar comparisons', function (): void {
    $message = gmailFiltererMessage('m-1', 'Invoice Alpha', 'Vendor <vendor@example.com>');

    $matches = (new GmailMessageFilterer([
        [
            'type' => 'single',
            'field' => 'isRead',
            'operator' => false,
            'value' => null,
        ],
        [
            'type' => 'single',
            'field' => 'to.address',
            'operator' => 'contains',
            'value' => 'finance@example.com',
        ],
        [
            'type' => 'single',
            'field' => 'receivedAt',
            'operator' => '>=',
            'value' => '2025-01-01T00:00:00+00:00',
        ],
    ]))->apply(collect([$message]));

    $futureOnly = (new GmailMessageFilterer([
        [
            'type' => 'single',
            'field' => 'receivedAt',
            'operator' => '>',
            'value' => '2030-01-01T00:00:00+00:00',
        ],
    ]))->apply(collect([$message]));

    expect($matches)->toHaveCount(1);
    expect($futureOnly)->toHaveCount(0);
});

function gmailFiltererMessage(string $id, string $subject, string $from, array $overrides = []): MessageDto
{
    return MessageDto::fromGmail(gmailMessageFixture(array_replace_recursive([
        'id' => $id,
        'payload' => [
            'headers' => [
                ['name' => 'Subject', 'value' => $subject],
                ['name' => 'From', 'value' => $from],
                ['name' => 'To', 'value' => 'Finance <finance@example.com>'],
                ['name' => 'Date', 'value' => 'Wed, 01 Jan 2025 12:00:00 +0000'],
                ['name' => 'Message-ID', 'value' => sprintf('<%s@example.com>', $id)],
            ],
            'parts' => [
                [
                    'mimeType' => 'text/plain',
                    'body' => [
                        'data' => rtrim(strtr(base64_encode('Invoice details'), '+/', '-_'), '='),
                    ],
                ],
            ],
        ],
        'labelIds' => ['INBOX', 'UNREAD'],
        'internalDate' => '1735732800000',
    ], $overrides)));
}

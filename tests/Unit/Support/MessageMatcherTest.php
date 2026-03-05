<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\Support\MessageMatcher;

it('evaluates simple contains condition', function (): void {
    $matcher = new MessageMatcher([
        'operator' => 'AND',
        'conditions' => [
            ['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice'],
        ],
    ]);

    expect($matcher->matches(messageDto(subject: 'Your invoice #123')))->toBeTrue();
    expect($matcher->matches(messageDto(subject: 'Hello world')))->toBeFalse();
});

it('evaluates nested groups', function (): void {
    $matcher = new MessageMatcher([
        'operator' => 'AND',
        'conditions' => [
            ['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice'],
            [
                'operator' => 'OR',
                'conditions' => [
                    ['field' => 'from.address', 'operator' => 'contains', 'value' => 'vendor'],
                    ['field' => 'from.address', 'operator' => 'contains', 'value' => 'billing'],
                ],
            ],
        ],
    ]);

    expect($matcher->matches(messageDto(subject: 'Invoice ready')))->toBeTrue();
});

dataset('matcherOperators', [
    'equals' => ['equals', 'Hello', 'hello', true],
    'not_equals' => ['not_equals', 'Hello', 'World', true],
    'contains' => ['contains', 'Alpha Beta', 'beta', true],
    'not_contains' => ['not_contains', 'Alpha Beta', 'gamma', true],
    'starts_with' => ['starts_with', 'Alpha Beta', 'alpha', true],
    'ends_with' => ['ends_with', 'Alpha Beta', 'beta', true],
    'matches_regex' => ['matches_regex', 'CONFIDENTIAL: secret', '/^CONFIDENTIAL/', true],
    'greater_than' => ['greater_than', 20, 10, true],
    'less_than' => ['less_than', 10, 20, true],
]);

it('evaluates matcher operators', function (string $operator, mixed $actual, mixed $expected, bool $result): void {
    $matcher = new MessageMatcher([
        'operator' => 'AND',
        'conditions' => [
            ['field' => 'subject', 'operator' => $operator, 'value' => $expected],
        ],
    ]);

    $message = messageDto(subject: is_string($actual) ? $actual : 'placeholder');

    if (is_int($actual)) {
        $data = msgraphMessageFixture();
        $data['subject'] = 'ignored';
        $data['raw'] = ['subject' => $actual];
        $message = messageDto();
        $message = new \Pyle\Mailbox\DTOs\MessageDto(
            id: $message->id,
            subject: (string) $actual,
            bodyPreview: $message->bodyPreview,
            body: $message->body,
            from: $message->from,
            sender: $message->sender,
            toRecipients: $message->toRecipients,
            ccRecipients: $message->ccRecipients,
            bccRecipients: $message->bccRecipients,
            receivedAt: $message->receivedAt,
            sentAt: $message->sentAt,
            isRead: $message->isRead,
            isDraft: $message->isDraft,
            hasAttachments: $message->hasAttachments,
            importance: $message->importance,
            conversationId: $message->conversationId,
            internetMessageId: $message->internetMessageId,
            parentFolderId: $message->parentFolderId,
            raw: $message->raw,
        );
    }

    expect($matcher->matches($message))->toBe($result);
})->with('matcherOperators');

it('evaluates between for datetimes', function (): void {
    $matcher = new MessageMatcher([
        'operator' => 'AND',
        'conditions' => [
            [
                'field' => 'receivedAt',
                'operator' => 'between',
                'value' => [CarbonImmutable::parse('2025-01-01T00:00:00Z'), CarbonImmutable::parse('2027-01-01T00:00:00Z')],
            ],
        ],
    ]);

    expect($matcher->matches(messageDto()))->toBeTrue();
});

it('evaluates attachment conditions', function (): void {
    $matcher = new MessageMatcher([
        'operator' => 'AND',
        'conditions' => [
            ['field' => 'attachmentCount', 'operator' => 'greater_than', 'value' => 1],
            ['field' => 'attachmentExtension', 'operator' => 'equals', 'value' => 'pdf'],
            ['field' => 'attachmentName', 'operator' => 'ends_with', 'value' => '.pdf'],
            ['field' => 'attachmentSize', 'operator' => 'less_than', 'value' => 10_000],
        ],
    ]);

    $attachments = [
        new AttachmentDto('a1', 'invoice.pdf', 'application/pdf', 1200, false, null),
        new AttachmentDto('a2', 'note.txt', 'text/plain', 500, false, null),
    ];

    expect($matcher->matches(messageDto(), $attachments))->toBeTrue();
});

it('evaluates attachment extension from mime type when filename has no extension', function (): void {
    $matcher = new MessageMatcher([
        'operator' => 'AND',
        'conditions' => [
            ['field' => 'attachmentExtension', 'operator' => 'equals', 'value' => 'pdf'],
        ],
    ]);

    $attachments = [
        new AttachmentDto('a1', 'invoice', 'application/pdf', 1200, false, null),
    ];

    expect($matcher->matches(messageDto(), $attachments))->toBeTrue();
});

it('evaluates attachment extension from non-pdf mime type when filename has no extension', function (): void {
    $matcher = new MessageMatcher([
        'operator' => 'AND',
        'conditions' => [
            ['field' => 'attachmentExtension', 'operator' => 'equals', 'value' => 'png'],
        ],
    ]);

    $attachments = [
        new AttachmentDto('a1', 'diagram', 'image/png', 1200, false, null),
    ];

    expect($matcher->matches(messageDto(), $attachments))->toBeTrue();
});

it('evaluates attachment extension from mime type with parameters', function (): void {
    $matcher = new MessageMatcher([
        'operator' => 'AND',
        'conditions' => [
            ['field' => 'attachmentExtension', 'operator' => 'equals', 'value' => 'json'],
        ],
    ]);

    $attachments = [
        new AttachmentDto('a1', 'payload', 'application/json; charset=utf-8', 1200, false, null),
    ];

    expect($matcher->matches(messageDto(), $attachments))->toBeTrue();
});

<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Pyle\Mailbox\Contracts\SupportsDraftAttachmentPreparation;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\PreparedFile;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\Enums\PreparationOutcome;
use Pyle\Mailbox\Enums\SubmissionOutcome;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('attaches a chosen small file to the acknowledged immutable draft without sending', function (): void {
    $history = [];
    $payload = null;
    $mailbox = graphAttachmentMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        static function (RequestInterface $request) use (&$payload): Response {
            $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);

            return new Response(201, ['request-id' => 'attach-1'], '{"id":"attachment-1"}');
        },
    ], $history);
    $file = new PreparedFile('host-file-1', 'résumé.txt', 'text/plain', 5,
        static fn () => Utils::streamFor('hello'), hash('sha256', 'hello'), true, 'chosen-cid');
    $message = graphAttachmentMessage([$file]);

    $draft = $mailbox->prepareSubmission($message);
    expect($draft->outcome)->toBe(PreparationOutcome::Preparing)
        ->and($draft->draftId)->toBe('immutable-draft=')
        ->and($history)->toHaveCount(1);

    $prepared = $mailbox->prepareAttachments(unserialize(serialize($draft)), $message);

    expect($prepared->outcome)->toBe(PreparationOutcome::Prepared)
        ->and($prepared->draftId)->toBe($draft->draftId)
        ->and($prepared->attachments)->toHaveCount(1)
        ->and($prepared->attachments[0]->fileIdentity)->toBe('host-file-1')
        ->and($prepared->attachments[0]->acknowledgedBytes)->toBe(5)
        ->and($prepared->attachments[0]->attachmentId)->toBe('attachment-1')
        ->and($prepared->attachments[0]->headers)->toBe(['request-id' => ['attach-1']])
        ->and($history)->toHaveCount(2)
        ->and($history[1]['request']->getMethod())->toBe('POST')
        ->and($history[1]['request']->getUri()->getPath())->toBe('/v1.0/users/sender%40example.com/messages/immutable-draft%3D/attachments')
        ->and($payload)->toBe([
            '@odata.type' => '#microsoft.graph.fileAttachment', 'name' => 'résumé.txt', 'contentType' => 'text/plain',
            'isInline' => true, 'contentId' => 'chosen-cid', 'contentBytes' => 'aGVsbG8=',
        ]);
});

it('inspects the original draft and exact file bytes after a lost attachment acknowledgement without adding another copy', function (): void {
    $history = [];
    $attachment = ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'observed-file=',
        'name' => 'chosen.txt', 'contentType' => 'text/plain', 'size' => 5, 'isInline' => false, 'contentId' => null];
    $draft = graphAttachmentDraft([$attachment]);
    $mailbox = graphAttachmentMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        new ConnectException('private-upload-secret', new Request('POST', 'https://private-upload-secret')),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)), new Response(200, [], 'hello'),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)), new Response(200, [], 'hello'), new Response(202),
    ], $history);
    $file = new PreparedFile('chosen-file', 'chosen.txt', 'text/plain', 5,
        static fn () => Utils::streamFor('hello'), hash('sha256', 'hello'));
    $message = graphAttachmentMessage([$file]);
    $uncertain = $mailbox->prepareAttachments($mailbox->prepareSubmission($message), $message);

    expect($uncertain->outcome)->toBe(PreparationOutcome::Uncertain)
        ->and($uncertain->draftId)->toBe('immutable-draft=')
        ->and(fn () => $mailbox->prepareAttachments($uncertain, $message))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $mailbox->submitPrepared($uncertain, $message))->toThrow(InvalidArgumentException::class)
        ->and($history)->toHaveCount(2);
    $observed = $mailbox->inspectAttachments(unserialize(serialize($uncertain)), $message);

    expect($observed->outcome)->toBe(PreparationOutcome::Prepared)
        ->and($observed->attachments[0]->attachmentId)->toBe('observed-file=')
        ->and($observed->attachments[0]->acknowledgedBytes)->toBe(5)
        ->and($uncertain->outcome)->toBe(PreparationOutcome::Uncertain)
        ->and($history)->toHaveCount(4);
    $result = $mailbox->submitPrepared($observed, $message);
    expect($result->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($history)->toHaveCount(7)
        ->and(array_map(static fn ($entry): string => $entry['request']->getMethod(), $history))
        ->toBe(['POST', 'POST', 'GET', 'GET', 'GET', 'GET', 'POST'])
        ->and($history[3]['request']->getUri()->getPath())->toEndWith('/messages/immutable-draft%3D/attachments/observed-file%3D/$value');
});

it('rejects a same length changed source after a retained acknowledged prefix before any further request', function (): void {
    $history = [];
    $bytes = str_repeat('a', 3_000_001);
    $expires = (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM);
    $mailbox = graphAttachmentMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(201, [], json_encode(['uploadUrl' => graphAttachmentUploadUrl(), 'expirationDateTime' => $expires, 'nextExpectedRanges' => ['0-']], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['expirationDateTime' => $expires, 'nextExpectedRanges' => ['3000000']], JSON_THROW_ON_ERROR)),
        new Response(201, ['Location' => 'https://unexpected.example/duplicate']),
    ], $history);
    $file = new PreparedFile('mutable-host-file', 'chosen.bin', 'application/octet-stream', strlen($bytes),
        static function () use (&$bytes) { return Utils::streamFor($bytes); }, hash('sha256', $bytes));
    $message = graphAttachmentMessage([$file]);
    $session = $mailbox->prepareAttachments($mailbox->prepareSubmission($message), $message);
    $prefix = $mailbox->prepareAttachments($session, $message);
    $bytes[0] = 'b';

    expect($prefix->attachments[0]->acknowledgedBytes)->toBe(3_000_000)
        ->and(fn () => $mailbox->prepareAttachments(unserialize(serialize($prefix)), $message))->toThrow(MailboxException::class)
        ->and(fn () => $mailbox->submitPrepared($prefix, $message))->toThrow(InvalidArgumentException::class)
        ->and($history)->toHaveCount(3);
});

it('retains each acknowledged Outlook upload step before sending another sequential range', function (): void {
    $history = [];
    $ranges = [];
    $url = "https://outlook.office.com/api/v2.0/Users('provider-user')/Messages('provider-draft')/AttachmentSessions('session-1')?authtoken=private-upload-secret";
    $expires = (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM);
    $bytes = str_repeat('a', 3_000_000).'z';
    $mailbox = graphAttachmentMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(201, [], json_encode(['uploadUrl' => $url, 'expirationDateTime' => $expires, 'nextExpectedRanges' => ['0-']], JSON_THROW_ON_ERROR)),
        static function (RequestInterface $request) use (&$ranges, $expires): Response {
            $ranges[] = ['range' => $request->getHeaderLine('Content-Range'), 'bytes' => (string) $request->getBody()];

            return new Response(200, [], json_encode(['ExpirationDateTime' => $expires, 'nextExpectedRanges' => ['3000000']], JSON_THROW_ON_ERROR));
        },
        static function (RequestInterface $request) use (&$ranges): Response {
            $ranges[] = ['range' => $request->getHeaderLine('Content-Range'), 'bytes' => (string) $request->getBody()];

            return new Response(201, ['Location' => "https://outlook.office.com/api/v2.0/Users('provider-user')/Messages('provider-draft')/Attachments('large-attachment=')"]);
        },
    ], $history);
    $file = new PreparedFile('large-host-file', 'chosen.bin', 'application/octet-stream', strlen($bytes),
        static fn () => Utils::streamFor($bytes), hash('sha256', $bytes));
    $message = graphAttachmentMessage([$file]);
    $draft = $mailbox->prepareSubmission($message);

    $session = $mailbox->prepareAttachments($draft, $message);
    expect($session->outcome)->toBe(PreparationOutcome::Preparing)
        ->and($session->attachments[0]->acknowledgedBytes)->toBe(0)
        ->and($history)->toHaveCount(2);
    $prefix = $mailbox->prepareAttachments(unserialize(serialize($session)), $message);
    expect($prefix->outcome)->toBe(PreparationOutcome::Preparing)
        ->and($prefix->attachments[0]->acknowledgedBytes)->toBe(3_000_000)
        ->and($history)->toHaveCount(3);
    $complete = $mailbox->prepareAttachments(unserialize(serialize($prefix)), $message);

    expect($complete->outcome)->toBe(PreparationOutcome::Prepared)
        ->and($complete->draftId)->toBe($draft->draftId)
        ->and($complete->attachments[0]->acknowledgedBytes)->toBe(3_000_001)
        ->and($complete->attachments[0]->attachmentId)->toBe('large-attachment=')
        ->and($complete->attachments[0]->headers)->toBe([])
        ->and($ranges)->toBe([
            ['range' => 'bytes 0-2999999/3000001', 'bytes' => str_repeat('a', 3_000_000)],
            ['range' => 'bytes 3000000-3000000/3000001', 'bytes' => 'z'],
        ])
        ->and($history)->toHaveCount(4);
    foreach (array_slice($history, 2) as $entry) {
        expect($entry['request']->getMethod())->toBe('PUT')
            ->and((string) $entry['request']->getUri())->toBe($url)
            ->and($entry['request']->hasHeader('Authorization'))->toBeFalse()
            ->and($entry['request']->getHeaderLine('Content-Type'))->toBe('application/octet-stream')
            ->and($entry['options']['allow_redirects'])->toBeFalse()
            ->and($entry['options']['debug'])->toBeFalse();
    }
    foreach ([$session, $prefix, $complete] as $state) {
        $public = json_encode($state, JSON_THROW_ON_ERROR).print_r($state, true);
        expect($public)->not->toContain('private-upload-secret');
        expect($public)->not->toContain('outlook.office.com');
        expect($public)->not->toContain('private-token');
    }
});

/** @param list<PreparedFile> $files */
function graphAttachmentMessage(array $files): PreparedMessage
{
    return new PreparedMessage(new EmailAddressDto('Sender', 'sender@example.com'),
        [new EmailAddressDto('Recipient', 'to@example.com')], 'Chosen subject', 'attachment-operation', textBody: 'Chosen body', files: $files);
}

function graphAttachmentUploadUrl(): string
{
    return "https://outlook.office.com/api/v2.0/Users('provider-user')/Messages('provider-draft')/AttachmentSessions('session-1')?authtoken=private-upload-secret";
}

/**
 * @param list<array<string, mixed>> $attachments
 * @return array<string, mixed>
 */
function graphAttachmentDraft(array $attachments): array
{
    return ['id' => 'immutable-draft=', 'isDraft' => true, 'hasAttachments' => $attachments !== [], 'attachments' => $attachments,
        'from' => ['emailAddress' => ['name' => 'Sender', 'address' => 'sender@example.com']],
        'toRecipients' => [['emailAddress' => ['name' => 'Recipient', 'address' => 'to@example.com']]],
        'ccRecipients' => [], 'bccRecipients' => [], 'subject' => 'Chosen subject',
        'body' => ['contentType' => 'text', 'content' => 'Chosen body'],
        'internetMessageHeaders' => [['name' => 'X-Mailbox-Correlation-Id', 'value' => 'attachment-operation']]];
}

/**
 * @param list<mixed> $responses
 * @param array<int, mixed> $history
 */
function graphAttachmentMailbox(array $responses, array &$history): SupportsDraftAttachmentPreparation
{
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::history($history));

    return Mailbox::forGrant(new GrantContext('connection', 'grant', '1', 'ms-graph', 'sender@example.com'),
        new TestGrantTokenProvider(['grant' => ['connection' => 'connection', 'generation' => '1',
            'mailboxes' => ['sender@example.com'], 'token' => 'private-token']]), new Client(['handler' => $handler]));
}

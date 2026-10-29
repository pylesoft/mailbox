<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Pyle\Mailbox\Contracts\SupportsDraftAttachmentPreparation;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\PreparedFile;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\Enums\PreparationOutcome;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('attaches a chosen small file to the acknowledged immutable draft without sending', function (): void {
    $history = [];
    $mailbox = graphAttachmentMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(201, ['request-id' => 'attach-1'], '{"id":"attachment-1"}'),
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
        ->and(json_decode((string) $history[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            '@odata.type' => '#microsoft.graph.fileAttachment', 'name' => 'résumé.txt', 'contentType' => 'text/plain',
            'isInline' => true, 'contentId' => 'chosen-cid', 'contentBytes' => 'aGVsbG8=',
        ]);
});

/** @param list<PreparedFile> $files */
function graphAttachmentMessage(array $files): PreparedMessage
{
    return new PreparedMessage(new EmailAddressDto('Sender', 'sender@example.com'),
        [new EmailAddressDto('Recipient', 'to@example.com')], 'Chosen subject', 'attachment-operation', textBody: 'Chosen body', files: $files);
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

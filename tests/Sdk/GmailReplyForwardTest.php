<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\ReplyForwardContext;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Enums\ReplyForwardOperation;
use Pyle\Mailbox\Enums\SubmissionOutcome;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;
use ZBateson\MailMimeParser\MailMimeParser;

it('submits a Gmail reply in its complete selected thread with only caller chosen recipients and content', function (): void {
    $history = [];
    $mailbox = gmailContextMailbox([
        new Response(200, [], json_encode(['id' => 'thread', 'messages' => [gmailContextSource()]], JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"id":"sent","threadId":"thread"}'),
    ], $history);
    $conversation = $mailbox->conversation('thread', new SyncScope('INBOX'), new SyncBudget);
    $message = gmailContextMessage(new ReplyForwardContext(ReplyForwardOperation::Reply, $conversation, 'source'));

    $result = $mailbox->submit($message);

    expect($conversation->complete)->toBeTrue()
        ->and($conversation->messages[0]->subject)->toBe('Réunion à Montréal')
        ->and($result->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($result->correlationId)->toBe('reply-operation')
        ->and($result->threadId)->toBe('thread')
        ->and($history)->toHaveCount(2);
    $payload = json_decode((string) $history[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['threadId'] ?? null)->toBe('thread');
    $rawMime = base64_decode(strtr($payload['raw'], '-_', '+/'), true);
    $mime = (new MailMimeParser)->parse($rawMime, false);
    expect($mime->getHeaderValue('Subject'))->toBe('Réunion à Montréal')
        ->and($mime->getHeaderValue('In-Reply-To'))->toBe('<source@example.com>')
        ->and($mime->getHeaderValue('References'))->toBe('<ancestor@example.com> <source@example.com>')
        ->and($mime->getTextContent())->toBe('Only the chosen reply')
        ->and($mime->getHeader('To')->getAddresses()[0]->getEmail())->toBe('chosen@example.com')
        ->and($mime->getHeader('Bcc')->getAddresses()[0]->getEmail())->toBe('chosen-private@example.com')
        ->and($rawMime)->not->toContain('old-private@example.com');
});

/** @return array<string, mixed> */
function gmailContextSource(): array
{
    return ['id' => 'source', 'threadId' => 'thread', 'payload' => [
        'mimeType' => 'text/plain', 'headers' => [
            ['name' => 'Subject', 'value' => '=?UTF-8?Q?R=C3=A9union_=C3=A0_Montr=C3=A9al?='],
            ['name' => 'Message-ID', 'value' => '<source@example.com>'],
            ['name' => 'References', 'value' => '<ancestor@example.com>'],
            ['name' => 'To', 'value' => 'old-recipient@example.com'],
            ['name' => 'Bcc', 'value' => 'old-private@example.com'],
        ], 'body' => ['data' => 'T2xkIGNvbnRleHQ', 'size' => 11],
    ]];
}

function gmailContextMessage(ReplyForwardContext $context): PreparedMessage
{
    return new PreparedMessage(
        sender: new EmailAddressDto('Sender', 'sender@example.com'),
        to: [new EmailAddressDto('Chosen', 'chosen@example.com')],
        bcc: [new EmailAddressDto('Chosen private', 'chosen-private@example.com')],
        subject: 'Réunion à Montréal',
        correlationId: 'reply-operation',
        textBody: 'Only the chosen reply',
        context: $context,
    );
}

/**
 * @param  list<mixed>  $responses
 * @param  array<int, mixed>  $history
 */
function gmailContextMailbox(array $responses, array &$history, ?TestGrantTokenProvider $tokens = null): MailboxResource
{
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::mapRequest(static fn (RequestInterface $request): RequestInterface => $request->withBody(Utils::streamFor((string) $request->getBody()))));
    $handler->push(Middleware::history($history));
    $tokens ??= new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['sender@example.com'], 'token' => 'private-token'],
    ]);

    return Mailbox::forGrant(new GrantContext('connection', 'grant', '1', 'gmail', 'sender@example.com'), $tokens, new Client(['handler' => $handler]));
}

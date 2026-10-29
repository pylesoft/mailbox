<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\PreparedFile;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\ReplyForwardContext;
use Pyle\Mailbox\DTOs\SubmissionResult;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Enums\ReplyForwardOperation;
use Pyle\Mailbox\Enums\SubmissionEvidenceOutcome;
use Pyle\Mailbox\Enums\SubmissionOutcome;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
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
        ->and($mime->getHeader('In-Reply-To')?->getRawValue())->toBe('<source@example.com>')
        ->and($mime->getHeader('References')?->getRawValue())->toBe('<ancestor@example.com> <source@example.com>')
        ->and($mime->getTextContent())->toBe('Only the chosen reply')
        ->and($mime->getHeader('To')->getAddresses()[0]->getEmail())->toBe('chosen@example.com')
        ->and($mime->getHeader('Bcc')->getAddresses()[0]->getEmail())->toBe('chosen-private@example.com')
        ->and($rawMime)->not->toContain('old-private@example.com');
});

it('preserves a caller chosen outgoing Message-ID for Gmail reply correlation', function (): void {
    $history = [];
    $mailbox = gmailContextMailbox([
        new Response(200, [], json_encode(['id' => 'thread', 'messages' => [gmailContextSource()]], JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"id":"sent","threadId":"thread"}'),
    ], $history);
    $conversation = $mailbox->conversation('thread', new SyncScope, new SyncBudget);
    $message = gmailContextMessage(new ReplyForwardContext(ReplyForwardOperation::Reply, $conversation, 'source'), [
        'headers' => ['Message-ID' => '<caller-outgoing@example.com>'],
    ]);

    $result = $mailbox->submit($message);

    $payload = json_decode((string) $history[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $mime = (new MailMimeParser)->parse(base64_decode(strtr($payload['raw'], '-_', '+/'), true), false);
    expect($mime->getHeader('Message-ID')->getRawValue())->toBe('<caller-outgoing@example.com>')
        ->and($result->context?->matches(new GrantContext('connection', 'grant', '1', 'gmail', 'sender@example.com')))->toBeTrue()
        ->and($history)->toHaveCount(2);
});

it('rejects ambiguous source Message-IDs before sending a Gmail reply', function (): void {
    $history = [];
    $source = gmailContextSource();
    $source['payload']['headers'][] = ['name' => 'Message-ID', 'value' => '<different-source@example.com>'];
    $mailbox = gmailContextMailbox([
        new Response(200, [], json_encode(['id' => 'thread', 'messages' => [$source]], JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"id":"must-not-send","threadId":"thread"}'),
    ], $history);
    $conversation = $mailbox->conversation('thread', new SyncScope, new SyncBudget);
    $message = gmailContextMessage(new ReplyForwardContext(ReplyForwardOperation::Reply, $conversation, 'source'));

    expect(fn () => $mailbox->submit($message))->toThrow(UnsupportedCapabilityException::class)
        ->and($history)->toHaveCount(1);
});

it('keeps an original observed thread subject when replying to a later prefixed message', function (): void {
    $history = [];
    $original = gmailContextSource();
    $original['id'] = 'original';
    $original['payload']['headers'][1]['value'] = '<ancestor@example.com>';
    $latest = gmailContextSource();
    $latest['payload']['headers'][0]['value'] = 'Re: Réunion à Montréal';
    $mailbox = gmailContextMailbox([
        new Response(200, [], json_encode(['id' => 'thread', 'messages' => [$original, $latest]], JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"id":"sent","threadId":"thread"}'),
    ], $history);
    $conversation = $mailbox->conversation('thread', new SyncScope, new SyncBudget);

    $mailbox->submit(gmailContextMessage(new ReplyForwardContext(ReplyForwardOperation::Reply, $conversation, 'source')));

    $payload = json_decode((string) $history[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $mime = (new MailMimeParser)->parse(base64_decode(strtr($payload['raw'], '-_', '+/'), true), false);
    expect($mime->getHeaderValue('Subject'))->toBe('Réunion à Montréal')
        ->and($mime->getHeader('In-Reply-To')->getRawValue())->toBe('<source@example.com>')
        ->and($payload['threadId'])->toBe('thread');
});

it('forwards only chosen content and files without copying source recipients or reply headers', function (): void {
    $history = [];
    $source = gmailContextSource();
    $source['payload'] = ['mimeType' => 'multipart/mixed', 'headers' => $source['payload']['headers'], 'parts' => [
        ['mimeType' => 'text/plain', 'body' => $source['payload']['body']],
        ['mimeType' => 'text/plain', 'filename' => 'old-file.dat', 'body' => ['attachmentId' => 'old-file', 'size' => 3]],
    ]];
    $mailbox = gmailContextMailbox([
        new Response(200, [], json_encode(['id' => 'thread', 'messages' => [$source]], JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"id":"forwarded","threadId":"new-thread"}'),
    ], $history);
    $conversation = $mailbox->conversation('thread', new SyncScope, new SyncBudget);
    $file = new PreparedFile('chosen', 'chosen.dat', 'text/plain', 12, static fn () => Utils::streamFor('Chosen bytes'), hash('sha256', 'Chosen bytes'));
    $message = gmailContextMessage(new ReplyForwardContext(ReplyForwardOperation::Forward, $conversation, 'source'), [
        'subject' => 'Transfert : Montréal', 'headers' => ['X-Choice' => 'Only chosen'], 'files' => [$file],
    ]);

    $result = $mailbox->submit($message);

    $payload = json_decode((string) $history[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $raw = base64_decode(strtr($payload['raw'], '-_', '+/'), true);
    $mime = (new MailMimeParser)->parse($raw, false);
    $parts = $mime->getAllAttachmentParts();
    expect($result->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($payload)->not->toHaveKey('threadId')
        ->and($mime->getHeaderValue('Subject'))->toBe('Transfert : Montréal')
        ->and($mime->getHeader('In-Reply-To'))->toBeNull()
        ->and($mime->getHeader('References'))->toBeNull()
        ->and($mime->getHeaderValue('X-Choice'))->toBe('Only chosen')
        ->and($mime->getTextContent())->toBe('Only the chosen reply')
        ->and($parts)->toHaveCount(1)
        ->and($parts[0]->getFilename())->toBe('chosen.dat')
        ->and($parts[0]->getContent())->toBe('Chosen bytes')
        ->and($raw)->not->toContain('old-private@example.com')->not->toContain('old-recipient@example.com')->not->toContain('old-file.dat')
        ->and($history)->toHaveCount(2);
});

it('observes a scoped Gmail sent copy without changing original acceptance or resending after a later missing copy', function (): void {
    $history = [];
    $mailbox = gmailContextMailbox([
        new Response(200, ['X-Request-Id' => 'original-send'], '{"id":"sent","threadId":"thread"}'),
        new Response(200, ['X-Request-Id' => 'inspection'], '{"id":"sent","threadId":"thread","labelIds":["SENT"],"payload":{"headers":[{"name":"X-Mailbox-Correlation-Id","value":"reply-operation"}]}}'),
        new Response(404),
    ], $history);
    $original = $mailbox->submit(gmailContextMessage());

    $observed = $mailbox->inspectSubmission($original);
    $missing = $mailbox->inspectSubmission($original);

    expect($observed->outcome)->toBe(SubmissionEvidenceOutcome::SentCopyObserved)
        ->and($observed->artifactId)->toBe('sent')
        ->and($observed->conversationId)->toBe('thread')
        ->and($observed->correlationId)->toBe('reply-operation')
        ->and($observed->context)->toBe($original->context)
        ->and($observed->headers)->toBe(['x-request-id' => ['inspection']])
        ->and($missing->outcome)->toBe(SubmissionEvidenceOutcome::Inconclusive)
        ->and($missing->artifactId)->toBe('sent')
        ->and($missing->correlationId)->toBe('reply-operation')
        ->and($original->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($original->status)->toBe(200)
        ->and($original->headers)->toBe(['x-request-id' => ['original-send']])
        ->and(array_map(static fn (array $entry): string => $entry['request']->getMethod(), $history))->toBe(['POST', 'GET', 'GET'])
        ->and((string) $history[1]['request']->getUri())->toBe('https://gmail.googleapis.com/gmail/v1/users/sender%40example.com/messages/sent?format=metadata');
});

it('keeps malformed Gmail label evidence inconclusive without repeating the send', function (): void {
    $history = [];
    $mailbox = gmailContextMailbox([
        new Response(200, [], '{"id":"sent","threadId":"thread"}'),
        new Response(200, [], '{"id":"sent","threadId":"thread","labelIds":["SENT",17],"payload":{"headers":[{"name":"X-Mailbox-Correlation-Id","value":"reply-operation"}]}}'),
    ], $history);
    $original = $mailbox->submit(gmailContextMessage());

    $evidence = $mailbox->inspectSubmission($original);

    expect($evidence->outcome)->toBe(SubmissionEvidenceOutcome::Inconclusive)
        ->and($original->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and(array_map(static fn (array $entry): string => $entry['request']->getMethod(), $history))->toBe(['POST', 'GET']);
});

it('rejects a Gmail receipt from another captured identity before inspection HTTP', function (?GrantContext $context, string $provider): void {
    $history = [];
    $mailbox = gmailContextMailbox([new Response(200, [], '{"id":"sent","threadId":"thread"}')], $history);
    $original = $mailbox->submit(gmailContextMessage());
    $foreign = new SubmissionResult($provider, $original->outcome, $original->correlationId, $original->status,
        $original->headers, $original->messageId, $original->threadId, $context);

    expect(fn () => $mailbox->inspectSubmission($foreign))->toThrow(InvalidArgumentException::class)
        ->and($original->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($history)->toHaveCount(1);
})->with([
    'unbound legacy receipt' => [null, 'gmail'],
    'connection' => [new GrantContext('another', 'grant', '1', 'gmail', 'sender@example.com'), 'gmail'],
    'grant in same mailbox' => [new GrantContext('connection', 'another', '1', 'gmail', 'sender@example.com'), 'gmail'],
    'generation' => [new GrantContext('connection', 'grant', '2', 'gmail', 'sender@example.com'), 'gmail'],
    'mailbox' => [new GrantContext('connection', 'grant', '1', 'gmail', 'another@example.com'), 'gmail'],
    'driver' => [new GrantContext('connection', 'grant', '1', 'ms-graph', 'sender@example.com'), 'gmail'],
    'provider' => [new GrantContext('connection', 'grant', '1', 'gmail', 'sender@example.com'), 'ms-graph'],
]);

it('keeps an unacknowledged Gmail message identity inconclusive without guessing a correlation search', function (): void {
    $history = [];
    $mailbox = gmailContextMailbox([new Response(200, ['X-Request-Id' => 'original'], '{"threadId":"thread"}')], $history);
    $original = $mailbox->submit(gmailContextMessage());

    $evidence = $mailbox->inspectSubmission($original);

    expect($evidence->outcome)->toBe(SubmissionEvidenceOutcome::Inconclusive)
        ->and($evidence->artifactId)->toBeNull()
        ->and($evidence->status)->toBeNull()
        ->and($evidence->correlationId)->toBe('reply-operation')
        ->and($evidence->conversationId)->toBe('thread')
        ->and($original->outcome)->toBe(SubmissionOutcome::Uncertain)
        ->and($original->status)->toBe(200)
        ->and($original->headers)->toBe(['x-request-id' => ['original']])
        ->and($history)->toHaveCount(1);
});

it('rejects stale Gmail inspection work after reconnect or disconnect without reading or sending', function (string $change): void {
    $history = [];
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['sender@example.com'], 'token' => 'private-token'],
    ]);
    $mailbox = gmailContextMailbox([new Response(200, [], '{"id":"sent","threadId":"thread"}')], $history, $tokens);
    $original = $mailbox->submit(gmailContextMessage());
    if ($change === 'reconnect') {
        $tokens->grants['grant']['generation'] = '2';
    } else {
        unset($tokens->grants['grant']);
    }

    expect(fn () => $mailbox->inspectSubmission($original))->toThrow(GrantAccessDeniedException::class)
        ->and($original->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($history)->toHaveCount(1);
})->with(['reconnect', 'disconnect']);

it('keeps mismatched contextual Gmail acknowledgements uncertain while retaining provider facts', function (ReplyForwardOperation $operation, string $acknowledgement, ?string $observedThread): void {
    $history = [];
    $mailbox = gmailContextMailbox([
        new Response(200, [], json_encode(['id' => 'thread', 'messages' => [gmailContextSource()]], JSON_THROW_ON_ERROR)),
        new Response(200, ['X-Request-Id' => 'original'], $acknowledgement),
    ], $history);
    $conversation = $mailbox->conversation('thread', new SyncScope, new SyncBudget);

    $result = $mailbox->submit(gmailContextMessage(new ReplyForwardContext($operation, $conversation, 'source')));

    expect($result->outcome)->toBe(SubmissionOutcome::Uncertain)
        ->and($result->status)->toBe(200)
        ->and($result->messageId)->toBe('sent')
        ->and($result->threadId)->toBe($observedThread)
        ->and($result->headers)->toBe(['x-request-id' => ['original']])
        ->and($result->correlationId)->toBe('reply-operation')
        ->and($history)->toHaveCount(2);
})->with([
    'reply acknowledged in another thread' => [ReplyForwardOperation::Reply, '{"id":"sent","threadId":"wrong-thread"}', 'wrong-thread'],
    'reply without thread evidence' => [ReplyForwardOperation::Reply, '{"id":"sent"}', null],
    'forward acknowledged in the source thread' => [ReplyForwardOperation::Forward, '{"id":"sent","threadId":"thread"}', 'thread'],
    'forward without thread evidence' => [ReplyForwardOperation::Forward, '{"id":"sent"}', null],
]);

it('leaves missing or mismatched Gmail metadata inconclusive and retains original acceptance', function (array|string $changes, int $status = 200): void {
    $history = [];
    $metadata = ['id' => 'sent', 'threadId' => 'thread', 'labelIds' => ['SENT'], 'payload' => [
        'headers' => [['name' => 'X-Mailbox-Correlation-Id', 'value' => 'reply-operation']],
    ]];
    $body = is_array($changes) ? json_encode(array_replace($metadata, $changes), JSON_THROW_ON_ERROR) : $changes;
    $mailbox = gmailContextMailbox([
        new Response(200, ['X-Request-Id' => 'original'], '{"id":"sent","threadId":"thread"}'),
        new Response($status, ['X-Request-Id' => 'inspection', 'Authorization' => 'Bearer private-response', 'Location' => 'https://private-upload'], $body),
    ], $history);
    $original = $mailbox->submit(gmailContextMessage());

    $evidence = $mailbox->inspectSubmission($original);

    expect($evidence->outcome)->toBe(SubmissionEvidenceOutcome::Inconclusive)
        ->and($evidence->status)->toBe($status)
        ->and($evidence->artifactId)->toBe('sent')
        ->and($evidence->conversationId)->toBe('thread')
        ->and($evidence->correlationId)->toBe('reply-operation')
        ->and($original->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($original->headers)->toBe(['x-request-id' => ['original']])
        ->and(array_map(static fn (array $entry): string => $entry['request']->getMethod(), $history))->toBe(['POST', 'GET'])
        ->and(json_encode($evidence, JSON_THROW_ON_ERROR))->not->toContain('private-response')->not->toContain('private-upload')->not->toContain('private-token');
})->with([
    'another message' => [['id' => 'other']],
    'missing message ID' => [['id' => null]],
    'another thread' => [['threadId' => 'other']],
    'missing thread' => [['threadId' => null]],
    'invalid thread' => [['threadId' => 'not/a/thread']],
    'not sent' => [['labelIds' => ['INBOX']]],
    'still a draft' => [['labelIds' => ['SENT', 'DRAFT']]],
    'label object' => [['labelIds' => (object) ['SENT' => true]]],
    'missing correlation' => [['payload' => ['headers' => []]]],
    'another correlation' => [['payload' => ['headers' => [['name' => 'X-Mailbox-Correlation-Id', 'value' => 'other']]]]],
    'truncated encoded correlation' => [['payload' => ['headers' => [['name' => 'X-Mailbox-Correlation-Id', 'value' => '=?UTF-8?Q?unfinished']]]]],
    'duplicate correlation' => [['payload' => ['headers' => [['name' => 'X-Mailbox-Correlation-Id', 'value' => 'reply-operation'], ['name' => 'x-mailbox-correlation-id', 'value' => 'reply-operation']]]]],
    'malformed header' => [['payload' => ['headers' => [['name' => 'X-Mailbox-Correlation-Id', 'value' => 12]]]]],
    'header object' => [['payload' => ['headers' => (object) []]]],
    'missing payload' => [['payload' => null]],
    'invalid JSON' => ['{'],
    'JSON list' => ['[]'],
    'oversized body' => [str_repeat('x', 65_537)],
    'delayed copy' => [[], 404],
    'insufficient read scope' => [[], 403],
    'server failure' => [[], 503],
    'redirect' => [[], 307],
]);

it('keeps interrupted Gmail evidence reads inconclusive with owned cleanup and no resend', function (bool $timeout): void {
    $history = [];
    $closed = false;
    $body = FnStream::decorate(Utils::streamFor('metadata'), [
        'read' => static function (int $length): string {
            throw new RuntimeException('private-token interrupted read');
        },
        'close' => static function () use (&$closed): void {
            $closed = true;
        },
    ]);
    $response = $timeout
        ? new ConnectException('private-token timeout', new Request('GET', 'https://private-upload'))
        : new Response(200, ['X-Request-Id' => 'inspection'], $body);
    $mailbox = gmailContextMailbox([new Response(200, [], '{"id":"sent","threadId":"thread"}'), $response], $history);
    $original = $mailbox->submit(gmailContextMessage());

    $evidence = $mailbox->inspectSubmission($original);

    expect($evidence->outcome)->toBe(SubmissionEvidenceOutcome::Inconclusive)
        ->and($evidence->status)->toBe($timeout ? null : 200)
        ->and($original->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and(array_map(static fn (array $entry): string => $entry['request']->getMethod(), $history))->toBe(['POST', 'GET'])
        ->and(json_encode($evidence, JSON_THROW_ON_ERROR))->not->toContain('private-token')->not->toContain('private-upload');
    if (! $timeout) {
        expect($closed)->toBeTrue();
    }
})->with([false, true]);

it('replies to complete attachment-only Gmail sources without inheriting their files', function (): void {
    $history = [];
    $source = gmailContextSource();
    $source['payload'] = ['mimeType' => 'application/pdf', 'filename' => 'original.pdf', 'headers' => $source['payload']['headers'],
        'body' => ['attachmentId' => 'original-file', 'size' => 3]];
    $mailbox = gmailContextMailbox([
        new Response(200, [], json_encode(['id' => 'thread', 'messages' => [$source]], JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"id":"sent","threadId":"thread"}'),
    ], $history);
    $conversation = $mailbox->conversation('thread', new SyncScope, new SyncBudget);
    expect($conversation->complete)->toBeTrue()
        ->and($conversation->messages[0]->body)->toBeNull()
        ->and($conversation->files[0]['attachment']->id)->toBe('original-file');

    $result = $mailbox->submit(gmailContextMessage(new ReplyForwardContext(ReplyForwardOperation::Reply, $conversation, 'source')));

    $payload = json_decode((string) $history[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $mime = (new MailMimeParser)->parse(base64_decode(strtr($payload['raw'], '-_', '+/'), true), false);
    expect($result->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($mime->getAllAttachmentParts())->toBe([])
        ->and($mime->getTextContent())->toBe('Only the chosen reply')
        ->and($history)->toHaveCount(2);
});

it('rejects unavailable or conflicting Gmail reply context before sending', function (string $failure, string $exception): void {
    $history = [];
    $source = gmailContextSource();
    $changes = [];
    $budget = new SyncBudget;
    $operation = ReplyForwardOperation::Reply;
    $sourceId = 'source';
    if ($failure === 'wrong thread') {
        $source['threadId'] = 'another';
    } elseif ($failure === 'missing source ID') {
        unset($source['payload']['headers'][1]);
        $source['payload']['headers'] = array_values($source['payload']['headers']);
    } elseif ($failure === 'invalid source ID') {
        $source['payload']['headers'][1]['value'] = '<invalid>';
    } elseif ($failure === 'malformed references') {
        $source['payload']['headers'][2]['value'] = '<truncated';
    } elseif ($failure === 'missing body') {
        $source['payload']['body'] = [];
    } elseif ($failure === 'draft source') {
        $source['labelIds'] = ['DRAFT'];
    } elseif ($failure === 'unobserved subject') {
        $changes['subject'] = 'Re: Réunion à Montréal';
    } elseif ($failure === 'absent source') {
        $sourceId = 'absent';
    } elseif ($failure === 'conflicting reply header') {
        $changes['headers'] = ['In-Reply-To' => '<someone-else@example.com>'];
    } elseif ($failure === 'conflicting references') {
        $changes['headers'] = ['References' => '<someone-else@example.com> <source@example.com>'];
    } elseif ($failure === 'forward reply header') {
        $operation = ReplyForwardOperation::Forward;
        $changes['headers'] = ['References' => '<source@example.com>'];
    } elseif ($failure === 'nontext header') {
        $changes['headers'] = ['X-Chosen' => []];
    } elseif ($failure === 'numeric header') {
        $changes['headers'] = ['unsupported'];
    }
    $messages = [$source];
    if ($failure === 'unfinished read') {
        $older = gmailContextSource();
        $older['id'] = 'older';
        $messages = [$older, $source];
        $budget = new SyncBudget(maxItems: 1);
    }
    $mailbox = gmailContextMailbox([
        $failure === 'unavailable scope' ? new Response(404) : new Response(200, [], json_encode(['id' => 'thread', 'messages' => $messages], JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"id":"must-not-send","threadId":"thread"}'),
    ], $history);
    $conversation = $mailbox->conversation('thread', new SyncScope, $budget);
    $message = gmailContextMessage(new ReplyForwardContext($operation, $conversation, $sourceId), $changes);

    expect(fn () => $mailbox->submit($message))->toThrow($exception)
        ->and($history)->toHaveCount(1);
})->with([
    'wrong thread' => ['wrong thread', UnsupportedCapabilityException::class],
    'missing source ID' => ['missing source ID', UnsupportedCapabilityException::class],
    'invalid source ID' => ['invalid source ID', InvalidArgumentException::class],
    'malformed references' => ['malformed references', InvalidArgumentException::class],
    'missing body' => ['missing body', UnsupportedCapabilityException::class],
    'draft source' => ['draft source', UnsupportedCapabilityException::class],
    'unobserved subject' => ['unobserved subject', UnsupportedCapabilityException::class],
    'absent source' => ['absent source', UnsupportedCapabilityException::class],
    'conflicting reply header' => ['conflicting reply header', UnsupportedCapabilityException::class],
    'conflicting references' => ['conflicting references', UnsupportedCapabilityException::class],
    'forward reply header' => ['forward reply header', UnsupportedCapabilityException::class],
    'nontext header' => ['nontext header', InvalidArgumentException::class],
    'numeric header' => ['numeric header', InvalidArgumentException::class],
    'unfinished read' => ['unfinished read', UnsupportedCapabilityException::class],
    'unavailable scope' => ['unavailable scope', UnsupportedCapabilityException::class],
]);

it('rejects malformed or duplicate chosen Message-IDs before any Gmail HTTP', function (array $headers): void {
    $history = [];
    $mailbox = gmailContextMailbox([], $history);

    expect(fn () => $mailbox->submit(gmailContextMessage(changes: ['headers' => $headers])))->toThrow(InvalidArgumentException::class)
        ->and($history)->toBe([]);
})->with([
    'duplicate case variants' => [['Message-ID' => '<chosen@example.com>', 'message-id' => '<other@example.com>']],
    'multiple IDs' => [['Message-ID' => '<one@example.com> <two@example.com>']],
    'invalid ID' => [['Message-ID' => '<invalid>']],
    'missing brackets' => [['Message-ID' => 'chosen@example.com']],
    'non-ASCII ID' => [['Message-ID' => '<é@example.com>']],
]);

it('hydrates complete older Gmail source context while keeping only the chosen outgoing body', function (): void {
    $history = [];
    $source = gmailContextSource();
    $source['internalDate'] = '1262304000000';
    $source['payload']['body'] = ['attachmentId' => 'source-body', 'size' => 11];
    $mailbox = gmailContextMailbox([
        new Response(200, [], json_encode(['id' => 'thread', 'messages' => [$source]], JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"data":"T2xkIGNvbnRleHQ","size":11}'),
        new Response(200, [], '{"id":"sent","threadId":"thread"}'),
    ], $history);
    $conversation = $mailbox->conversation('thread', new SyncScope('INBOX', new DateTimeImmutable('2026-10-01')), new SyncBudget);

    $result = $mailbox->submit(gmailContextMessage(new ReplyForwardContext(ReplyForwardOperation::Reply, $conversation, 'source')));

    $payload = json_decode((string) $history[2]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $mime = (new MailMimeParser)->parse(base64_decode(strtr($payload['raw'], '-_', '+/'), true), false);
    expect($conversation->complete)->toBeTrue()
        ->and($conversation->messages[0]->body->content)->toBe('Old context')
        ->and($mime->getTextContent())->toBe('Only the chosen reply')
        ->and($result->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($history)->toHaveCount(3);
});

it('rejects complete Gmail source content captured by another grant in the same mailbox', function (): void {
    $sendHistory = $readHistory = [];
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['sender@example.com'], 'token' => 'send-token'],
        'other' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['sender@example.com'], 'token' => 'read-token'],
    ]);
    $sender = gmailContextMailbox([], $sendHistory, $tokens);
    $reader = gmailContextMailbox([
        new Response(200, [], json_encode(['id' => 'thread', 'messages' => [gmailContextSource()]], JSON_THROW_ON_ERROR)),
    ], $readHistory, $tokens, new GrantContext('connection', 'other', '1', 'gmail', 'sender@example.com'));
    $conversation = $reader->conversation('thread', new SyncScope, new SyncBudget);

    expect(fn () => $sender->submit(gmailContextMessage(new ReplyForwardContext(ReplyForwardOperation::Reply, $conversation, 'source'))))->toThrow(UnsupportedCapabilityException::class)
        ->and($conversation->complete)->toBeTrue()
        ->and($sendHistory)->toBe([])
        ->and($readHistory)->toHaveCount(1);
});

it('observes the original correlation when Gmail returns its actual encoded MIME header', function (): void {
    $history = [];
    $wireCorrelation = null;
    $mailbox = gmailContextMailbox([
        static function (RequestInterface $request) use (&$wireCorrelation): Response {
            $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $mime = (new MailMimeParser)->parse(base64_decode(strtr($payload['raw'], '-_', '+/'), true), false);
            $wireCorrelation = $mime->getHeader('X-Mailbox-Correlation-Id')->getRawValue();

            return new Response(200, [], '{"id":"sent","threadId":"thread"}');
        },
        static function () use (&$wireCorrelation): Response {
            return new Response(200, [], json_encode(['id' => 'sent', 'threadId' => 'thread', 'labelIds' => ['SENT'], 'payload' => [
                'headers' => [['name' => 'X-Mailbox-Correlation-Id', 'value' => $wireCorrelation]],
            ]], JSON_THROW_ON_ERROR));
        },
    ], $history);
    $original = $mailbox->submit(gmailContextMessage(changes: ['correlationId' => 'opération-équipe-42']));

    $evidence = $mailbox->inspectSubmission($original);

    expect($wireCorrelation)->toMatch('/=\?utf-8\?/i')
        ->and($evidence->outcome)->toBe(SubmissionEvidenceOutcome::SentCopyObserved)
        ->and($evidence->correlationId)->toBe('opération-équipe-42')
        ->and($original->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and(array_map(static fn (array $entry): string => $entry['request']->getMethod(), $history))->toBe(['POST', 'GET']);
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

/** @param array<string, mixed> $changes */
function gmailContextMessage(?ReplyForwardContext $context = null, array $changes = []): PreparedMessage
{
    return new PreparedMessage(...array_replace([
        'sender' => new EmailAddressDto('Sender', 'sender@example.com'),
        'to' => [new EmailAddressDto('Chosen', 'chosen@example.com')],
        'bcc' => [new EmailAddressDto('Chosen private', 'chosen-private@example.com')],
        'subject' => 'Réunion à Montréal',
        'correlationId' => 'reply-operation',
        'textBody' => 'Only the chosen reply',
        'context' => $context,
    ], $changes));
}

/**
 * @param  list<mixed>  $responses
 * @param  array<int, mixed>  $history
 */
function gmailContextMailbox(array $responses, array &$history, ?TestGrantTokenProvider $tokens = null, ?GrantContext $context = null): MailboxResource
{
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::mapRequest(static fn (RequestInterface $request): RequestInterface => $request->withBody(Utils::streamFor((string) $request->getBody()))));
    $handler->push(Middleware::history($history));
    $tokens ??= new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['sender@example.com'], 'token' => 'private-token'],
    ]);

    return Mailbox::forGrant($context ?? new GrantContext('connection', 'grant', '1', 'gmail', 'sender@example.com'), $tokens, new Client(['handler' => $handler]));
}

<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Queue\Job;
use Psr\Http\Message\RequestInterface;
use Pyle\Mailbox\Contracts\SupportsDraftAttachmentPreparation;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\GraphUploadSession;
use Pyle\Mailbox\DTOs\AttachmentPreparation;
use Pyle\Mailbox\DTOs\ConversationRead;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\DTOs\ReplyForwardContext;
use Pyle\Mailbox\DTOs\SyncIdentity;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\DTOs\PreparedFile;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\SubmissionLimits;
use Pyle\Mailbox\DTOs\SubmissionPreparation;
use Pyle\Mailbox\Enums\PreparationOutcome;
use Pyle\Mailbox\Enums\SubmissionOutcome;
use Pyle\Mailbox\Enums\ReplyForwardOperation;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
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

it('does not use an untrusted malformed or already expired Outlook session', function (string $case, PreparationOutcome $outcome): void {
    $history = [];
    $data = ['uploadUrl' => graphAttachmentUploadUrl(), 'expirationDateTime' => (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM), 'nextExpectedRanges' => ['0']];
    if ($case === 'foreign origin') {
        $data['uploadUrl'] = str_replace('outlook.office.com', 'attacker.example', $data['uploadUrl']);
    } elseif ($case === 'expired') {
        $data['expirationDateTime'] = '2020-01-01T00:00:00Z';
    } elseif ($case === 'missing initial offset') {
        unset($data['nextExpectedRanges']);
    } elseif ($case === 'invalid expiry') {
        $data['expirationDateTime'] = '2026-02-31T00:00:00Z';
    }
    $body = $case === 'malformed JSON' ? '{' : json_encode($data, JSON_THROW_ON_ERROR);
    $mailbox = graphAttachmentMailbox([new Response(201, [], '{"id":"immutable-draft="}'), new Response(201, [], $body)], $history);
    $bytes = str_repeat('a', 3_000_001);
    $message = graphAttachmentMessage([new PreparedFile('chosen-file', 'chosen.bin', 'application/octet-stream', strlen($bytes),
        static fn () => Utils::streamFor($bytes), hash('sha256', $bytes))]);

    $result = $mailbox->prepareAttachments($mailbox->prepareSubmission($message), $message);

    expect($result->outcome)->toBe($outcome)
        ->and($result->draftId)->toBe('immutable-draft=')
        ->and(fn () => $mailbox->prepareAttachments($result, $message))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $mailbox->submitPrepared($result, $message))->toThrow(InvalidArgumentException::class)
        ->and($history)->toHaveCount(2);
    foreach (['private-upload-secret', 'attacker.example', 'outlook.office.com'] as $secret) {
        expect(json_encode($result, JSON_THROW_ON_ERROR).print_r($result, true))->not->toContain($secret);
    }
})->with([
    ['foreign origin', PreparationOutcome::Uncertain], ['expired', PreparationOutcome::Expired],
    ['missing initial offset', PreparationOutcome::Uncertain], ['invalid expiry', PreparationOutcome::Uncertain],
    ['malformed JSON', PreparationOutcome::Uncertain],
]);

it('requires the original captured session and authorization binding when restoring upload progress', function (bool $missing): void {
    $history = [];
    $expires = new DateTimeImmutable('+1 hour');
    $mailbox = graphAttachmentMailbox([new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(201, [], json_encode(['uploadUrl' => graphAttachmentUploadUrl(), 'expirationDateTime' => $expires->format(DATE_ATOM), 'nextExpectedRanges' => ['0']], JSON_THROW_ON_ERROR))], $history);
    $bytes = str_repeat('a', 3_000_001);
    $file = new PreparedFile('chosen-file', 'chosen.bin', 'application/octet-stream', strlen($bytes), static fn () => Utils::streamFor($bytes), hash('sha256', $bytes));
    $message = graphAttachmentMessage([$file]);
    $original = $mailbox->prepareAttachments($mailbox->prepareSubmission($message), $message);
    $session = $missing ? null : new GraphUploadSession(new GrantContext('connection', 'another-grant', '1', 'ms-graph', 'sender@example.com'),
        'immutable-draft=', $file->identity, $file->byteLength, $file->fingerprint(), graphAttachmentUploadUrl(), $expires);
    $restored = new SubmissionPreparation($original->context, $original->outcome, $original->correlationId, $original->draftId,
        $original->status, $original->headers, $original->intentFingerprint(), $original->conversationId,
        [new AttachmentPreparation($file->identity, PreparationOutcome::Preparing, 0, null, 201, session: $session)]);

    expect(fn () => $mailbox->prepareAttachments(unserialize(serialize($restored)), $message))->toThrow(InvalidArgumentException::class)
        ->and($history)->toHaveCount(2);
})->with(['missing private state' => true, 'foreign grant same mailbox' => false]);

it('recovers a lost final large file acknowledgement from original draft bytes before preparing the next chosen file', function (): void {
    $history = [];
    $bytes = str_repeat('a', 3_000_001);
    $expires = (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM);
    $remote = ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'observed-large=',
        'name' => 'chosen.bin', 'contentType' => 'application/octet-stream', 'size' => 3_000_001, 'isInline' => false, 'contentId' => null];
    $mailbox = graphAttachmentMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(201, [], json_encode(['uploadUrl' => graphAttachmentUploadUrl(), 'expirationDateTime' => $expires, 'nextExpectedRanges' => ['0']], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['expirationDateTime' => $expires, 'nextExpectedRanges' => ['3000000']], JSON_THROW_ON_ERROR)),
        new ConnectException('private-upload-secret', new Request('PUT', graphAttachmentUploadUrl())),
        new Response(200, [], json_encode(graphAttachmentDraft([$remote]), JSON_THROW_ON_ERROR)), new Response(200, [], $bytes),
        new Response(201, [], '{"id":"second-file="}'),
    ], $history);
    $message = graphAttachmentMessage([
        new PreparedFile('large-file', 'chosen.bin', 'application/octet-stream', strlen($bytes), static fn () => Utils::streamFor($bytes), hash('sha256', $bytes)),
        new PreparedFile('small-file', 'second.txt', 'text/plain', 5, static fn () => Utils::streamFor('hello'), hash('sha256', 'hello')),
    ]);
    $session = $mailbox->prepareAttachments($mailbox->prepareSubmission($message), $message);
    $prefix = $mailbox->prepareAttachments($session, $message);
    $uncertain = $mailbox->prepareAttachments($prefix, $message);

    $observed = $mailbox->inspectAttachments(unserialize(serialize($uncertain)), $message);
    expect($observed->outcome)->toBe(PreparationOutcome::Preparing)
        ->and($observed->attachments[0]->attachmentId)->toBe('observed-large=')
        ->and($observed->attachments[0]->acknowledgedBytes)->toBe(3_000_001)
        ->and($history)->toHaveCount(6);
    $complete = $mailbox->prepareAttachments($observed, $message);
    expect($complete->outcome)->toBe(PreparationOutcome::Prepared)
        ->and(array_column($complete->attachments, 'attachmentId'))->toBe(['observed-large=', 'second-file='])
        ->and(array_map(static fn ($entry): string => $entry['request']->getMethod(), $history))
        ->toBe(['POST', 'POST', 'PUT', 'PUT', 'GET', 'GET', 'POST'])
        ->and($complete->draftId)->toBe('immutable-draft=');
});

it('refuses changed remote file intent or bytes before the immutable draft send', function (string $case): void {
    $history = [];
    $file = ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'chosen-file=',
        'name' => 'chosen.txt', 'contentType' => 'text/plain', 'size' => 5, 'isInline' => false, 'contentId' => null];
    $draft = graphAttachmentDraft([$file]);
    if ($case === 'changed bytes') {
        $raw = 'hallo';
    } elseif ($case === 'changed identity') {
        $draft['attachments'][0]['id'] = 'foreign-file=';
    } elseif ($case === 'changed name') {
        $draft['attachments'][0]['name'] = 'another.txt';
    } elseif ($case === 'changed type') {
        $draft['attachments'][0]['@odata.type'] = '#microsoft.graph.itemAttachment';
    } elseif ($case === 'changed inline') {
        $draft['attachments'][0]['isInline'] = true;
    } elseif ($case === 'extra file') {
        $draft['attachments'][] = array_replace($file, ['id' => 'extra-file=']);
    } elseif ($case === 'incomplete list') {
        $draft['attachments@odata.nextLink'] = 'https://private-upload-secret';
    } elseif ($case === 'sent already') {
        $draft['isDraft'] = false;
    }
    $mailbox = graphAttachmentMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'), new Response(201, [], '{"id":"chosen-file="}'),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)), new Response(200, [], $raw ?? 'hello'), new Response(202),
    ], $history);
    $message = graphAttachmentMessage([new PreparedFile('chosen-file', 'chosen.txt', 'text/plain', 5,
        static fn () => Utils::streamFor('hello'), hash('sha256', 'hello'))]);
    $prepared = $mailbox->prepareAttachments($mailbox->prepareSubmission($message), $message);

    expect(fn () => $mailbox->submitPrepared($prepared, $message))->toThrow(ApiRequestException::class)
        ->and($history)->toHaveCount($case === 'changed bytes' ? 4 : 3);
})->with(['changed bytes', 'changed identity', 'changed name', 'changed type', 'changed inline', 'extra file', 'incomplete list', 'sent already']);

it('stops when the host revokes the selected grant while the original source is reopening for an upload', function (): void {
    $history = [];
    $tokens = new TestGrantTokenProvider(['grant' => ['connection' => 'connection', 'generation' => '1',
        'mailboxes' => ['sender@example.com'], 'token' => 'private-token']]);
    $expires = (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM);
    $mailbox = graphAttachmentMailbox([new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(201, [], json_encode(['uploadUrl' => graphAttachmentUploadUrl(), 'expirationDateTime' => $expires, 'nextExpectedRanges' => ['0']], JSON_THROW_ON_ERROR))], $history, tokens: $tokens);
    $bytes = str_repeat('a', 3_000_001);
    $opens = $closes = 0;
    $message = graphAttachmentMessage([new PreparedFile('chosen-file', 'chosen.bin', 'application/octet-stream', strlen($bytes),
        static function () use ($bytes, $tokens, &$opens, &$closes) {
            if (++$opens === 2) {
                $tokens->grants['grant']['generation'] = '2';
            }
            $source = Utils::streamFor($bytes);

            return \GuzzleHttp\Psr7\FnStream::decorate($source, ['close' => static function () use ($source, &$closes): void {
                if ($source->isReadable()) {
                    $closes++;
                }
                $source->close();
            }]);
        }, hash('sha256', $bytes))]);
    $session = $mailbox->prepareAttachments($mailbox->prepareSubmission($message), $message);

    expect(fn () => $mailbox->prepareAttachments($session, $message))->toThrow(GrantAccessDeniedException::class)
        ->and($history)->toHaveCount(2)
        ->and($tokens->tokenReads)->toBe(2)
        ->and($closes)->toBe(2);
});

it('enforces caller decoded file and write limits before attaching another file', function (string $case): void {
    $history = [];
    $opened = false;
    $source = static function () use (&$opened) {
        $opened = true;

        return Utils::streamFor('hello');
    };
    $files = [new PreparedFile('chosen-file', 'chosen.txt', 'text/plain', 5, $source, hash('sha256', 'hello'))];
    if ($case === 'aggregate') {
        $files[] = new PreparedFile('second-file', 'second.txt', 'text/plain', 5, $source, hash('sha256', 'hello'));
    }
    $mailbox = graphAttachmentMailbox([new Response(201, [], '{"id":"immutable-draft="}')], $history);
    $message = graphAttachmentMessage($files);
    $draft = $mailbox->prepareSubmission($message);
    $limits = new SubmissionLimits(maxFileBytes: $case === 'file' ? 4 : 5, maxTotalFileBytes: 9);

    expect(fn () => $mailbox->prepareAttachments($draft, $message, $limits, maxRequestBytes: $case === 'write' ? 100 : 4_000_000))
        ->toThrow($case === 'write' ? \Pyle\Mailbox\Exceptions\ResponseLimitExceededException::class : UnsupportedCapabilityException::class)
        ->and($history)->toHaveCount(1);
    if ($case !== 'write') {
        expect($opened)->toBeFalse();
    }
})->with(['file', 'aggregate', 'write']);

it('rejects a large-file source before creating a session when the caller memory budget cannot hold a bounded read', function (): void {
    $history = [];
    $opened = false;
    $mailbox = graphAttachmentMailbox([new Response(201, [], '{"id":"immutable-draft="}')], $history);
    $message = graphAttachmentMessage([new PreparedFile('chosen-file', 'chosen.bin', 'application/octet-stream', 3_000_001,
        static function () use (&$opened) {
            $opened = true;

            return Utils::streamFor(str_repeat('a', 3_000_001));
        }, hash('sha256', str_repeat('a', 3_000_001)))]);
    $draft = $mailbox->prepareSubmission($message);

    expect(fn () => $mailbox->prepareAttachments($draft, $message, new SubmissionLimits(maxMemoryBytes: 32_768)))
        ->toThrow(\Pyle\Mailbox\Exceptions\ResponseLimitExceededException::class)
        ->and($opened)->toBeFalse()
        ->and($history)->toHaveCount(1);
});

it('retains the last acknowledged prefix and distinguishes final range failures without replay or secret output', function (mixed $final, PreparationOutcome $outcome, ?int $status): void {
    $history = [];
    $job = Mockery::mock(Job::class);
    $job->shouldNotReceive('release');
    app()->instance('queue.job', $job);
    $expires = (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM);
    $mailbox = graphAttachmentMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(201, [], json_encode(['uploadUrl' => graphAttachmentUploadUrl(), 'expirationDateTime' => $expires, 'nextExpectedRanges' => ['0-']], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['expirationDateTime' => $expires, 'nextExpectedRanges' => ['3000000']], JSON_THROW_ON_ERROR)), $final,
    ], $history);
    $bytes = str_repeat('a', 3_000_001);
    $message = graphAttachmentMessage([new PreparedFile('chosen-file', 'chosen.bin', 'application/octet-stream', strlen($bytes),
        static fn () => Utils::streamFor($bytes), hash('sha256', $bytes))]);
    $session = $mailbox->prepareAttachments($mailbox->prepareSubmission($message), $message);
    $prefix = $mailbox->prepareAttachments($session, $message);

    $result = $mailbox->prepareAttachments($prefix, $message);

    expect($result->outcome)->toBe($outcome)
        ->and($result->draftId)->toBe('immutable-draft=')
        ->and($result->attachments[0]->acknowledgedBytes)->toBe(3_000_000)
        ->and($result->attachments[0]->attachmentId)->toBeNull()
        ->and($result->status)->toBe($status)
        ->and(fn () => $mailbox->prepareAttachments($result, $message))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $mailbox->submitPrepared($result, $message))->toThrow(InvalidArgumentException::class)
        ->and($history)->toHaveCount(4);
    $public = json_encode($result, JSON_THROW_ON_ERROR).print_r($result, true);
    foreach (['private-upload-secret', 'private-token', 'private-response', 'outlook.office.com'] as $secret) {
        expect($public)->not->toContain($secret);
    }
})->with([
    'lost final acknowledgement' => [new ConnectException('private-upload-secret', new Request('PUT', 'https://private-upload-secret')), PreparationOutcome::Uncertain, null],
    'server error' => [new Response(503, [], 'private-response'), PreparationOutcome::Uncertain, 503],
    'explicit invalid range rejection' => [new Response(400), PreparationOutcome::Rejected, 400],
    'rate limit rejection' => [new Response(429, ['Retry-After' => '9']), PreparationOutcome::Rejected, 429],
    'missing session' => [new Response(404), PreparationOutcome::Expired, 404],
    'expired session' => [new Response(410), PreparationOutcome::Expired, 410],
    'redirect' => [new Response(307, ['Location' => 'https://attacker.example/private-upload-secret']), PreparationOutcome::Uncertain, 307],
    'missing Location' => [new Response(201), PreparationOutcome::Uncertain, 201],
    'foreign draft Location' => [new Response(201, ['Location' => "https://outlook.office.com/api/v2.0/Users('provider-user')/Messages('another-draft')/Attachments('file=')"]), PreparationOutcome::Uncertain, 201],
    'unexpected final body' => [new Response(201, ['Location' => "https://outlook.office.com/api/v2.0/Users('provider-user')/Messages('provider-draft')/Attachments('file=')"], '{}'), PreparationOutcome::Uncertain, 201],
    'offset without completion identity' => [new Response(200, [], '{"nextExpectedRanges":["3000001"]}'), PreparationOutcome::Uncertain, 200],
    'oversized receipt' => [new Response(201, [], str_repeat('x', 65_537)), PreparationOutcome::Uncertain, 201],
    'encoded receipt' => [new Response(201, ['Content-Encoding' => 'gzip'], gzencode('private-response')), PreparationOutcome::Uncertain, 201],
]);

it('prepares chosen inline files on the new native draft while preserving its conversation and original message', function (ReplyForwardOperation $operation): void {
    $history = [];
    $source = ['id' => 'source=', 'conversationId' => 'source-conversation', 'isDraft' => false,
        'body' => ['contentType' => 'text', 'content' => 'Original content']];
    $conversationId = $operation === ReplyForwardOperation::Reply ? 'source-conversation' : 'forward-conversation';
    $empty = array_replace(graphAttachmentDraft([]), ['conversationId' => $conversationId]);
    $file = ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'chosen-inline=',
        'name' => 'chosen.txt', 'contentType' => 'text/plain', 'size' => 5, 'isInline' => true, 'contentId' => 'chosen-cid'];
    $completed = array_replace($empty, ['attachments' => [$file], 'hasAttachments' => false]);
    $mailbox = graphAttachmentMailbox([
        new Response(200, [], json_encode($source, JSON_THROW_ON_ERROR)), new Response(201, [], json_encode($empty, JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"value":[{"id":"copied-original="}]}'), new Response(204),
        new Response(200, [], json_encode($empty, JSON_THROW_ON_ERROR)), new Response(201, [], '{"id":"chosen-inline="}'),
        new Response(200, [], json_encode($completed, JSON_THROW_ON_ERROR)), new Response(200, [], 'hello'), new Response(202),
    ], $history);
    $context = new GrantContext('connection', 'grant', '1', 'ms-graph', 'sender@example.com');
    $conversation = new ConversationRead(new SyncIdentity('ms-graph', $context, new SyncScope('Inbox')),
        'source-conversation', [MessageDto::fromMsGraph($source)], complete: true);
    $chosen = graphAttachmentMessage([new PreparedFile('chosen-file', 'chosen.txt', 'text/plain', 5,
        static fn () => Utils::streamFor('hello'), hash('sha256', 'hello'), true, 'chosen-cid')]);
    $message = new PreparedMessage($chosen->sender, $chosen->to, $chosen->subject, $chosen->correlationId,
        textBody: $chosen->textBody, files: $chosen->files, context: new ReplyForwardContext($operation, $conversation, 'source='));

    $draft = $mailbox->prepareSubmission($message);
    expect($draft->outcome)->toBe(PreparationOutcome::Preparing)
        ->and($draft->conversationId)->toBe($conversationId);
    $prepared = $mailbox->prepareAttachments($draft, $message);
    expect($mailbox->submitPrepared($prepared, $message)->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($prepared->conversationId)->toBe($conversationId)
        ->and($history)->toHaveCount(9);
    foreach (array_slice($history, 2) as $entry) {
        expect($entry['request']->getUri()->getPath())->toContain('/messages/immutable-draft%3D');
    }
    expect($history[0]['request']->getMethod())->toBe('GET')
        ->and($history[1]['request']->getUri()->getPath())->toEndWith('/messages/source%3D/'.($operation === ReplyForwardOperation::Reply ? 'createReply' : 'createForward'));
})->with([ReplyForwardOperation::Reply, ReplyForwardOperation::Forward]);

it('checks the original source again after complete attachment acknowledgement before sending', function (): void {
    $history = [];
    $bytes = 'hello';
    $mailbox = graphAttachmentMailbox([new Response(201, [], '{"id":"immutable-draft="}'), new Response(201, [], '{"id":"file="}')], $history);
    $file = new PreparedFile('chosen-file', 'chosen.txt', 'text/plain', 5,
        static function () use (&$bytes) { return Utils::streamFor($bytes); }, hash('sha256', 'hello'));
    $message = graphAttachmentMessage([$file]);
    $prepared = $mailbox->prepareAttachments($mailbox->prepareSubmission($message), $message);
    $bytes = 'hallo';

    expect($prepared->outcome)->toBe(PreparationOutcome::Prepared)
        ->and(fn () => $mailbox->submitPrepared(unserialize(serialize($prepared)), $message))->toThrow(MailboxException::class)
        ->and($history)->toHaveCount(2);
});

it('omits inherited HTTP credentials and queries while preserving trusted network policy for the exact upload URL', function (): void {
    $history = [];
    $expires = (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM);
    $tokens = new TestGrantTokenProvider(['grant' => ['connection' => 'connection', 'generation' => '1',
        'mailboxes' => ['sender@example.com'], 'token' => 'private-token']]);
    $mailbox = graphAttachmentMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(201, [], json_encode(['uploadUrl' => graphAttachmentUploadUrl(), 'expirationDateTime' => $expires, 'nextExpectedRanges' => ['0']], JSON_THROW_ON_ERROR)),
        new Response(307, ['Location' => 'https://attacker.example/private-upload-secret']),
    ], $history, ['headers' => ['aUtHoRiZaTiOn' => 'Bearer inherited-token', 'Cookie' => 'inherited-cookie'],
        'auth' => ['basic-user', 'basic-secret'], 'cookies' => CookieJar::fromArray(['private-cookie' => 'cookie-secret'], 'outlook.office.com'),
        'query' => ['default-secret' => 'default-query'], 'proxy' => 'http://trusted-proxy.example:8080', 'verify' => '/trusted/host-ca.pem',
        'timeout' => 7, 'connect_timeout' => 3], $tokens);
    $bytes = str_repeat('a', 3_000_001);
    $message = graphAttachmentMessage([new PreparedFile('chosen-file', 'chosen.bin', 'application/octet-stream', strlen($bytes),
        static fn () => Utils::streamFor($bytes), hash('sha256', $bytes))]);
    $session = $mailbox->prepareAttachments($mailbox->prepareSubmission($message), $message);
    $tokenReads = $tokens->tokenReads;

    $uncertain = $mailbox->prepareAttachments($session, $message);

    expect($uncertain->outcome)->toBe(PreparationOutcome::Uncertain)
        ->and($history)->toHaveCount(3)
        ->and($tokens->tokenReads)->toBe($tokenReads)
        ->and((string) $history[2]['request']->getUri())->toBe(graphAttachmentUploadUrl())
        ->and($history[2]['request']->hasHeader('Authorization'))->toBeFalse()
        ->and($history[2]['request']->hasHeader('Cookie'))->toBeFalse()
        ->and($history[2]['options']['proxy'])->toBe('http://trusted-proxy.example:8080')
        ->and($history[2]['options']['verify'])->toBe('/trusted/host-ca.pem')
        ->and($history[2]['options']['timeout'])->toBe(7)
        ->and($history[2]['options']['connect_timeout'])->toBe(3)
        ->and($history[2]['options']['allow_redirects'])->toBeFalse()
        ->and($uncertain->headers)->toBe([]);
});

it('leaves ambiguous or mismatched attachment evidence unresolved without any duplicate write', function (string $case): void {
    $history = [];
    $file = ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'candidate=',
        'name' => 'chosen.txt', 'contentType' => 'text/plain', 'size' => 5, 'isInline' => false, 'contentId' => null];
    $draft = graphAttachmentDraft([$file]);
    if ($case === 'duplicate') {
        $draft['attachments'][] = array_replace($file, ['id' => 'duplicate=']);
    } elseif ($case === 'changed CID') {
        $draft['attachments'][0]['contentId'] = 'another-cid';
    } elseif ($case === 'wrong draft') {
        $draft['id'] = 'another-draft=';
    } elseif ($case === 'incomplete list') {
        $draft['attachments@odata.nextLink'] = 'https://private-upload-secret';
    }
    $mailbox = graphAttachmentMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'), new Response(503),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)), new Response(200, [], 'hallo'),
    ], $history);
    $message = graphAttachmentMessage([new PreparedFile('chosen-file', 'chosen.txt', 'text/plain', 5,
        static fn () => Utils::streamFor('hello'), hash('sha256', 'hello'))]);
    $uncertain = $mailbox->prepareAttachments($mailbox->prepareSubmission($message), $message);

    $observed = $mailbox->inspectAttachments($uncertain, $message);

    expect($observed->outcome)->toBe(PreparationOutcome::Uncertain)
        ->and($observed->draftId)->toBe('immutable-draft=')
        ->and(fn () => $mailbox->prepareAttachments($observed, $message))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $mailbox->submitPrepared($observed, $message))->toThrow(InvalidArgumentException::class)
        ->and($history)->toHaveCount($case === 'changed bytes' ? 4 : 3);
})->with(['changed bytes', 'duplicate', 'changed CID', 'wrong draft', 'incomplete list']);

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
    $rebound = graphAttachmentMessage([new PreparedFile('mutable-host-file', 'chosen.bin', 'application/octet-stream', strlen($bytes),
        static fn () => Utils::streamFor($bytes), hash('sha256', $bytes))]);
    expect(fn () => $mailbox->prepareAttachments($prefix, $rebound))->toThrow(InvalidArgumentException::class)
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
 * @param array<string, mixed> $clientOptions
 */
function graphAttachmentMailbox(array $responses, array &$history, array $clientOptions = [], ?TestGrantTokenProvider $tokens = null): SupportsDraftAttachmentPreparation
{
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::history($history));

    return Mailbox::forGrant(new GrantContext('connection', 'grant', '1', 'ms-graph', 'sender@example.com'),
        $tokens ?? new TestGrantTokenProvider(['grant' => ['connection' => 'connection', 'generation' => '1',
            'mailboxes' => ['sender@example.com'], 'token' => 'private-token']]), new Client(['handler' => $handler, ...$clientOptions]));
}

<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Queue\Job;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Pyle\Mailbox\Contracts\SupportsDraftAttachmentPreparation;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\RateLimiter;
use Pyle\Mailbox\Drivers\MsGraph\TokenManager;
use Pyle\Mailbox\DTOs\AttachmentPreparation;
use Pyle\Mailbox\DTOs\ConversationRead;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\GraphUploadSession;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\DTOs\PreparedFile;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\ReplyForwardContext;
use Pyle\Mailbox\DTOs\SubmissionLimits;
use Pyle\Mailbox\DTOs\SubmissionPreparation;
use Pyle\Mailbox\DTOs\SyncIdentity;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Enums\PreparationOutcome;
use Pyle\Mailbox\Enums\ReplyForwardOperation;
use Pyle\Mailbox\Enums\SubmissionOutcome;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
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
    $untrustedHosts = ['foreign origin' => 'attacker.example',
        'Office suffix lookalike' => 'outlook.office.com.attacker.example', 'Office foreign subdomain' => 'uploads.outlook.office.com',
        'Office365 suffix lookalike' => 'outlook.office365.com.attacker.example', 'Office365 foreign subdomain' => 'uploads.outlook.office365.com'];
    if (isset($untrustedHosts[$case])) {
        $data['uploadUrl'] = str_replace('outlook.office.com', $untrustedHosts[$case], $data['uploadUrl']);
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
    foreach (['private-upload-secret', 'attacker.example', 'outlook.office.com', 'outlook.office365.com'] as $secret) {
        expect(json_encode($result, JSON_THROW_ON_ERROR).print_r($result, true))->not->toContain($secret);
    }
})->with([
    ['foreign origin', PreparationOutcome::Uncertain], ['expired', PreparationOutcome::Expired],
    ['missing initial offset', PreparationOutcome::Uncertain], ['invalid expiry', PreparationOutcome::Uncertain],
    ['malformed JSON', PreparationOutcome::Uncertain],
    ['Office suffix lookalike', PreparationOutcome::Uncertain], ['Office foreign subdomain', PreparationOutcome::Uncertain],
    ['Office365 suffix lookalike', PreparationOutcome::Uncertain], ['Office365 foreign subdomain', PreparationOutcome::Uncertain],
]);

it('requires the original captured session and authorization binding when restoring upload progress', function (string $case): void {
    $history = [];
    $expires = new DateTimeImmutable('+1 hour');
    $mailbox = graphAttachmentMailbox([new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(201, [], json_encode(['uploadUrl' => graphAttachmentUploadUrl(), 'expirationDateTime' => $expires->format(DATE_ATOM), 'nextExpectedRanges' => ['0']], JSON_THROW_ON_ERROR))], $history);
    $bytes = str_repeat('a', 3_000_001);
    $file = new PreparedFile('chosen-file', 'chosen.bin', 'application/octet-stream', strlen($bytes), static fn () => Utils::streamFor($bytes), hash('sha256', $bytes));
    $message = graphAttachmentMessage([$file]);
    $original = $mailbox->prepareAttachments($mailbox->prepareSubmission($message), $message);
    $session = $case === 'foreign grant same mailbox'
        ? new GraphUploadSession(new GrantContext('connection', 'another-grant', '1', 'ms-graph', 'sender@example.com'),
            'immutable-draft=', $file->identity, $file->byteLength, $file->fingerprint(), graphAttachmentUploadUrl(), $expires)
        : ($case === 'missing private state' ? null : $original->attachments[0]->session());
    $progress = [new AttachmentPreparation($file->identity, PreparationOutcome::Preparing, 0, null, 201, session: $session)];
    if ($case === 'non-list state') {
        $progress = [1 => $progress[0]];
    } elseif ($case === 'malformed file state') {
        $progress = [new stdClass];
    }
    $restored = new SubmissionPreparation($original->context, $original->outcome, $original->correlationId, $original->draftId,
        $original->status, $original->headers, $original->intentFingerprint(), $original->conversationId,
        $progress);

    expect(fn () => $mailbox->prepareAttachments(unserialize(serialize($restored)), $message))->toThrow(InvalidArgumentException::class)
        ->and($history)->toHaveCount(2);
})->with(['missing private state', 'foreign grant same mailbox', 'non-list state', 'malformed file state']);

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

            return FnStream::decorate($source, ['close' => static function () use ($source, &$closes): void {
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
        ->toThrow($case === 'write' ? ResponseLimitExceededException::class : UnsupportedCapabilityException::class)
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
        ->toThrow(ResponseLimitExceededException::class)
        ->and($opened)->toBeFalse()
        ->and($history)->toHaveCount(1);
});

it('retains the last acknowledged prefix and distinguishes final range failures without replay or secret output', function (mixed $final, PreparationOutcome $outcome, ?int $status, string $uploadHost = 'outlook.office.com'): void {
    $history = [];
    $uploadUrl = str_replace('outlook.office.com', $uploadHost, graphAttachmentUploadUrl());
    $job = Mockery::mock(Job::class);
    $job->shouldNotReceive('release');
    app()->instance('queue.job', $job);
    $expires = (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM);
    $mailbox = graphAttachmentMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(201, [], json_encode(['uploadUrl' => $uploadUrl, 'expirationDateTime' => $expires, 'nextExpectedRanges' => ['0-']], JSON_THROW_ON_ERROR)),
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
    foreach (['private-upload-secret', 'private-token', 'private-response', 'outlook.office.com', 'outlook.office365.com'] as $secret) {
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
    'Office session rejects Office365 completion' => [new Response(201, ['Location' => "https://outlook.office365.com/api/v2.0/Users('provider-user')/Messages('provider-draft')/Attachments('file=')"]), PreparationOutcome::Uncertain, 201],
    'Office365 session rejects Office completion' => [new Response(201, ['Location' => "https://outlook.office.com/api/v2.0/Users('provider-user')/Messages('provider-draft')/Attachments('file=')"]), PreparationOutcome::Uncertain, 201, 'outlook.office365.com'],
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
        static function () use (&$bytes) {
            return Utils::streamFor($bytes);
        }, hash('sha256', 'hello'));
    $message = graphAttachmentMessage([$file]);
    $prepared = $mailbox->prepareAttachments($mailbox->prepareSubmission($message), $message);
    $bytes = 'hallo';

    expect($prepared->outcome)->toBe(PreparationOutcome::Prepared)
        ->and(fn () => $mailbox->submitPrepared(unserialize(serialize($prepared)), $message))->toThrow(MailboxException::class)
        ->and($history)->toHaveCount(2);
});

it('omits inherited HTTP credentials and queries while preserving trusted network policy for the exact upload URL', function (): void {
    $history = [];
    $observations = 0;
    $securityObserver = static function (ResponseInterface $response) use (&$observations): void {
        $observations++;
    };
    $curlDefaults = $networkCurlDefaults = [];
    if (defined('CURLOPT_CONNECT_TO')) {
        $networkCurlDefaults = [CURLOPT_PROXYUSERPWD => 'trusted-proxy:proxy-secret',
            CURLOPT_CONNECT_TO => ['outlook.office.com:443:127.0.0.1:443'], CURLOPT_SSL_VERIFYPEER => true];
        $curlDefaults = [CURLOPT_USERPWD => 'raw-user:raw-secret', CURLOPT_HTTPHEADER => ['Authorization: Bearer raw-token'],
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_VERBOSE => true, CURLOPT_URL => 'https://attacker.example',
            CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => 'unselected-bytes',
            CURLOPT_READFUNCTION => static fn (): string => 'unselected-bytes'] + $networkCurlDefaults;
    }
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
        'timeout' => 7, 'connect_timeout' => 3, 'on_headers' => $securityObserver, 'curl' => $curlDefaults], $tokens);
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
        ->and($history[2]['options']['curl'])->toBe($networkCurlDefaults)
        ->and($history[2]['options']['on_headers'])->toBe($securityObserver)
        ->and($observations)->toBe(3)
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
    } elseif ($case === 'invalid provider size') {
        $draft['attachments'][0]['size'] = '5';
    } elseif ($case === 'changed CID') {
        $draft['attachments'][0]['contentId'] = 'another-cid';
    } elseif ($case === 'wrong draft') {
        $draft['id'] = 'another-draft=';
    } elseif ($case === 'incomplete list') {
        $draft['attachments@odata.nextLink'] = 'https://private-upload-secret';
    }
    $mailbox = graphAttachmentMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'), new Response(503),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)), new Response(200, [], $case === 'different raw length' ? 'hell' : 'hallo'),
    ], $history);
    $message = graphAttachmentMessage([new PreparedFile('chosen-file', 'chosen.txt', 'text/plain', 5,
        static fn () => Utils::streamFor('hello'), hash('sha256', 'hello'))]);
    $uncertain = $mailbox->prepareAttachments($mailbox->prepareSubmission($message), $message);

    $observed = $mailbox->inspectAttachments($uncertain, $message);

    expect($observed->outcome)->toBe(PreparationOutcome::Uncertain)
        ->and($observed->draftId)->toBe('immutable-draft=')
        ->and(fn () => $mailbox->prepareAttachments($observed, $message))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $mailbox->submitPrepared($observed, $message))->toThrow(InvalidArgumentException::class)
        ->and($history)->toHaveCount(in_array($case, ['changed bytes', 'different raw length'], true) ? 4 : 3);
})->with(['changed bytes', 'different raw length', 'invalid provider size', 'duplicate', 'changed CID', 'wrong draft', 'incomplete list']);

it('inspects and sends exact original file bytes despite a different provider size hint without another upload', function (): void {
    $history = [];
    $attachment = ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'observed-file=',
        'name' => 'chosen.txt', 'contentType' => 'text/plain', 'size' => 987_654, 'isInline' => false, 'contentId' => null];
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
        static function () use (&$bytes) {
            return Utils::streamFor($bytes);
        }, hash('sha256', $bytes));
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

it('retains each acknowledged Outlook upload step before sending another sequential range', function (string $basePath): void {
    $history = [];
    $ranges = [];
    $url = $basePath."/AttachmentSessions('session-1')?authtoken=private-upload-secret";
    $expires = (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM);
    $bytes = str_repeat('a', 3_000_000).'z';
    $mailbox = graphAttachmentMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(201, [], json_encode(['uploadUrl' => $url, 'expirationDateTime' => $expires, 'nextExpectedRanges' => ['0-']], JSON_THROW_ON_ERROR)),
        static function (RequestInterface $request) use (&$ranges, $expires): Response {
            $ranges[] = ['range' => $request->getHeaderLine('Content-Range'), 'bytes' => (string) $request->getBody()];

            return new Response(200, [], json_encode(['ExpirationDateTime' => $expires, 'nextExpectedRanges' => ['3000000']], JSON_THROW_ON_ERROR));
        },
        static function (RequestInterface $request) use (&$ranges, $basePath): Response {
            $ranges[] = ['range' => $request->getHeaderLine('Content-Range'), 'bytes' => (string) $request->getBody()];

            return new Response(201, ['Location' => $basePath."/Attachments('large-attachment=')"]);
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
        expect($public)->not->toContain('outlook.office.com')->not->toContain('outlook.office365.com');
        expect($public)->not->toContain('private-token');
    }
})->with([
    'legacy v2.0' => "https://outlook.office.com/api/v2.0/Users('provider-user')/Messages('provider-draft')",
    'documented v1.0' => "https://outlook.office.com/api/v1.0/Users('provider-user')/Messages('provider-draft')",
    'documented gv1.0' => "https://outlook.office.com/api/gv1.0/users('provider-user')/messages('provider-draft')",
    'issued Office365 gv1.0' => "https://outlook.office365.com/api/gv1.0/users('provider-user')/messages('provider-draft')",
]);

it('retains a small file acknowledgement containing the posted content bytes', function (): void {
    $history = [];
    $bytes = str_repeat('a', 49_152);
    $receiptBytes = 0;
    $mailbox = graphAttachmentMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        static function (RequestInterface $request) use ($bytes, &$receiptBytes): Response {
            $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
            expect(base64_decode($payload['contentBytes'], true))->toBe($bytes);
            $receipt = json_encode(['id' => 'echoed-file=', ...$payload], JSON_THROW_ON_ERROR);
            $receiptBytes = strlen($receipt);

            return new Response(201, ['request-id' => 'echoed-attachment'], $receipt);
        },
    ], $history);
    $message = graphAttachmentMessage([new PreparedFile('chosen-file', 'chosen.txt', 'text/plain', strlen($bytes),
        static fn () => Utils::streamFor($bytes), hash('sha256', $bytes))]);
    $draft = $mailbox->prepareSubmission($message);

    $result = $mailbox->prepareAttachments($draft, $message);

    expect($receiptBytes)->toBeGreaterThan(65_536);
    expect($result->outcome)->toBe(PreparationOutcome::Prepared)
        ->and($result->attachments[0]->attachmentId)->toBe('echoed-file=')
        ->and($result->attachments[0]->acknowledgedBytes)->toBe(49_152)
        ->and($result->headers)->toBe(['request-id' => ['echoed-attachment']]);
    expect($history)->toHaveCount(2);
});

it('honors an explicit small file wire budget including escaped UTF-8 metadata above four million bytes', function (): void {
    $history = [];
    $bytes = str_repeat('a', 2_999_999);
    $name = str_repeat('😀', 196).'.txt';
    $requestBytes = 0;
    $mailbox = graphAttachmentMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        static function (RequestInterface $request) use ($name, $bytes, &$requestBytes): Response {
            $body = (string) $request->getBody();
            $requestBytes = strlen($body);
            expect($body)->toContain('\\ud83d\\ude00');
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            expect($payload['name'])->toBe($name);
            expect(hash('sha256', base64_decode($payload['contentBytes'], true)))->toBe(hash('sha256', $bytes));

            return new Response(201, [], json_encode(['id' => 'boundary-file=', ...$payload], JSON_THROW_ON_ERROR));
        },
    ], $history);
    $message = graphAttachmentMessage([new PreparedFile('chosen-file', $name, 'text/plain', strlen($bytes),
        static fn () => Utils::streamFor($bytes), hash('sha256', $bytes))]);
    $draft = $mailbox->prepareSubmission($message);

    $result = $mailbox->prepareAttachments($draft, $message,
        new SubmissionLimits(maxMemoryBytes: 16_777_216), maxRequestBytes: 4_194_304);

    expect(mb_strlen($name))->toBe(200);
    expect($requestBytes)->toBeGreaterThan(4_000_000)->toBeLessThanOrEqual(4_194_304);
    expect($result->outcome)->toBe(PreparationOutcome::Prepared)
        ->and($result->attachments[0]->acknowledgedBytes)->toBe(2_999_999)
        ->and($result->attachments[0]->attachmentId)->toBe('boundary-file=');
    expect($history)->toHaveCount(2);
});

it('retains the default and tighter small file request budgets when escaped metadata crosses the bound', function (?int $requestLimit): void {
    $history = [];
    $bytes = str_repeat('a', 2_999_000);
    $name = str_repeat('😀', 196).'.txt';
    $mailbox = graphAttachmentMailbox([new Response(201, [], '{"id":"immutable-draft="}')], $history);
    $message = graphAttachmentMessage([new PreparedFile('chosen-file', $name, 'text/plain', strlen($bytes),
        static fn () => Utils::streamFor($bytes), hash('sha256', $bytes))]);
    $draft = $mailbox->prepareSubmission($message);

    $attach = $requestLimit === null
        ? fn () => $mailbox->prepareAttachments($draft, $message)
        : fn () => $mailbox->prepareAttachments($draft, $message, maxRequestBytes: $requestLimit);

    expect($attach)->toThrow(ResponseLimitExceededException::class);
    expect($history)->toHaveCount(1);
})->with(['unchanged default' => null, 'explicit default' => 4_000_000, 'tighter caller budget' => 3_999_999]);

it('keeps an attachment error response within the original sixty four KiB receipt budget', function (): void {
    $history = [];
    $bytes = str_repeat('a', 49_152);
    $read = 0;
    $closed = false;
    $input = Utils::streamFor(str_repeat('x', 70_000));
    $stream = FnStream::decorate($input, [
        'read' => static function (int $length) use ($input, &$read): string {
            $part = $input->read($length);
            $read += strlen($part);

            return $part;
        },
        'close' => static function () use ($input, &$closed): void {
            $closed = true;
            $input->close();
        },
    ]);
    $mailbox = graphAttachmentMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(429, ['request-id' => 'bounded-rejection', 'Retry-After' => '9'], $stream),
    ], $history);
    $message = graphAttachmentMessage([new PreparedFile('chosen-file', 'chosen.txt', 'text/plain', strlen($bytes),
        static fn () => Utils::streamFor($bytes), hash('sha256', $bytes))]);
    $draft = $mailbox->prepareSubmission($message);

    $result = $mailbox->prepareAttachments($draft, $message);

    expect($result->outcome)->toBe(PreparationOutcome::Rejected)
        ->and($result->status)->toBe(429)
        ->and($result->headers)->toBe(['retry-after' => ['9'], 'request-id' => ['bounded-rejection']])
        ->and($result->attachments[0]->attachmentId)->toBeNull();
    expect($read)->toBe(65_537);
    expect($closed)->toBeTrue();
    expect($history)->toHaveCount(2);
});

it('keeps an oversized successful attachment receipt uncertain within its finite response budget', function (): void {
    $history = [];
    $bytes = str_repeat('a', 49_152);
    $read = 0;
    $closed = false;
    $input = Utils::streamFor(str_repeat('x', 180_000));
    $stream = FnStream::decorate($input, [
        'read' => static function (int $length) use ($input, &$read): string {
            $part = $input->read($length);
            $read += strlen($part);

            return $part;
        },
        'close' => static function () use ($input, &$closed): void {
            $closed = true;
            $input->close();
        },
    ]);
    $mailbox = graphAttachmentMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(201, ['request-id' => 'bounded-success'], $stream),
    ], $history);
    $message = graphAttachmentMessage([new PreparedFile('chosen-file', 'chosen.txt', 'text/plain', strlen($bytes),
        static fn () => Utils::streamFor($bytes), hash('sha256', $bytes))]);
    $draft = $mailbox->prepareSubmission($message);

    $result = $mailbox->prepareAttachments($draft, $message);

    expect($result->outcome)->toBe(PreparationOutcome::Uncertain)
        ->and($result->status)->toBe(201)
        ->and($result->headers)->toBe(['request-id' => ['bounded-success']])
        ->and($result->attachments[0]->attachmentId)->toBeNull();
    expect($read)->toBeGreaterThan(65_536)->toBeLessThan(180_000);
    expect($closed)->toBeTrue();
    expect($history)->toHaveCount(2);
});

it('inspects original attachment bytes after a truncated echoed acknowledgement without posting the file again', function (): void {
    $history = [];
    $bytes = str_repeat('a', 49_152);
    $file = ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'observed-file=',
        'name' => 'chosen.txt', 'contentType' => 'text/plain', 'size' => strlen($bytes), 'isInline' => false, 'contentId' => null];
    $mailbox = graphAttachmentMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        static function (RequestInterface $request): Response {
            $payload = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $body = json_encode(['id' => 'observed-file=', ...$payload], JSON_THROW_ON_ERROR);

            return new Response(201, ['Content-Length' => (string) (strlen($body) + 1), 'request-id' => 'truncated-echo'], $body);
        },
        new Response(200, [], json_encode(graphAttachmentDraft([$file]), JSON_THROW_ON_ERROR)),
        new Response(200, [], $bytes),
    ], $history);
    $message = graphAttachmentMessage([new PreparedFile('chosen-file', 'chosen.txt', 'text/plain', strlen($bytes),
        static fn () => Utils::streamFor($bytes), hash('sha256', $bytes))]);
    $draft = $mailbox->prepareSubmission($message);
    $uncertain = $mailbox->prepareAttachments($draft, $message);
    expect($uncertain->outcome)->toBe(PreparationOutcome::Uncertain)
        ->and($uncertain->attachments[0]->attachmentId)->toBeNull();
    expect(fn () => $mailbox->prepareAttachments($uncertain, $message))->toThrow(InvalidArgumentException::class);

    $observed = $mailbox->inspectAttachments(unserialize(serialize($uncertain)), $message);

    expect($observed->outcome)->toBe(PreparationOutcome::Prepared)
        ->and($observed->draftId)->toBe('immutable-draft=')
        ->and($observed->attachments[0]->attachmentId)->toBe('observed-file=')
        ->and($observed->attachments[0]->acknowledgedBytes)->toBe(49_152);
    expect(array_map(static fn (array $entry): string => $entry['request']->getMethod(), $history))->toBe(['POST', 'POST', 'GET', 'GET']);
    expect($history[3]['request']->getUri()->getPath())->toEndWith('/attachments/observed-file%3D/$value');
});

it('rejects nonpositive optional successful response budgets before provider IO', function (int $limit): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([]));
    $stack->push(Middleware::history($history));
    $client = new GraphClient([], new TokenManager([]),
        new RateLimiter([]), new Client(['handler' => $stack]));

    expect(fn () => $client->requestOnceBudgeted('POST', 'users/sender%40example.com/messages', maxSuccessResponseBytes: $limit))
        ->toThrow(InvalidArgumentException::class);
    expect($history)->toBe([]);
})->with(['zero' => 0, 'negative' => -1]);

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
 * @param  list<array<string, mixed>>  $attachments
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
 * @param  list<mixed>  $responses
 * @param  array<int, mixed>  $history
 * @param  array<string, mixed>  $clientOptions
 */
function graphAttachmentMailbox(array $responses, array &$history, array $clientOptions = [], ?TestGrantTokenProvider $tokens = null): SupportsDraftAttachmentPreparation
{
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::history($history));

    return Mailbox::forGrant(new GrantContext('connection', 'grant', '1', 'ms-graph', 'sender@example.com'),
        $tokens ?? new TestGrantTokenProvider(['grant' => ['connection' => 'connection', 'generation' => '1',
            'mailboxes' => ['sender@example.com'], 'token' => 'private-token']]), new Client(['handler' => $handler, ...$clientOptions]));
}

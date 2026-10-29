<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use Pyle\Mailbox\Contracts\SupportsDraftSubmission;
use Pyle\Mailbox\Contracts\SupportsDraftSubmissionInspection;
use Pyle\Mailbox\DTOs\ConversationRead;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\SubmissionPreparation;
use Pyle\Mailbox\DTOs\ReplyForwardContext;
use Pyle\Mailbox\DTOs\SyncIdentity;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\DTOs\SyncContinuation;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\Enums\PreparationOutcome;
use Pyle\Mailbox\Enums\ReplyForwardOperation;
use Pyle\Mailbox\Enums\SubmissionOutcome;
use Pyle\Mailbox\Enums\SubmissionEvidenceOutcome;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('prepares an explicit native Graph reply without inheriting recipients or changing the source', function (): void {
    $history = [];
    $draft = nativeGraphDraft();
    $inherited = array_replace($draft, [
        'toRecipients' => [['emailAddress' => ['name' => 'Inherited', 'address' => 'inherited@example.com']]],
        'bccRecipients' => [['emailAddress' => ['name' => 'Historical', 'address' => 'historical@example.com']]],
        'body' => ['contentType' => 'text', 'content' => 'Inherited quoted history'],
    ]);
    $mailbox = nativeGraphMailbox([
        new Response(200, [], json_encode(nativeGraphSource(), JSON_THROW_ON_ERROR)),
        new Response(201, [], json_encode($inherited, JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"value":[]}'),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(202),
    ], $history);
    $message = nativeGraphMessage();

    $preparation = $mailbox->prepareSubmission($message);

    expect($preparation->outcome)->toBe(PreparationOutcome::Prepared)
        ->and($preparation->draftId)->toBe('new-draft=')
        ->and($preparation->correlationId)->toBe('native-operation');
    $result = $mailbox->submitPrepared($preparation, $message);
    expect($result->outcome)->toBe(SubmissionOutcome::Accepted);
    $requests = array_column($history, 'request');
    expect(array_map(static fn ($request): string => $request->getMethod(), $requests))->toBe(['GET', 'POST', 'GET', 'PATCH', 'GET', 'POST'])
        ->and($requests[0]->getUri()->getPath())->toEndWith('/mailFolders/Inbox/messages/source%3D')
        ->and($requests[1]->getUri()->getPath())->toEndWith('/mailFolders/Inbox/messages/source%3D/createReply')
        ->and($requests[2]->getUri()->getPath())->toEndWith('/messages/new-draft%3D/attachments')
        ->and($requests[3]->getUri()->getPath())->toEndWith('/messages/new-draft%3D')
        ->and($requests[5]->getUri()->getPath())->toEndWith('/messages/new-draft%3D/send');
    $normalized = json_decode((string) $requests[3]->getBody(), true, flags: JSON_THROW_ON_ERROR);
    expect($normalized['toRecipients'])->toBe([['emailAddress' => ['name' => 'Chosen', 'address' => 'chosen@example.com']]])
        ->and($normalized['ccRecipients'])->toBe([])
        ->and($normalized['bccRecipients'])->toBe([])
        ->and($normalized['body'])->toBe(['contentType' => 'Text', 'content' => 'Chosen content']);
    foreach ($requests as $request) {
        expect($request->getHeaderLine('Prefer'))->toContain('IdType="ImmutableId"');
    }
});

it('removes only copied native forward attachments before sending caller chosen content', function (): void {
    $history = [];
    $draft = array_replace(nativeGraphDraft(), ['conversationId' => 'forward-conversation']);
    $mailbox = nativeGraphMailbox([
        new Response(200, [], json_encode(nativeGraphSource(), JSON_THROW_ON_ERROR)),
        new Response(201, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"value":[{"id":"copied-inline="},{"id":"copied-document="}]}'),
        new Response(204), new Response(204),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(202),
    ], $history);
    $base = nativeGraphMessage();
    $message = nativeGraphMessage(['context' => new ReplyForwardContext(ReplyForwardOperation::Forward, $base->context->conversation, 'source=')]);

    $preparation = $mailbox->prepareSubmission($message);
    $result = $mailbox->submitPrepared(unserialize(serialize($preparation)), $message);

    expect($preparation->outcome)->toBe(PreparationOutcome::Prepared)
        ->and($preparation->conversationId)->toBe('forward-conversation')
        ->and($result->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($history)->toHaveCount(8)
        ->and($history[1]['request']->getUri()->getPath())->toEndWith('/source%3D/createForward');
    foreach ([3 => 'copied-inline%3D', 4 => 'copied-document%3D'] as $index => $id) {
        expect($history[$index]['request']->getMethod())->toBe('DELETE')
            ->and($history[$index]['request']->getUri()->getPath())->toEndWith('/messages/new-draft%3D/attachments/'.$id);
    }
    $created = json_decode((string) $history[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $normalized = json_decode((string) $history[5]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    expect($created['message']['internetMessageHeaders'])->toBe([['name' => 'X-Mailbox-Correlation-Id', 'value' => 'native-operation']])
        ->and($normalized)->not->toHaveKey('internetMessageHeaders')
        ->and($normalized['body']['content'])->toBe('Chosen content')
        ->and($normalized['bccRecipients'])->toBe([]);
});

it('retains native preparation uncertainty and its draft identity after interrupted copied file cleanup', function (): void {
    $history = [];
    $mailbox = nativeGraphMailbox([
        new Response(200, [], json_encode(nativeGraphSource(), JSON_THROW_ON_ERROR)),
        new Response(201, [], json_encode(nativeGraphDraft(), JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"value":[{"id":"copied-inline="},{"id":"copied-document="}]}'),
        new ConnectException('private-token timeout', new Request('DELETE', 'https://private-upload')),
        new Response(204), new Response(202),
    ], $history);
    $message = nativeGraphMessage();

    $preparation = $mailbox->prepareSubmission($message);

    expect($preparation->outcome)->toBe(PreparationOutcome::Uncertain)
        ->and($preparation->draftId)->toBe('new-draft=')
        ->and($preparation->status)->toBeNull()
        ->and(fn () => $mailbox->submitPrepared($preparation, $message))->toThrow(InvalidArgumentException::class)
        ->and($history)->toHaveCount(4);
    expect(json_encode($preparation, JSON_THROW_ON_ERROR))->not->toContain('private-token')->not->toContain('private-upload');
});

it('inspects the same immutable sent artifact without changing prior acceptance or resending after a later missing copy', function (): void {
    $history = [];
    $draft = nativeGraphDraft();
    $sent = array_replace($draft, ['isDraft' => false, 'sentDateTime' => '2026-10-29T12:30:00Z']);
    $mailbox = nativeGraphMailbox([
        new Response(200, [], json_encode(nativeGraphSource(), JSON_THROW_ON_ERROR)),
        new Response(201, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"value":[]}'),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)), new Response(202, ['request-id' => 'accepted-send']),
        new Response(200, ['request-id' => 'observed-copy'], json_encode($sent, JSON_THROW_ON_ERROR)),
        new Response(404, ['request-id' => 'missing-copy']),
    ], $history);
    $message = nativeGraphMessage();
    $preparation = $mailbox->prepareSubmission($message);
    $accepted = $mailbox->submitPrepared($preparation, $message);

    expect($mailbox)->toBeInstanceOf(SupportsDraftSubmissionInspection::class);
    $observed = $mailbox->inspectSubmission($preparation, $message);
    $missing = $mailbox->inspectSubmission($preparation, $message);

    expect($observed->outcome)->toBe(SubmissionEvidenceOutcome::SentCopyObserved)
        ->and($observed->artifactId)->toBe('new-draft=')
        ->and($observed->correlationId)->toBe('native-operation')
        ->and($observed->context)->toBe($preparation->context)
        ->and($observed->conversationId)->toBe('source-conversation')
        ->and($observed->status)->toBe(200)
        ->and($observed->headers)->toBe(['request-id' => ['observed-copy']])
        ->and($missing->outcome)->toBe(SubmissionEvidenceOutcome::Inconclusive)
        ->and($missing->artifactId)->toBe('new-draft=')
        ->and($missing->status)->toBe(404)
        ->and($accepted->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($accepted->headers)->toBe(['request-id' => ['accepted-send']])
        ->and($preparation->outcome)->toBe(PreparationOutcome::Prepared)
        ->and($history)->toHaveCount(8);
    foreach (array_slice($history, 6) as $entry) {
        expect($entry['request']->getMethod())->toBe('GET')
            ->and($entry['request']->getUri()->getPath())->toEndWith('/messages/new-draft%3D')
            ->and($entry['request']->getHeaderLine('Prefer'))->toContain('IdType="ImmutableId"');
    }
});

it('leaves uncertain Graph sends unresolved for delayed malformed or mismatched sent copies without replay', function (mixed $lookup): void {
    $history = [];
    $draft = nativeGraphDraft();
    $copy = array_replace($draft, ['isDraft' => false, 'sentDateTime' => '2026-10-29T12:30:00Z']);
    if (is_array($lookup)) {
        $lookup = new Response(200, ['request-id' => 'lookup-1'], json_encode(array_replace($copy, $lookup), JSON_THROW_ON_ERROR));
    }
    $mailbox = nativeGraphMailbox([
        new Response(200, [], json_encode(nativeGraphSource(), JSON_THROW_ON_ERROR)),
        new Response(201, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"value":[]}'),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new ConnectException('private-token timeout', new Request('POST', 'https://private-upload')),
        $lookup, new Response(202),
    ], $history);
    $message = nativeGraphMessage();
    $preparation = $mailbox->prepareSubmission($message);
    $uncertain = $mailbox->submitPrepared($preparation, $message);

    $evidence = $mailbox->inspectSubmission(unserialize(serialize($preparation)), $message);

    expect($evidence->outcome)->toBe(SubmissionEvidenceOutcome::Inconclusive)
        ->and($evidence->artifactId)->toBe('new-draft=')
        ->and($evidence->correlationId)->toBe('native-operation')
        ->and($evidence->conversationId)->toBe('source-conversation')
        ->and($uncertain->outcome)->toBe(SubmissionOutcome::Uncertain)
        ->and($preparation->outcome)->toBe(PreparationOutcome::Prepared)
        ->and($history)->toHaveCount(7)
        ->and($history[6]['request']->getMethod())->toBe('GET');
    $public = json_encode($evidence, JSON_THROW_ON_ERROR).print_r($evidence, true);
    expect($public)->not->toContain('private-token')->not->toContain('private-upload')->not->toContain('private-response');
})->with([
    'delayed draft' => [['isDraft' => true]],
    'wrong artifact' => [['id' => 'another-draft=']],
    'wrong conversation' => [['conversationId' => 'other-conversation']],
    'wrong correlation' => [['internetMessageHeaders' => [['name' => 'x-mailbox-correlation-id', 'value' => 'other-operation']]]],
    'duplicate correlation' => [['internetMessageHeaders' => [['name' => 'x-mailbox-correlation-id', 'value' => 'native-operation'], ['name' => 'X-Mailbox-Correlation-Id', 'value' => 'native-operation']]]],
    'wrong recipients' => [['toRecipients' => []]],
    'inherited Bcc' => [['bccRecipients' => [['emailAddress' => ['address' => 'historical@example.com']]]]],
    'changed content' => [['body' => ['contentType' => 'text', 'content' => 'Changed after submission']]],
    'copied inline file' => [['attachments' => [['id' => 'unselected-inline']]]],
    'missing file list' => [['attachments' => null]],
    'malformed file list' => [['attachments' => (object) []]],
    'unfinished file list' => [['attachments@odata.nextLink' => 'https://private-upload']],
    'missing sent date' => [['sentDateTime' => null]],
    'malformed sent date' => [['sentDateTime' => '2026-02-31T12:00:00Z']],
    'missing copy' => [new Response(404, ['request-id' => 'missing-1'], 'private-response')],
    'server interrupted' => [new Response(503, ['Location' => 'https://private-upload'], 'private-response')],
    'lost lookup acknowledgement' => [new ConnectException('private-token timeout', new Request('GET', 'https://private-upload'))],
    'malformed JSON' => [new Response(200, [], '{')],
    'oversized copy' => [new Response(200, [], str_repeat('a', 4_194_305))],
    'encoded copy' => [new Response(200, ['Content-Encoding' => 'gzip'], gzencode('private-response'))],
]);

it('rejects incomplete or mismatched native context before provider work', function (string $change): void {
    $history = [];
    $base = nativeGraphMessage()->context->conversation;
    $grant = get_object_vars($base->identity->context);
    if (array_key_exists($change, $grant)) {
        $grant[$change] = 'another-context';
    }
    $identity = new SyncIdentity($change === 'provider' ? 'gmail' : 'ms-graph', new GrantContext(...$grant), $base->identity->scope, $change === 'version' ? 2 : 1);
    $source = nativeGraphSource();
    if ($change === 'body') {
        unset($source['body']);
    }
    if ($change === 'source conversation') {
        $source['conversationId'] = 'another-conversation';
    }
    $read = new ConversationRead($identity, 'source-conversation',
        $change === 'missing source' ? [] : [MessageDto::fromMsGraph($source)],
        complete: $change !== 'incomplete', continuation: $change === 'incomplete' ? new SyncContinuation($identity, 'ms-graph.conversation', []) : null);
    $message = nativeGraphMessage(['context' => new ReplyForwardContext(ReplyForwardOperation::Reply, $read, $change === 'unsafe ID' ? '../source' : 'source=')]);
    $mailbox = nativeGraphMailbox([], $history);

    expect(fn () => $mailbox->prepareSubmission($message))->toThrow(UnsupportedCapabilityException::class)
        ->and($history)->toBe([]);
})->with(['connectionId', 'grantId', 'lifecycleGeneration', 'driver', 'mailbox', 'provider', 'version', 'body', 'source conversation', 'missing source', 'incomplete', 'unsafe ID']);

it('stops stale or unavailable source context before creating a native draft', function (mixed $source): void {
    $history = [];
    if (is_array($source)) {
        $source = new Response(200, ['request-id' => 'source-1'], json_encode(array_replace(nativeGraphSource(), $source), JSON_THROW_ON_ERROR));
    }
    $mailbox = nativeGraphMailbox([$source, new Response(201, [], json_encode(nativeGraphDraft(), JSON_THROW_ON_ERROR))], $history);

    try {
        $mailbox->prepareSubmission(nativeGraphMessage());
        test()->fail('Unavailable source context must stop before draft creation.');
    } catch (ApiRequestException $exception) {
        $public = $exception->getMessage().json_encode($exception->headers, JSON_THROW_ON_ERROR);
        expect($public)->not->toContain('private-token')->not->toContain('private-response')->not->toContain('private-upload');
    }
    expect($history)->toHaveCount(1)
        ->and($history[0]['request']->getMethod())->toBe('GET');
})->with([
    'moved source' => [new Response(404, ['request-id' => 'missing-source'], 'private-response')],
    'forbidden scope' => [new Response(403, [], 'private-response')],
    'wrong source' => [['id' => 'other-source=']],
    'wrong conversation' => [['conversationId' => 'other-conversation']],
    'source is draft' => [['isDraft' => true]],
    'malformed source' => [new Response(200, [], '{}')],
]);

it('keeps missing native creation acknowledgements distinct from send uncertainty without touching a possible original', function (mixed $created): void {
    $history = [];
    if (is_array($created)) {
        $created = new Response(201, ['request-id' => 'native-create'], json_encode(array_replace(nativeGraphDraft(), $created), JSON_THROW_ON_ERROR));
    }
    $mailbox = nativeGraphMailbox([
        new Response(200, [], json_encode(nativeGraphSource(), JSON_THROW_ON_ERROR)), $created,
        new Response(202),
    ], $history);
    $message = nativeGraphMessage();

    $preparation = $mailbox->prepareSubmission($message);

    expect($preparation->outcome)->toBe(PreparationOutcome::Uncertain)
        ->and($preparation->draftId)->toBeNull()
        ->and(fn () => $mailbox->submitPrepared($preparation, $message))->toThrow(InvalidArgumentException::class)
        ->and($history)->toHaveCount(2);
})->with([
    'lost create' => [new ConnectException('private-token timeout', new Request('POST', 'https://private-upload'))],
    'interrupted create' => [new Response(503, [], 'private-response')],
    'missing identity' => [['id' => null]],
    'original identity' => [['id' => 'source=']],
    'wrong reply conversation' => [['conversationId' => 'different-conversation']],
    'unacknowledged draft' => [['isDraft' => null]],
]);

it('retains a known native draft without mutations when inherited file metadata is unfinished malformed or over budget', function (mixed $files): void {
    $history = [];
    $mailbox = nativeGraphMailbox([
        new Response(200, [], json_encode(nativeGraphSource(), JSON_THROW_ON_ERROR)),
        new Response(201, [], json_encode(nativeGraphDraft(), JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode($files, JSON_THROW_ON_ERROR)), new Response(202),
    ], $history);
    $message = nativeGraphMessage();

    $preparation = $mailbox->prepareSubmission($message);

    expect($preparation->outcome)->toBe(PreparationOutcome::Uncertain)
        ->and($preparation->draftId)->toBe('new-draft=')
        ->and(fn () => $mailbox->submitPrepared($preparation, $message))->toThrow(InvalidArgumentException::class)
        ->and($history)->toHaveCount(3);
})->with([
    'unfinished page' => [['value' => [['id' => 'copied=']], '@odata.nextLink' => 'https://private-upload']],
    'missing list' => [(object) []],
    'object list' => [['value' => (object) []]],
    'malformed ID' => [['value' => [['id' => '../source']]]],
    'duplicate ID' => [['value' => [['id' => 'copied='], ['id' => 'copied=']]]],
    'too many files' => [['value' => array_map(static fn (int $id): array => ['id' => 'file-'.$id], range(1, 1001))]],
]);

it('uses complete public scoped conversation content while leaving original file selection untouched', function (): void {
    $history = [];
    $draft = nativeGraphDraft();
    $mailbox = nativeGraphMailbox([
        new Response(200, [], json_encode(['value' => [nativeGraphSource()]], JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"value":[{"id":"original-file=","name":"original.txt","contentType":"text/plain","size":8,"isInline":false}]}'),
        new Response(200, [], json_encode(nativeGraphSource(), JSON_THROW_ON_ERROR)),
        new Response(201, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"value":[{"id":"copied-file="}]}'), new Response(204),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)), new Response(202),
    ], $history);
    $read = $mailbox->conversation('source-conversation', new SyncScope('Inbox'), new SyncBudget(maxPages: 2, maxRequests: 2));
    $message = nativeGraphMessage(['context' => new ReplyForwardContext(ReplyForwardOperation::Forward, $read, 'source=')]);

    $preparation = $mailbox->prepareSubmission($message);
    $result = $mailbox->submitPrepared($preparation, $message);

    expect($read->complete)->toBeTrue()
        ->and($read->messages[0]->body->content)->toBe('Original content')
        ->and($read->files[0]['attachment']->id)->toBe('original-file=')
        ->and($message->files)->toBe([])
        ->and($result->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($history)->toHaveCount(9);
    foreach ($history as $entry) {
        $request = $entry['request'];
        if ($request->getMethod() !== 'GET' && ! str_ends_with($request->getUri()->getPath(), '/createForward')) {
            expect($request->getUri()->getPath())->toContain('/messages/new-draft%3D');
        }
    }
});

it('binds the operation source and trusted scope in retained native intent without hashing unrelated history', function (string $change): void {
    $history = [];
    $draft = nativeGraphDraft();
    $mailbox = nativeGraphMailbox([
        new Response(200, [], json_encode(nativeGraphSource(), JSON_THROW_ON_ERROR)),
        new Response(201, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"value":[]}'), new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)), new Response(202),
    ], $history);
    $message = nativeGraphMessage();
    $preparation = $mailbox->prepareSubmission($message);
    $read = $message->context->conversation;
    $source = MessageDto::fromMsGraph(array_replace(nativeGraphSource(), ['id' => 'other-source=']));
    $scope = new SyncScope($change === 'folder' ? 'Archive' : 'Inbox', $change === 'cutoff' ? new DateTimeImmutable('2026-10-01T00:00:00Z') : null);
    $changed = new ConversationRead(new SyncIdentity('ms-graph', $read->identity->context, $scope), 'source-conversation', [...$read->messages, $source], complete: true);
    $message = nativeGraphMessage(['context' => new ReplyForwardContext($change === 'operation' ? ReplyForwardOperation::Forward : ReplyForwardOperation::Reply, $changed, $change === 'source' ? 'other-source=' : 'source=')]);

    if ($change === 'unrelated history') {
        expect($mailbox->submitPrepared($preparation, $message)->outcome)->toBe(SubmissionOutcome::Accepted)
            ->and($history)->toHaveCount(6);

        return;
    }
    expect(fn () => $mailbox->submitPrepared($preparation, $message))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $mailbox->inspectSubmission($preparation, $message))->toThrow(InvalidArgumentException::class)
        ->and($history)->toHaveCount(4);
})->with(['operation', 'source', 'folder', 'cutoff', 'unrelated history']);

it('checks the selected grant again before each native preparation send and inspection phase', function (string $phase, int $expectedRequests): void {
    $history = [];
    $tokens = new TestGrantTokenProvider(['grant' => [
        'connection' => 'connection', 'generation' => '1', 'mailboxes' => ['sender@example.com'], 'token' => 'private-token',
    ]]);
    $response = static function (int $status, mixed $payload, string $nextPhase) use ($tokens, $phase): Closure {
        return static function () use ($status, $payload, $nextPhase, $tokens, $phase): Response {
            if ($phase === $nextPhase) {
                $tokens->grants['grant']['generation'] = '2';
            }

            return new Response($status, [], $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR));
        };
    };
    $mailbox = nativeGraphMailbox([
        $response(200, nativeGraphSource(), 'creation'),
        $response(201, nativeGraphDraft(), 'file inspection'),
        $response(200, ['value' => $phase === 'deletion' ? [['id' => 'copied=']] : []], $phase === 'deletion' ? 'deletion' : 'normalization'),
        $response(200, nativeGraphDraft(), 'verification'),
        $response(200, nativeGraphDraft(), 'send'),
        $response(202, null, 'inspection'),
    ], $history, $tokens);
    $message = nativeGraphMessage();
    if ($phase === 'preparation') {
        $tokens->grants['grant']['generation'] = '2';
    }

    expect(function () use ($mailbox, $message, $phase): void {
        $preparation = $mailbox->prepareSubmission($message);
        if (in_array($phase, ['verification', 'send', 'inspection'], true)) {
            $mailbox->submitPrepared($preparation, $message);
        }
        if ($phase === 'inspection') {
            $mailbox->inspectSubmission($preparation, $message);
        }
    })->toThrow(GrantAccessDeniedException::class)
        ->and($history)->toHaveCount($expectedRequests);
})->with([
    'preparation' => ['preparation', 0], 'creation' => ['creation', 1], 'file inspection' => ['file inspection', 2],
    'deletion' => ['deletion', 3], 'normalization' => ['normalization', 3], 'verification' => ['verification', 4],
    'send' => ['send', 5], 'inspection' => ['inspection', 6],
]);

it('preserves explicit native HTML recipients and supported harmless normalization through sent copy inspection', function (): void {
    $history = [];
    $message = nativeGraphMessage(['textBody' => null, 'htmlBody' => "<p>Résumé</p>\n<p>Chosen</p>",
        'cc' => [new EmailAddressDto('Copy', 'copy@example.com')],
        'bcc' => [new EmailAddressDto('Private', 'private@example.com')], 'headers' => ['X-Project' => 'Support']]);
    $draft = array_replace(nativeGraphDraft(), [
        'ccRecipients' => [['emailAddress' => ['name' => 'Copy', 'address' => 'copy@EXAMPLE.COM']]],
        'bccRecipients' => [['emailAddress' => ['name' => 'Private', 'address' => 'private@example.com']]],
        'body' => ['contentType' => 'HTML', 'content' => "<p>Résumé</p>\r\n<p>Chosen</p>"],
        'internetMessageHeaders' => [['name' => 'X-MAILBOX-CORRELATION-ID', 'value' => 'native-operation'], ['name' => 'x-project', 'value' => 'Support']],
    ]);
    $mailbox = nativeGraphMailbox([
        new Response(200, [], json_encode(nativeGraphSource(), JSON_THROW_ON_ERROR)),
        new Response(201, [], json_encode($draft, JSON_THROW_ON_ERROR)), new Response(200, [], '{"value":[]}'),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)), new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)), new Response(202),
        new Response(200, [], json_encode(array_replace($draft, ['isDraft' => false, 'sentDateTime' => '2026-10-29T12:30:00.1234567Z']), JSON_THROW_ON_ERROR)),
    ], $history);

    $preparation = $mailbox->prepareSubmission($message);
    $sent = $mailbox->submitPrepared($preparation, $message);
    $observed = $mailbox->inspectSubmission($preparation, $message);

    expect($sent->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($observed->outcome)->toBe(SubmissionEvidenceOutcome::SentCopyObserved)
        ->and($history[5]['request']->getMethod())->toBe('POST')
        ->and($history[6]['request']->getHeaderLine('Prefer'))->toContain('outlook.body-content-type="html"');
    $normalized = json_decode((string) $history[3]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    expect($normalized['ccRecipients'])->toBe([['emailAddress' => ['name' => 'Copy', 'address' => 'copy@example.com']]])
        ->and($normalized['bccRecipients'])->toBe([['emailAddress' => ['name' => 'Private', 'address' => 'private@example.com']]])
        ->and($normalized['body'])->toBe(['contentType' => 'HTML', 'content' => "<p>Résumé</p>\n<p>Chosen</p>"]);
});

it('requires the retained native provider conversation when reconstructing a preparation for send or inspection', function (string $operation): void {
    $history = [];
    $draft = nativeGraphDraft();
    $changed = array_replace($draft, ['conversationId' => 'different-conversation', 'isDraft' => $operation === 'submitPrepared', 'sentDateTime' => '2026-10-29T12:30:00Z']);
    $mailbox = nativeGraphMailbox([
        new Response(200, [], json_encode(nativeGraphSource(), JSON_THROW_ON_ERROR)),
        new Response(201, [], json_encode($draft, JSON_THROW_ON_ERROR)), new Response(200, [], '{"value":[]}'),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode($changed, JSON_THROW_ON_ERROR)), new Response(202),
    ], $history);
    $message = nativeGraphMessage();
    $preparation = $mailbox->prepareSubmission($message);
    $incomplete = new SubmissionPreparation($preparation->context, $preparation->outcome, $preparation->correlationId,
        $preparation->draftId, $preparation->status, $preparation->headers, $preparation->intentFingerprint());

    expect(fn () => $mailbox->{$operation}($incomplete, $message))->toThrow(InvalidArgumentException::class)
        ->and($history)->toHaveCount(4);
})->with(['submitPrepared', 'inspectSubmission']);

/** @param array<string, mixed> $changes */
function nativeGraphMessage(array $changes = []): PreparedMessage
{
    $context = new GrantContext('connection', 'grant', '1', 'ms-graph', 'sender@example.com');
    $conversation = new ConversationRead(new SyncIdentity('ms-graph', $context, new SyncScope('Inbox')), 'source-conversation', [MessageDto::fromMsGraph(nativeGraphSource())], complete: true);

    return new PreparedMessage(...array_replace([
        'sender' => new EmailAddressDto('Sender', 'sender@example.com'),
        'to' => [new EmailAddressDto('Chosen', 'chosen@example.com')],
        'subject' => 'Chosen subject', 'correlationId' => 'native-operation', 'textBody' => 'Chosen content',
        'context' => new ReplyForwardContext(ReplyForwardOperation::Reply, $conversation, 'source='),
    ], $changes));
}

/** @return array<string, mixed> */
function nativeGraphSource(): array
{
    return ['id' => 'source=', 'conversationId' => 'source-conversation', 'parentFolderId' => 'Inbox', 'isDraft' => false,
        'subject' => 'Original subject', 'body' => ['contentType' => 'text', 'content' => 'Original content']];
}

/** @return array<string, mixed> */
function nativeGraphDraft(): array
{
    return [
        'id' => 'new-draft=', 'conversationId' => 'source-conversation', 'isDraft' => true, 'hasAttachments' => false, 'attachments' => [],
        'from' => ['emailAddress' => ['name' => 'Sender', 'address' => 'sender@example.com']],
        'toRecipients' => [['emailAddress' => ['name' => 'Chosen', 'address' => 'chosen@example.com']]],
        'ccRecipients' => [], 'bccRecipients' => [], 'subject' => 'Chosen subject',
        'body' => ['contentType' => 'text', 'content' => 'Chosen content'],
        'internetMessageHeaders' => [['name' => 'x-mailbox-correlation-id', 'value' => 'native-operation']],
    ];
}

/**
 * @param list<mixed> $responses
 * @param array<int, mixed> $history
 */
function nativeGraphMailbox(array $responses, array &$history, ?TestGrantTokenProvider $tokens = null): SupportsDraftSubmission
{
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::history($history));
    $tokens ??= new TestGrantTokenProvider(['grant' => [
        'connection' => 'connection', 'generation' => '1', 'mailboxes' => ['sender@example.com'], 'token' => 'private-token',
    ]]);

    return Mailbox::forGrant(new GrantContext('connection', 'grant', '1', 'ms-graph', 'sender@example.com'), $tokens, new Client(['handler' => $handler]));
}

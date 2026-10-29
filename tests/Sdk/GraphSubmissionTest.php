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
use Illuminate\Contracts\Queue\Job;
use Pyle\Mailbox\Contracts\GrantTokenProvider;
use Pyle\Mailbox\Contracts\SupportsDraftSubmission;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\SubmissionPreparation;
use Pyle\Mailbox\Enums\PreparationOutcome;
use Pyle\Mailbox\Enums\SubmissionOutcome;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('returns an immutable Graph draft before a separate verified send with empty acceptance evidence', function (): void {
    $history = [];
    $draft = graphSubmissionDraft();
    $mailbox = graphSubmissionMailbox([
        new Response(201, ['request-id' => 'create-1'], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(202, ['request-id' => 'send-1']),
    ], $history);
    $message = graphSubmissionMessage();

    expect($history)->toBe([]);
    $preparation = $mailbox->prepareSubmission($message);

    expect($preparation->outcome)->toBe(PreparationOutcome::Prepared)
        ->and($preparation->draftId)->toBe('immutable-draft=')
        ->and($preparation->correlationId)->toBe('operation-1')
        ->and($preparation->headers)->toBe(['request-id' => ['create-1']])
        ->and($history)->toHaveCount(1);
    $retained = unserialize(serialize($preparation));
    $result = $mailbox->submitPrepared($retained, $message);

    expect($result->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($result->status)->toBe(202)
        ->and($result->headers)->toBe(['request-id' => ['send-1']])
        ->and($result->messageId)->toBeNull()
        ->and($result->threadId)->toBeNull()
        ->and($result->correlationId)->toBe('operation-1')
        ->and($history)->toHaveCount(3)
        ->and($history[0]['request']->getMethod())->toBe('POST')
        ->and($history[0]['request']->getUri()->getPath())->toBe('/v1.0/users/sender%40example.com/messages')
        ->and($history[1]['request']->getMethod())->toBe('GET')
        ->and($history[2]['request']->getMethod())->toBe('POST')
        ->and($history[2]['request']->getUri()->getPath())->toBe('/v1.0/users/sender%40example.com/messages/immutable-draft%3D/send')
        ->and((string) $history[2]['request']->getBody())->toBe('');
    $payload = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    expect($payload)->toBe([
        'from' => ['emailAddress' => ['name' => 'Sender', 'address' => 'sender@example.com']],
        'toRecipients' => [['emailAddress' => ['name' => 'Recipient', 'address' => 'to@example.com']]],
        'ccRecipients' => [['emailAddress' => ['name' => 'Copy', 'address' => 'cc@example.com']]],
        'bccRecipients' => [['emailAddress' => ['name' => 'Private', 'address' => 'bcc@example.com']]],
        'subject' => 'Prepared subject',
        'body' => ['contentType' => 'Text', 'content' => 'Prepared body'],
        'internetMessageHeaders' => [['name' => 'X-Mailbox-Correlation-Id', 'value' => 'operation-1']],
    ]);
    foreach ($history as $entry) {
        expect($entry['request']->getHeaderLine('Authorization'))->toBe('Bearer private-token')
            ->and($entry['request']->getHeaderLine('Prefer'))->toContain('IdType="ImmutableId"');
    }
});

it('permits only harmless protocol normalization when verifying a Graph draft', function (): void {
    $history = [];
    $message = graphSubmissionMessage([
        'to' => [new EmailAddressDto('First', 'first@example.com'), new EmailAddressDto('Second', 'second@example.com')],
        'textBody' => "First line\nSecond line",
    ]);
    $draft = graphSubmissionDraft();
    $draft['from'] = ['emailAddress' => ['address' => 'sender@EXAMPLE.COM', 'name' => 'Sender']];
    $draft['toRecipients'] = [
        ['emailAddress' => ['address' => 'second@EXAMPLE.COM', 'name' => 'Second']],
        ['emailAddress' => ['address' => 'first@EXAMPLE.COM', 'name' => 'First']],
    ];
    $draft['body'] = ['content' => "First line\r\nSecond line", 'contentType' => 'TEXT'];
    $mailbox = graphSubmissionMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(202),
    ], $history);

    $preparation = $mailbox->prepareSubmission($message);
    $result = $mailbox->submitPrepared($preparation, $message);

    expect($result->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($history)->toHaveCount(3);
});

it('refuses a draft with a conflicting duplicate correlation header before send', function (): void {
    $history = [];
    $draft = graphSubmissionDraft();
    $draft['internetMessageHeaders'][] = ['name' => 'X-Mailbox-Correlation-Id', 'value' => 'another-operation'];
    $mailbox = graphSubmissionMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(202),
    ], $history);
    $message = graphSubmissionMessage();
    $preparation = $mailbox->prepareSubmission($message);

    expect(fn () => $mailbox->submitPrepared($preparation, $message))->toThrow(ApiRequestException::class)
        ->and($history)->toHaveCount(2);
});

it('stops before send when the observed Graph draft differs from the explicit intent', function (array $changes): void {
    $history = [];
    $mailbox = graphSubmissionMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(200, [], json_encode(array_replace(graphSubmissionDraft(), $changes), JSON_THROW_ON_ERROR)),
        new Response(202),
    ], $history);
    $message = graphSubmissionMessage();
    $preparation = $mailbox->prepareSubmission($message);

    expect(fn () => $mailbox->submitPrepared($preparation, $message))->toThrow(ApiRequestException::class)
        ->and($history)->toHaveCount(2);
})->with([
    'changed subject' => [['subject' => 'Different subject']],
    'changed content' => [['body' => ['contentType' => 'text', 'content' => 'Different body']]],
    'malformed content' => [['body' => ['contentType' => 'text', 'content' => 1]]],
    'changed sender' => [['from' => ['emailAddress' => ['name' => 'Sender', 'address' => 'another@example.com']]]],
    'removed recipient' => [['toRecipients' => []]],
    'changed Cc' => [['ccRecipients' => []]],
    'inherited Bcc' => [['bccRecipients' => [['emailAddress' => ['name' => 'Unexpected', 'address' => 'unexpected@example.com']]]]],
    'inline file despite false hasAttachments' => [['attachments' => [['id' => 'inline-file']]]],
    'unlisted files' => [['attachments' => null]],
    'malformed empty object file list' => [['attachments' => (object) []]],
    'incomplete file list' => [['attachments@odata.nextLink' => 'https://private-upload']],
    'missing correlation' => [['internetMessageHeaders' => []]],
    'wrong immutable identity' => [['id' => 'different-draft']],
    'already sent' => [['isDraft' => false]],
]);

it('keeps lost or rejected Graph draft acknowledgements separate from send uncertainty', function (mixed $response, PreparationOutcome $outcome, ?int $status): void {
    $history = [];
    $job = Mockery::mock(Job::class);
    $job->shouldNotReceive('release');
    app()->instance('queue.job', $job);
    $mailbox = graphSubmissionMailbox([$response, new Response(201, [], '{"id":"unwanted-second-draft"}')], $history);
    $message = graphSubmissionMessage();

    $preparation = $mailbox->prepareSubmission($message);

    expect($preparation->outcome)->toBe($outcome)
        ->and($preparation->status)->toBe($status)
        ->and($preparation->draftId)->toBeNull()
        ->and($preparation->correlationId)->toBe('operation-1')
        ->and(fn () => $mailbox->submitPrepared($preparation, $message))->toThrow(InvalidArgumentException::class)
        ->and($history)->toHaveCount(1);
    $public = json_encode($preparation, JSON_THROW_ON_ERROR).print_r($preparation, true);
    expect($public)->not->toContain('private-token')->not->toContain('private-response')->not->toContain('private-upload')
        ->not->toContain($preparation->intentFingerprint());
})->with([
    'lost acknowledgement' => [new ConnectException('private-token timeout', new Request('POST', 'https://private-upload')), PreparationOutcome::Uncertain, null],
    'server error' => [new Response(503, ['Location' => 'https://private-upload'], 'private-response'), PreparationOutcome::Uncertain, 503],
    'empty create acknowledgement' => [new Response(201), PreparationOutcome::Uncertain, 201],
    'malformed create acknowledgement' => [new Response(201, [], '{'), PreparationOutcome::Uncertain, 201],
    'missing draft identity' => [new Response(201, [], '{}'), PreparationOutcome::Uncertain, 201],
    'invalid draft identity' => [new Response(201, [], '{"id":"https://private-upload"}'), PreparationOutcome::Uncertain, 201],
    'wrong create status' => [new Response(202, [], '{"id":"possible-draft"}'), PreparationOutcome::Uncertain, 202],
    'definite rejection' => [new Response(403, [], 'private-response'), PreparationOutcome::Rejected, 403],
]);

it('retains Graph send evidence without replay or cleanup after possible acceptance', function (mixed $response, SubmissionOutcome $outcome, ?int $status): void {
    $history = [];
    $job = Mockery::mock(Job::class);
    $job->shouldNotReceive('release');
    app()->instance('queue.job', $job);
    $mailbox = graphSubmissionMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(200, [], json_encode(graphSubmissionDraft(), JSON_THROW_ON_ERROR)),
        $response,
        new Response(202),
    ], $history);
    $message = graphSubmissionMessage();
    $preparation = $mailbox->prepareSubmission($message);

    $result = $mailbox->submitPrepared($preparation, $message);

    expect($result->outcome)->toBe($outcome)
        ->and($result->status)->toBe($status)
        ->and($result->correlationId)->toBe('operation-1')
        ->and($result->messageId)->toBeNull()
        ->and($history)->toHaveCount(3)
        ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('private-token')->not->toContain('private-response')->not->toContain('private-upload');
})->with([
    'lost send acknowledgement' => [new ConnectException('private-token timeout', new Request('POST', 'https://private-upload')), SubmissionOutcome::Uncertain, null],
    'server failure' => [new Response(503, ['request-id' => 'request-503', 'Authorization' => 'Bearer private-token', 'Location' => 'https://private-upload'], 'private-response'), SubmissionOutcome::Uncertain, 503],
    'unexpected acknowledgement body' => [new Response(202, [], '{}'), SubmissionOutcome::Uncertain, 202],
    'wrong acceptance status' => [new Response(200, [], '{"id":"invented-id"}'), SubmissionOutcome::Uncertain, 200],
    'redirect' => [new Response(307, ['Location' => 'https://private-upload']), SubmissionOutcome::Uncertain, 307],
    'rate limit' => [new Response(429, ['Retry-After' => '17']), SubmissionOutcome::Rejected, 429],
    'oversized acknowledgement' => [new Response(202, [], str_repeat('x', 65_537)), SubmissionOutcome::Uncertain, 202],
]);

it('rejects a retained handle from a different selected context before any further HTTP', function (string $field): void {
    $history = [];
    $mailbox = graphSubmissionMailbox([new Response(201, [], '{"id":"immutable-draft="}')], $history);
    $message = graphSubmissionMessage();
    $preparation = $mailbox->prepareSubmission($message);
    $context = get_object_vars($preparation->context);
    $context[$field] = 'different';
    $other = new SubmissionPreparation(
        new GrantContext(...$context), $preparation->outcome, $preparation->correlationId,
        $preparation->draftId, $preparation->status, $preparation->headers, $preparation->intentFingerprint(),
    );

    expect(fn () => $mailbox->submitPrepared($other, $message))->toThrow(InvalidArgumentException::class)
        ->and($history)->toHaveCount(1);
})->with(['connectionId', 'grantId', 'lifecycleGeneration', 'driver', 'mailbox']);

it('rejects changed host intent before reading or sending the retained draft', function (array $changes): void {
    $history = [];
    $mailbox = graphSubmissionMailbox([new Response(201, [], '{"id":"immutable-draft="}')], $history);
    $preparation = $mailbox->prepareSubmission(graphSubmissionMessage());

    expect(fn () => $mailbox->submitPrepared($preparation, graphSubmissionMessage($changes)))->toThrow(InvalidArgumentException::class)
        ->and($history)->toHaveCount(1);
})->with([
    'content' => [['textBody' => 'Changed body']],
    'recipient' => [['bcc' => [new EmailAddressDto('Unexpected', 'unexpected@example.com')]]],
    'correlation' => [['correlationId' => 'another-operation']],
]);

it('checks selected lifecycle before preparation, verification and send', function (string $phase): void {
    $history = [];
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['sender@example.com'], 'token' => 'private-token'],
    ]);
    $mailbox = graphSubmissionMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        static function () use ($tokens): Response {
            $tokens->grants['grant']['generation'] = '2';

            return new Response(200, [], json_encode(graphSubmissionDraft(), JSON_THROW_ON_ERROR));
        },
        new Response(202),
    ], $history, $tokens);
    $message = graphSubmissionMessage();
    if ($phase === 'preparation') {
        unset($tokens->grants['grant']);
        expect(fn () => $mailbox->prepareSubmission($message))->toThrow(GrantAccessDeniedException::class)
            ->and($history)->toBe([]);

        return;
    }
    $preparation = $mailbox->prepareSubmission($message);
    if ($phase === 'verification') {
        unset($tokens->grants['grant']);
    }

    expect(fn () => $mailbox->submitPrepared($preparation, $message))->toThrow(GrantAccessDeniedException::class)
        ->and($history)->toHaveCount($phase === 'verification' ? 1 : 2);
})->with(['preparation', 'verification', 'send']);

it('rejects unsupported or unsafe Graph input before creating a provider draft', function (array $changes, string $exception): void {
    $history = [];
    $mailbox = graphSubmissionMailbox([], $history);

    expect(fn () => $mailbox->prepareSubmission(graphSubmissionMessage($changes)))->toThrow($exception)
        ->and($history)->toBe([]);
})->with([
    'files' => [['files' => [['id' => 'host-file']]], UnsupportedCapabilityException::class],
    'dual body' => [['htmlBody' => '<p>Body</p>'], UnsupportedCapabilityException::class],
    'unsupported header' => [['headers' => ['In-Reply-To' => '<original@example.com>']], UnsupportedCapabilityException::class],
    'header injection' => [['subject' => "Subject\r\nBcc: unexpected@example.com"], InvalidArgumentException::class],
    'address injection' => [['sender' => new EmailAddressDto('Sender', "sender@example.com\r\nBcc: unexpected@example.com")], InvalidArgumentException::class],
    'invalid address' => [['to' => [new EmailAddressDto('Recipient', 'invalid')]], InvalidArgumentException::class],
    'no recipients' => [['to' => [], 'cc' => [], 'bcc' => []], InvalidArgumentException::class],
    'no content' => [['textBody' => null], InvalidArgumentException::class],
    'no correlation' => [['correlationId' => ''], InvalidArgumentException::class],
]);

it('keeps a truncated empty Graph acknowledgement uncertain', function (): void {
    $history = [];
    $mailbox = graphSubmissionMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(200, [], json_encode(graphSubmissionDraft(), JSON_THROW_ON_ERROR)),
        new Response(202, ['Content-Length' => '12', 'request-id' => 'truncated-1']),
    ], $history);
    $message = graphSubmissionMessage();
    $preparation = $mailbox->prepareSubmission($message);

    $result = $mailbox->submitPrepared($preparation, $message);

    expect($result->outcome)->toBe(SubmissionOutcome::Uncertain)
        ->and($result->status)->toBe(202)
        ->and($result->headers)->toBe(['request-id' => ['truncated-1']])
        ->and($history)->toHaveCount(3);
});

it('retains safe verification failure evidence without requesting send', function (): void {
    $history = [];
    $mailbox = graphSubmissionMailbox([
        new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(503, ['request-id' => 'verification-503', 'Authorization' => 'Bearer private-token', 'Location' => 'https://private-upload'], 'private-response'),
        new Response(202),
    ], $history);
    $message = graphSubmissionMessage();
    $preparation = $mailbox->prepareSubmission($message);

    try {
        $mailbox->submitPrepared($preparation, $message);
        test()->fail('An unverifiable draft must stop before send.');
    } catch (ApiRequestException $exception) {
        expect($exception->status)->toBe(503)
            ->and($exception->headers)->toBe(['request-id' => ['verification-503']])
            ->and($exception->getPrevious())->toBeNull();
        $public = $exception->getMessage().json_encode($exception->headers, JSON_THROW_ON_ERROR);
        expect($public)->not->toContain('private-token')->not->toContain('private-upload')->not->toContain('private-response');
    }
    expect($history)->toHaveCount(2);
});

it('uses immutable identities for phased HTML submission while preserving disabled legacy preference', function (): void {
    config()->set('mailbox.prefer_immutable_ids', false);
    $history = [];
    $message = graphSubmissionMessage([
        'to' => [], 'cc' => [], 'textBody' => null, 'htmlBody' => '<p>Résumé</p>',
        'headers' => ['X-Project' => 'Support'],
    ]);
    $draft = graphSubmissionDraft();
    $draft['toRecipients'] = [];
    $draft['ccRecipients'] = [];
    $draft['body'] = ['contentType' => 'html', 'content' => '<p>Résumé</p>'];
    $draft['internetMessageHeaders'][] = ['name' => 'x-project', 'value' => 'Support'];
    $mailbox = graphSubmissionMailbox([
        new Response(200, [], '{"id":"existing-id"}'),
        new Response(201, [], '{"id":"immutable-draft="}'),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(202),
    ], $history);

    $mailbox->message('existing-id')->get();
    $preparation = $mailbox->prepareSubmission($message);
    $result = $mailbox->submitPrepared($preparation, $message);

    expect($result->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and(config('mailbox.prefer_immutable_ids'))->toBeFalse()
        ->and($history[0]['request']->getHeaderLine('Prefer'))->toBe('')
        ->and($history[2]['request']->getHeaderLine('Prefer'))->toContain('outlook.body-content-type="html"');
    foreach (array_slice($history, 1) as $entry) {
        expect($entry['request']->getHeaderLine('Prefer'))->toContain('IdType="ImmutableId"');
    }
    $payload = json_decode((string) $history[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['body'])->toBe(['contentType' => 'HTML', 'content' => '<p>Résumé</p>'])
        ->and($payload['toRecipients'])->toBe([])
        ->and($payload['ccRecipients'])->toBe([])
        ->and($payload['bccRecipients'][0]['emailAddress']['address'])->toBe('bcc@example.com');
});

it('enforces the actual Graph JSON byte budget and provider write ceiling before HTTP', function (array $changes, int $budget, string $exception): void {
    $history = [];
    $mailbox = graphSubmissionMailbox([], $history);

    expect(fn () => $mailbox->prepareSubmission(graphSubmissionMessage($changes), $budget))->toThrow($exception)
        ->and($history)->toBe([]);
})->with([
    'positive caller budget' => [[], 0, InvalidArgumentException::class],
    'body exceeds caller budget' => [['textBody' => str_repeat('a', 1000)], 512, UnsupportedCapabilityException::class],
    'JSON metadata exceeds caller budget' => [['textBody' => 'Body'], 128, UnsupportedCapabilityException::class],
    'JSON escape expansion exceeds Graph limit' => [['textBody' => str_repeat('é', 700_000)], 4_000_000, UnsupportedCapabilityException::class],
    'larger caller budget cannot exceed Graph limit' => [['textBody' => str_repeat('a', 4_000_000)], 8_000_000, UnsupportedCapabilityException::class],
]);

it('retains a bounded Graph draft body larger than the default write acknowledgement budget', function (): void {
    $history = [];
    $body = str_repeat('a', 70_000);
    $draft = graphSubmissionDraft();
    $draft['body']['content'] = $body;
    $response = json_encode($draft, JSON_THROW_ON_ERROR);
    $mailbox = graphSubmissionMailbox([
        new Response(201, [], $response), new Response(200, [], $response), new Response(202),
    ], $history);
    $message = graphSubmissionMessage(['textBody' => $body]);

    $preparation = $mailbox->prepareSubmission($message, 100_000);
    $result = $mailbox->submitPrepared($preparation, $message);

    expect($preparation->outcome)->toBe(PreparationOutcome::Prepared)
        ->and($result->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($history)->toHaveCount(3);
});

it('bounds actual preparation and verification responses while retaining safe phase evidence', function (string $phase): void {
    $history = [];
    $oversized = new Response($phase === 'create' ? 201 : 200, ['request-id' => 'oversized-1'], '{"id":"immutable-draft=","padding":"'.str_repeat('a', 4_194_304).'"}');
    $responses = $phase === 'create' ? [$oversized] : [new Response(201, [], '{"id":"immutable-draft="}'), $oversized];
    $mailbox = graphSubmissionMailbox($responses, $history);
    $message = graphSubmissionMessage();
    $preparation = $mailbox->prepareSubmission($message);
    if ($phase === 'create') {
        expect($preparation->outcome)->toBe(PreparationOutcome::Uncertain)
            ->and($preparation->status)->toBe(201)
            ->and($preparation->headers)->toBe(['request-id' => ['oversized-1']])
            ->and($preparation->draftId)->toBeNull()
            ->and($history)->toHaveCount(1);

        return;
    }
    try {
        $mailbox->submitPrepared($preparation, $message);
        test()->fail('An oversized verification response must stop before send.');
    } catch (ApiRequestException $exception) {
        expect($exception->status)->toBe(200)
            ->and($exception->headers)->toBe(['request-id' => ['oversized-1']]);
    }
    expect($history)->toHaveCount(2);
})->with(['create', 'verification']);

it('rejects encoded Graph receipts without reading or automatic decompression', function (): void {
    $history = [];
    $reads = 0;
    $closed = false;
    $source = Utils::streamFor(gzencode('{"id":"immutable-draft="}'));
    $body = FnStream::decorate($source, [
        'read' => static function (int $length) use ($source, &$reads): string {
            $reads++;

            return $source->read($length);
        },
        'close' => static function () use ($source, &$closed): void {
            $closed = true;
            $source->close();
        },
    ]);
    $mailbox = graphSubmissionMailbox([new Response(201, ['Content-Encoding' => 'gzip', 'request-id' => 'encoded-1'], $body)], $history);

    $preparation = $mailbox->prepareSubmission(graphSubmissionMessage());

    expect($preparation->outcome)->toBe(PreparationOutcome::Uncertain)
        ->and($preparation->status)->toBe(201)
        ->and($preparation->headers)->toBe(['request-id' => ['encoded-1']])
        ->and($preparation->draftId)->toBeNull()
        ->and($reads)->toBe(0)
        ->and($closed)->toBeTrue()
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getHeaderLine('Accept-Encoding'))->toBe('identity')
        ->and($history[0]['options']['decode_content'])->toBeFalse();
});

/** @param array<string, mixed> $changes */
function graphSubmissionMessage(array $changes = []): PreparedMessage
{
    return new PreparedMessage(...array_replace([
        'sender' => new EmailAddressDto('Sender', 'sender@example.com'),
        'to' => [new EmailAddressDto('Recipient', 'to@example.com')],
        'cc' => [new EmailAddressDto('Copy', 'cc@example.com')],
        'bcc' => [new EmailAddressDto('Private', 'bcc@example.com')],
        'subject' => 'Prepared subject',
        'correlationId' => 'operation-1',
        'textBody' => 'Prepared body',
    ], $changes));
}

/** @return array<string, mixed> */
function graphSubmissionDraft(): array
{
    return [
        'id' => 'immutable-draft=', 'isDraft' => true, 'hasAttachments' => false, 'attachments' => [],
        'from' => ['emailAddress' => ['name' => 'Sender', 'address' => 'sender@example.com']],
        'toRecipients' => [['emailAddress' => ['name' => 'Recipient', 'address' => 'to@example.com']]],
        'ccRecipients' => [['emailAddress' => ['name' => 'Copy', 'address' => 'cc@example.com']]],
        'bccRecipients' => [['emailAddress' => ['name' => 'Private', 'address' => 'bcc@example.com']]],
        'subject' => 'Prepared subject', 'body' => ['contentType' => 'text', 'content' => 'Prepared body'],
        'internetMessageHeaders' => [['name' => 'x-mailbox-correlation-id', 'value' => 'operation-1']],
    ];
}

/**
 * @param  list<mixed>  $responses
 * @param  array<int, mixed>  $history
 */
function graphSubmissionMailbox(array $responses, array &$history, ?GrantTokenProvider $tokens = null, ?GrantContext $context = null): SupportsDraftSubmission
{
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::history($history));
    $tokens ??= new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['sender@example.com'], 'token' => 'private-token'],
    ]);

    return Mailbox::forGrant(
        $context ?? new GrantContext('connection', 'grant', '1', 'ms-graph', 'sender@example.com'),
        $tokens,
        new Client(['handler' => $handler]),
    );
}

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
use Illuminate\Contracts\Queue\Job;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\DTOs\BodyReadLimits;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('reads externalized Gmail text as body without advertising it as a file', function (): void {
    $history = [];
    $resource = gmailBodyResource([
        ['id' => 'message', 'payload' => ['mimeType' => 'text/plain', 'body' => ['attachmentId' => 'external-body', 'size' => 12]]],
        ['size' => 12, 'data' => 'SGVsbG8gY2Fmw6kK'],
        ['id' => 'message', 'payload' => ['mimeType' => 'text/plain', 'body' => ['attachmentId' => 'external-body', 'size' => 12]]],
    ], $history);

    $message = $resource->get();

    expect($message->body?->content)->toBe("Hello café\n")
        ->and($message->body?->contentType)->toBe('text')
        ->and($message->hasAttachments)->toBeFalse()
        ->and($resource->attachments())->toBeEmpty()
        ->and($history)->toHaveCount(3)
        ->and($history[1]['request']->getUri()->getPath())->toBe('/gmail/v1/users/reader%40example.com/messages/message/attachments/external-body');
});

it('rejects malformed or incomplete Gmail body bytes', function (array $body): void {
    $history = [];
    $resource = gmailBodyResource([['id' => 'message', 'payload' => ['mimeType' => 'text/plain', 'body' => $body]]], $history);

    expect(fn () => $resource->get())->toThrow(MailboxException::class);
})->with([
    'invalid alphabet' => [['data' => '%%%']],
    'truncated quantum' => [['data' => 'A']],
    'noncanonical pad bits' => [['data' => 'Zh']],
    'bad padding' => [['data' => 'Zg=']],
    'truncated declared bytes' => [['data' => 'SGVsbG8', 'size' => 6]],
    'missing data' => [['size' => 8]],
    'empty missing body' => [[]],
    'wrong data type' => [['data' => 12]],
]);

it('preserves valid empty text and the literal zero', function (array $body, string $expected): void {
    $history = [];
    $resource = gmailBodyResource([['id' => 'message', 'payload' => ['mimeType' => 'text/plain', 'body' => $body]]], $history);

    expect($resource->body()->content)->toBe($expected);
})->with([
    [['data' => '', 'size' => 0], ''],
    [['size' => 0], ''],
    [['data' => 'MA', 'size' => 1], '0'],
]);

it('rejects native object MIME collections in ordinary Gmail reads', function (string $parts, string $operation): void {
    $history = [];
    $resource = gmailBodyResource([new Response(200, [], '{"id":"message","payload":{"mimeType":"multipart/mixed","parts":'.$parts.'}}')], $history);

    expect(fn () => $resource->{$operation}())->toThrow(ApiRequestException::class)
        ->and($history)->toHaveCount(1);
})->with(['{}', '{"0":{"mimeType":"text/plain","body":{"size":0}}}'])->with(['get', 'attachments']);

it('selects nested HTML body while retaining named text and inline image metadata', function (): void {
    $history = [];
    $payload = ['mimeType' => 'multipart/mixed', 'parts' => [
        ['mimeType' => 'text/html', 'filename' => 'notes.html', 'body' => ['attachmentId' => 'named', 'size' => 20]],
        ['mimeType' => 'multipart/alternative', 'parts' => [
            ['mimeType' => 'text/plain', 'body' => ['data' => 'UGxhaW4', 'size' => 5]],
            ['mimeType' => 'multipart/related', 'parts' => [
                ['mimeType' => 'text/html', 'headers' => [['name' => 'Content-Type', 'value' => 'text/html; charset="windows-1252"']], 'body' => ['attachmentId' => 'html', 'size' => 11]],
                ['mimeType' => 'image/png', 'filename' => 'logo.png', 'headers' => [
                    ['name' => 'Content-ID', 'value' => '<logo>'],
                ], 'body' => ['attachmentId' => 'logo', 'size' => 2]],
            ]],
        ]],
    ]];
    $resource = gmailBodyResource([
        ['id' => 'message', 'payload' => $payload],
        ['data' => 'PHA-Y2Fm6TwvcD4', 'size' => 11],
        ['id' => 'message', 'payload' => $payload],
    ], $history);

    expect($resource->body()->content)->toBe('<p>café</p>');
    $files = $resource->attachments();
    expect($files->pluck('id')->all())->toBe(['named', 'logo'])
        ->and($files->pluck('name')->all())->toBe(['notes.html', 'logo.png'])
        ->and($files[1]->contentType)->toBe('image/png')
        ->and($files[1]->isInline)->toBeTrue()
        ->and($files[1]->contentId)->toBe('<logo>')
        ->and($history)->toHaveCount(3);
});

it('rejects unsupported charsets and invalid text rather than replacing bytes', function (string $charset, string $data): void {
    $history = [];
    $resource = gmailBodyResource([['id' => 'message', 'payload' => [
        'mimeType' => 'text/plain',
        'headers' => [['name' => 'Content-Type', 'value' => 'text/plain; charset='.$charset]],
        'body' => ['data' => $data],
    ]]], $history);

    expect(fn () => $resource->get())->toThrow(MailboxException::class);
})->with([['unknown-charset', 'SGVsbG8'], ['utf-8', '_w']]);

it('does not complete when an external body response is absent or inconsistent', function (array $external): void {
    $history = [];
    $resource = gmailBodyResource([
        ['id' => 'message', 'payload' => ['mimeType' => 'text/plain', 'body' => ['attachmentId' => 'body', 'size' => 3]]],
        $external,
    ], $history);

    expect(fn () => $resource->get())->toThrow(MailboxException::class);
})->with([
    'empty response' => [[]],
    'missing bytes' => [['size' => 3]],
    'mismatched original size' => [['size' => 2, 'data' => 'T0s']],
    'different external identity' => [['attachmentId' => 'other', 'size' => 3, 'data' => 'WWVz']],
]);

it('refuses ambiguous inline and external body content', function (): void {
    $history = [];
    $resource = gmailBodyResource([
        ['id' => 'message', 'payload' => ['mimeType' => 'text/plain', 'body' => ['attachmentId' => 'body', 'size' => 3, 'data' => 'WWVz']]],
        ['size' => 3, 'data' => 'Tm8h'],
    ], $history);

    expect(fn () => $resource->get())->toThrow(MailboxException::class)
        ->and($history)->toHaveCount(1);
});

it('keeps a failed hydration visible and never returns an empty body', function (): void {
    $history = [];
    $resource = gmailBodyResource([
        ['id' => 'message', 'payload' => ['mimeType' => 'text/plain', 'body' => ['attachmentId' => 'body', 'size' => 3]]],
        new Response(404, [], '{}'),
    ], $history);

    expect(fn () => $resource->body())->toThrow(MailboxException::class)
        ->and($history)->toHaveCount(2);
});

it('retains all mixed body fragments and excludes inline or header-named text files', function (): void {
    $history = [];
    $payload = ['mimeType' => 'multipart/mixed', 'parts' => [
        ['mimeType' => 'text/plain', 'body' => ['data' => 'Rmlyc3Q']],
        ['mimeType' => 'text/plain', 'headers' => [['name' => 'Content-Disposition', 'value' => 'attachment; filename="notes.txt"']], 'body' => ['attachmentId' => 'notes']],
        ['partId' => 'inline', 'mimeType' => 'text/plain', 'headers' => [
            ['name' => 'Content-Disposition', 'value' => 'inline'], ['name' => 'Content-ID', 'value' => '<text-file>'],
        ], 'body' => ['data' => 'RmlsZQ']],
        ['mimeType' => 'text/plain', 'body' => ['data' => 'TGFzdA']],
    ]];
    $resource = gmailBodyResource([['id' => 'message', 'payload' => $payload], ['id' => 'message', 'payload' => $payload]], $history);

    expect($resource->body()->content)->toBe("First\nLast");
    $files = $resource->attachments();
    expect($files->pluck('id')->all())->toBe(['notes', 'inline'])
        ->and($files[0]->name)->toBe('notes.txt')
        ->and($files[1]->isInline)->toBeTrue()
        ->and($files[1]->contentId)->toBe('<text-file>');
});

it('does not invent an empty body when no text content was returned', function (): void {
    $history = [];
    $resource = gmailBodyResource([['id' => 'message', 'payload' => ['mimeType' => 'multipart/mixed', 'parts' => []]]], $history);

    expect(fn () => $resource->body())->toThrow(MailboxException::class);
});

it('reports malformed MIME structure explicitly', function (array $payload): void {
    $history = [];
    $resource = gmailBodyResource([['id' => 'message', 'payload' => $payload]], $history);

    expect(fn () => $resource->get())->toThrow(MailboxException::class);
})->with([
    [['mimeType' => 'multipart/mixed', 'parts' => ['missing-part']]],
    [['mimeType' => 'text/plain', 'headers' => ['invalid'], 'body' => ['data' => 'T0s']]],
]);

it('bounds the actual initial and external response bytes before decoding', function (bool $external): void {
    $history = [];
    $message = ['id' => 'message', 'payload' => ['mimeType' => 'text/plain', 'body' => ['attachmentId' => 'body', 'size' => 300]]];
    $resource = gmailBodyResource($external
        ? [$message, ['data' => str_repeat('YQ', 300), 'size' => 300]]
        : [new Response(200, ['Content-Length' => '2'], '{"ignored":"'.str_repeat('x', 400).'"}')], $history);

    expect(fn () => $resource->get(new BodyReadLimits(maxResponseBytes: 250)))->toThrow(MailboxException::class)
        ->and($history)->toHaveCount($external ? 2 : 1);
})->with([false, true]);

it('bounds decoded bodies and hydration work without returning partial content', function (array $parts, BodyReadLimits $limits, int $requests): void {
    $history = [];
    $resource = gmailBodyResource([
        ['id' => 'message', 'payload' => ['mimeType' => 'multipart/mixed', 'parts' => $parts]],
        ['data' => 'T0s', 'size' => 2],
    ], $history);

    expect(fn () => $resource->get($limits))->toThrow(MailboxException::class)
        ->and($history)->toHaveCount($requests);
})->with([
    'decoded bytes' => [[['mimeType' => 'text/plain', 'body' => ['data' => 'SGVsbG8', 'size' => 5]]], fn (): BodyReadLimits => new BodyReadLimits(maxBodyBytes: 4), 1],
    'combined decoded bytes' => [[['mimeType' => 'text/plain', 'body' => ['data' => 'T0s']], ['mimeType' => 'text/plain', 'body' => ['data' => 'T0s']]], fn (): BodyReadLimits => new BodyReadLimits(maxBodyBytes: 3), 1],
    'combined output bytes' => [[['mimeType' => 'text/plain', 'body' => ['data' => 'T0s']], ['mimeType' => 'text/plain', 'body' => ['data' => 'T0s']]], fn (): BodyReadLimits => new BodyReadLimits(maxBodyBytes: 4), 1],
    'hydration count' => [[['mimeType' => 'text/plain', 'body' => ['attachmentId' => 'first', 'size' => 2]], ['mimeType' => 'text/plain', 'body' => ['attachmentId' => 'second', 'size' => 2]]], fn (): BodyReadLimits => new BodyReadLimits(maxHydrations: 1), 2],
    'MIME parts' => [[['mimeType' => 'text/plain', 'body' => ['data' => 'T0s']]], fn (): BodyReadLimits => new BodyReadLimits(maxParts: 1), 1],
]);

it('counts decoded wire bytes even when charset conversion makes text smaller', function (): void {
    $history = [];
    $resource = gmailBodyResource([['id' => 'message', 'payload' => [
        'mimeType' => 'text/plain', 'headers' => [['name' => 'Content-Type', 'value' => 'text/plain; charset=UTF-16BE']],
        'body' => ['data' => 'AGE', 'size' => 2],
    ]]], $history);

    expect(fn () => $resource->body(new BodyReadLimits(maxBodyBytes: 1)))->toThrow(MailboxException::class);
});

it('never exposes an externalized body through the file resource', function (): void {
    $history = [];
    $resource = gmailBodyResource([['id' => 'message', 'payload' => ['mimeType' => 'text/plain', 'body' => ['attachmentId' => 'body', 'size' => 2]]]], $history);

    expect(fn () => $resource->attachment('body')->metadata())->toThrow(MailboxException::class);
});

it('hydrates bodies through the Gmail listing and incremental delta paths', function (bool $delta): void {
    $history = [];
    $mailbox = gmailBodyMailbox([
        $delta ? ['historyId' => '101', 'history' => [['messagesAdded' => [['message' => ['id' => 'message']]]]]] : ['messages' => [['id' => 'message']]],
        ['id' => 'message', 'payload' => ['mimeType' => 'text/plain', 'body' => ['attachmentId' => 'body', 'size' => 2]]],
        ['data' => 'T0s', 'size' => 2],
    ], $history);

    $messages = $delta ? $mailbox->folder('INBOX')->delta('100')->created : $mailbox->messages()->get();

    expect($messages->first()?->body?->content)->toBe('OK')
        ->and($messages->first()?->hasAttachments)->toBeFalse()
        ->and($history)->toHaveCount(3);
})->with([false, true]);

it('fails an interrupted external body stream without returning partial text or retrying', function (): void {
    $history = [];
    $closed = false;
    $stream = new FnStream([
        'getSize' => fn (): ?int => null,
        'eof' => fn (): bool => false,
        'read' => function (int $length): string {
            throw new RuntimeException('Private provider stream detail.');
        },
        'close' => function () use (&$closed): void {
            $closed = true;
        },
    ]);
    $resource = gmailBodyResource([
        ['id' => 'message', 'payload' => ['mimeType' => 'text/plain', 'body' => ['attachmentId' => 'body', 'size' => 2]]],
        new Response(200, [], $stream),
    ], $history);

    expect(fn () => $resource->body())->toThrow(ApiRequestException::class, 'The provider response stream could not be read.')
        ->and($history)->toHaveCount(2)
        ->and($closed)->toBeTrue();
});

it('uses the declared related root as body even when it has a Content-ID', function (): void {
    $history = [];
    $payload = ['mimeType' => 'multipart/related', 'headers' => [['name' => 'Content-Type', 'value' => 'multipart/related; start="<body>"']], 'parts' => [
        ['partId' => 'file', 'mimeType' => 'text/plain', 'headers' => [
            ['name' => 'Content-Disposition', 'value' => 'inline'], ['name' => 'Content-ID', 'value' => '<file>'],
        ], 'body' => ['data' => 'RmlsZQ']],
        ['mimeType' => 'text/html', 'headers' => [['name' => 'Content-ID', 'value' => '<body>']], 'body' => ['attachmentId' => 'body', 'size' => 9]],
    ]];
    $resource = gmailBodyResource([
        ['id' => 'message', 'payload' => $payload], ['data' => 'PGI-T0s8L2I-', 'size' => 9], ['id' => 'message', 'payload' => $payload],
    ], $history);

    expect($resource->body()->content)->toBe('<b>OK</b>')
        ->and($resource->attachments()->pluck('id')->all())->toBe(['file']);
});

it('uses Gmail parsed bytes without decoding the original MIME transfer encoding twice', function (): void {
    $history = [];
    $resource = gmailBodyResource([['id' => 'message', 'payload' => [
        'mimeType' => 'text/plain', 'headers' => [['name' => 'Content-Transfer-Encoding', 'value' => 'base64']],
        'body' => ['data' => 'WVE9PQ', 'size' => 4],
    ]]], $history);

    expect($resource->body()->content)->toBe('YQ==');
});

it('does not complete an incremental round when an external body is missing', function (): void {
    $history = [];
    $mailbox = gmailBodyMailbox([
        ['historyId' => '101', 'history' => [['messagesAdded' => [['message' => ['id' => 'message']]]]]],
        ['id' => 'message', 'payload' => ['mimeType' => 'text/plain', 'body' => ['attachmentId' => 'body', 'size' => 2]]],
        new Response(404, [], '{}'),
    ], $history);

    expect(fn () => $mailbox->folder('INBOX')->delta('100'))->toThrow(MailboxException::class, 'External Gmail body content was not available.');
});

it('preserves safe Gmail message retries without releasing the enclosing host job', function (bool $transportFailure): void {
    $job = Mockery::mock(Job::class);
    $job->shouldNotReceive('release');
    app()->instance('queue.job', $job);
    $history = [];
    $resource = gmailBodyResource([
        $transportFailure ? new ConnectException('Private connection detail.', new Request('GET', 'https://gmail.googleapis.com/gmail/v1/users/reader%40example.com/messages/message')) : new Response(503, [], '{}'),
        ['id' => 'message', 'payload' => ['mimeType' => 'text/plain', 'body' => ['data' => 'T0s', 'size' => 2]]],
    ], $history);

    expect($resource->body()->content)->toBe('OK')
        ->and($history)->toHaveCount(2);
})->with([false, true]);

it('charges retry responses against the same Gmail body byte budget', function (): void {
    $history = [];
    $message = ['id' => 'message', 'payload' => ['mimeType' => 'text/plain', 'body' => ['data' => 'T0s', 'size' => 2]]];
    $error = '{"error":"'.str_repeat('x', 150).'"}';
    $resource = gmailBodyResource([new Response(503, [], $error), $message], $history);
    $budget = strlen($error) + strlen(json_encode($message, JSON_THROW_ON_ERROR)) - 1;

    expect(fn () => $resource->get(new BodyReadLimits(maxResponseBytes: $budget)))->toThrow(ResponseLimitExceededException::class)
        ->and($history)->toHaveCount(2);
});

/**
 * @param  array<int, array<string, mixed>|Response|Throwable>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function gmailBodyResource(array $responses, array &$history): MessageResource
{
    return gmailBodyMailbox($responses, $history)->message('message');
}

/**
 * @param  array<int, array<string, mixed>|Response|Throwable>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function gmailBodyMailbox(array $responses, array &$history): MailboxResource
{
    $stack = HandlerStack::create(new MockHandler(array_map(
        static fn (array|Response|Throwable $response): Response|Throwable => is_array($response)
            ? new Response(200, [], json_encode($response, JSON_THROW_ON_ERROR))
            : $response,
        $responses,
    )));
    $stack->push(Middleware::history($history));
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['reader@example.com'], 'token' => 'selected-token'],
    ]);

    return Mailbox::forGrant(
        new GrantContext('connection', 'grant', '1', 'gmail', 'reader@example.com'),
        $tokens,
        new Client(['handler' => $stack, 'base_uri' => 'https://gmail.googleapis.com/gmail/v1/']),
    );
}

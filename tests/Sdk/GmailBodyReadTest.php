<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Exceptions\MailboxException;
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

it('selects nested HTML body while retaining named text and inline image metadata', function (): void {
    $history = [];
    $payload = ['mimeType' => 'multipart/mixed', 'parts' => [
        ['mimeType' => 'text/html', 'filename' => 'notes.html', 'body' => ['attachmentId' => 'named', 'size' => 20]],
        ['mimeType' => 'multipart/alternative', 'parts' => [
            ['mimeType' => 'text/plain', 'body' => ['data' => 'UGxhaW4', 'size' => 5]],
            ['mimeType' => 'multipart/related', 'parts' => [
                ['mimeType' => 'text/html', 'headers' => [['name' => 'Content-Type', 'value' => 'text/html; charset="windows-1252"']], 'body' => ['attachmentId' => 'html', 'size' => 11]],
                ['mimeType' => 'image/png', 'filename' => 'logo.png', 'headers' => [
                    ['name' => 'Content-Disposition', 'value' => 'inline; filename="logo.png"'],
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

/**
 * @param array<int, array<string, mixed>|Response|Throwable> $responses
 * @param array<int, array<string, mixed>> $history
 */
function gmailBodyResource(array $responses, array &$history): \Pyle\Mailbox\Contracts\MessageResource
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
    )->message('message');
}

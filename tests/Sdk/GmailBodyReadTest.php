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
use Illuminate\Support\Facades\Storage;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\Contracts\SupportsMessageMetadataRead;
use Pyle\Mailbox\DTOs\BodyReadLimits;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Exceptions\ProviderTransportException;
use Pyle\Mailbox\Exceptions\ResourceNotFoundException;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('reads Gmail selection metadata once without hydrating or exposing supplied body content', function (): void {
    $history = [];
    $metadata = ['id' => 'message', 'threadId' => 'thread', 'labelIds' => ['INBOX'], 'internalDate' => '1793044800000'];
    $resource = gmailBodyResource([$metadata + ['payload' => ['mimeType' => 'text/plain',
        'body' => ['attachmentId' => 'external-body', 'size' => 12]]]], $history);
    expect($resource)->toBeInstanceOf(SupportsMessageMetadataRead::class);

    $message = $resource->metadata();

    expect($message->raw)->toBe($metadata)
        ->and($message->body)->toBeNull()
        ->and($message->conversationId)->toBe('thread');
    expect($history)->toHaveCount(1);
    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    expect($query)->toBe(['format' => 'metadata', 'fields' => 'id,threadId,labelIds,internalDate']);
});

it('rejects incomplete Gmail selection metadata without hydrating bodies', function (array $changes): void {
    $history = [];
    $resource = gmailBodyResource([array_replace(['id' => 'message', 'threadId' => 'thread',
        'labelIds' => ['INBOX'], 'internalDate' => '1793044800000'], $changes)], $history);

    expect(fn () => $resource->metadata())->toThrow(MailboxException::class);
    expect($history)->toHaveCount(1);
})->with([
    'wrong message' => [['id' => 'another']],
    'missing thread' => [['threadId' => null]],
    'invalid labels' => [['labelIds' => [12]]],
    'missing activity' => [['internalDate' => null]],
]);

it('bounds Gmail selection metadata to one request on a transient provider failure', function (): void {
    $history = [];
    $resource = gmailBodyResource([new Response(503, [], '{}')], $history);

    expect(fn () => $resource->metadata())->toThrow(MailboxException::class);
    expect($history)->toHaveCount(1);
});

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

it('reads a message root with a Content-ID as body rather than an inline file', function (string $mime, bool $external): void {
    $history = [];
    $content = $mime === 'text/html' ? '<p>Québec é</p>' : 'Québec é';
    $encoded = rtrim(strtr(base64_encode($content), '+/', '-_'), '=');
    $payload = ['partId' => '', 'mimeType' => $mime, 'headers' => [['name' => 'Content-ID', 'value' => '<message-root@example.com>']],
        'body' => $external ? ['attachmentId' => 'root-body', 'size' => strlen($content)] : ['data' => $encoded, 'size' => strlen($content)]];
    $responses = [['id' => 'message', 'payload' => $payload]];
    if ($external) {
        $responses[] = ['data' => $encoded, 'size' => strlen($content)];
    }
    $responses[] = ['id' => 'message', 'payload' => $payload];
    $resource = gmailBodyResource($responses, $history);

    $message = $resource->get();

    expect($message->body?->content)->toBe($content)
        ->and($message->body?->contentType)->toBe($mime === 'text/html' ? 'html' : 'text')
        ->and($message->hasAttachments)->toBeFalse()
        ->and($resource->attachments())->toBeEmpty()
        ->and($history)->toHaveCount($external ? 3 : 2);
})->with(['text/plain', 'text/html'])->with([false, true]);

it('retains explicitly selected message-root text files even when they have a Content-ID', function (bool $named): void {
    $history = [];
    $payload = ['partId' => '', 'mimeType' => 'text/plain', 'filename' => $named ? 'notes.txt' : '',
        'headers' => [['name' => 'Content-ID', 'value' => '<message-root@example.com>'],
            ['name' => 'Content-Disposition', 'value' => $named ? 'inline' : 'attachment']],
        'body' => ['attachmentId' => 'root-file', 'size' => 4]];
    $resource = gmailBodyResource([['id' => 'message', 'payload' => $payload], ['id' => 'message', 'payload' => $payload]], $history);

    $message = $resource->get();

    expect($message->body)->toBeNull()
        ->and($message->hasAttachments)->toBeTrue()
        ->and($resource->attachments()->pluck('id')->all())->toBe(['root-file'])
        ->and($history)->toHaveCount(2);
})->with(['named inline file' => true, 'explicit attachment' => false]);

it('reads the anonymous first mixed body with a Content-ID without exposing it as a downloadable file', function (string $mime, bool $external): void {
    $history = [];
    $content = $mime === 'text/html' ? '<p>Québec é</p>' : 'Québec é';
    $encoded = rtrim(strtr(base64_encode($content), '+/', '-_'), '=');
    $payload = ['mimeType' => 'multipart/mixed', 'parts' => [
        ['partId' => '0', 'mimeType' => $mime, 'headers' => [['name' => 'Content-ID', 'value' => '<body@example.com>']],
            'body' => $external ? ['attachmentId' => 'body', 'size' => strlen($content)] : ['data' => $encoded, 'size' => strlen($content)]],
        ['partId' => '1', 'mimeType' => 'text/plain', 'filename' => 'notes.txt', 'body' => ['attachmentId' => 'notes', 'size' => 4]],
        ['partId' => '2', 'mimeType' => 'image/png', 'filename' => 'image.png', 'body' => ['attachmentId' => 'image', 'size' => 2]],
    ]];
    $responses = [['id' => 'message', 'payload' => $payload]];
    if ($external) {
        $responses[] = ['data' => $encoded, 'size' => strlen($content)];
    }
    array_push($responses, ['id' => 'message', 'payload' => $payload], ['id' => 'message', 'payload' => $payload]);
    $resource = gmailBodyResource($responses, $history);

    $message = $resource->get();

    expect($message->body?->content)->toBe($content)
        ->and($message->hasAttachments)->toBeTrue()
        ->and($resource->attachments()->pluck('id')->all())->toBe(['1', '2']);
    expect(fn () => $resource->attachment($external ? 'body' : '0')->metadata())->toThrow(ResourceNotFoundException::class);
    expect($history)->toHaveCount($external ? 4 : 3);
})->with(['text/plain', 'text/html'])->with([false, true]);

it('keeps named, disposition-selected and non-primary mixed CID parts as files', function (string $case): void {
    $history = [];
    $file = ['partId' => 'cid-file', 'mimeType' => 'text/plain', 'headers' => [['name' => 'Content-ID', 'value' => '<file@example.com>']],
        'body' => ['data' => 'RmlsZQ', 'size' => 4]];
    if ($case === 'named') {
        $file['filename'] = 'notes.txt';
    } elseif (in_array($case, ['inline', 'attachment'], true)) {
        $file['headers'][] = ['name' => 'Content-Disposition', 'value' => $case];
    }
    $parts = match ($case) {
        'non-primary' => [['mimeType' => 'text/plain', 'body' => ['data' => 'Qm9keQ', 'size' => 4]], $file],
        'after named file' => [['partId' => 'leading', 'mimeType' => 'text/plain', 'filename' => 'leading.txt', 'body' => ['data' => 'TGVhZA', 'size' => 4]], $file],
        'nested named container' => [['partId' => 'container', 'mimeType' => 'multipart/mixed', 'filename' => 'bundle.mime', 'parts' => [$file]]],
        default => [$file],
    };
    $payload = ['mimeType' => 'multipart/mixed', 'parts' => $parts];
    $resource = gmailBodyResource([['id' => 'message', 'payload' => $payload], ['id' => 'message', 'payload' => $payload]], $history);

    $message = $resource->get();

    expect($message->body?->content)->toBe($case === 'non-primary' ? 'Body' : null)
        ->and($resource->attachments()->pluck('id')->all())->toBe(match ($case) {
            'after named file' => ['leading', 'cid-file'], 'nested named container' => ['container'], default => ['cid-file'],
        })
        ->and($history)->toHaveCount(2);
})->with(['named', 'inline', 'attachment', 'non-primary', 'after named file', 'nested named container']);

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

    expect(fn () => $resource->{$operation}())->toThrow(MailboxException::class)
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

it('streams listed Gmail files when their download handles change', function (string $partId): void {
    $history = [];
    $part = ['partId' => $partId, 'mimeType' => 'text/plain', 'filename' => 'notes.txt',
        'body' => ['attachmentId' => 'old-handle', 'size' => 2]];
    $resource = gmailBodyResource([
        ['id' => 'message', 'payload' => ['mimeType' => 'multipart/mixed', 'parts' => [$part]]],
        ['id' => 'message', 'payload' => ['mimeType' => 'multipart/mixed', 'parts' => [
            [...$part, 'body' => ['attachmentId' => 'new-handle', 'size' => 2]],
        ]]],
        ['data' => 'T0s', 'size' => 2],
    ], $history);

    $attachment = $resource->attachments()->sole();
    $stream = $resource->attachment($attachment->id)->stream();

    try {
        expect($attachment->id)->toBe($partId);
        expect((string) $stream)->toBe('OK');
        expect($history)->toHaveCount(3);
        expect($history[2]['request']->getUri()->getPath())->toBe('/gmail/v1/users/reader%40example.com/messages/message/attachments/new-handle');
    } finally {
        $stream->close();
    }
})->with(['nested part' => '1', 'zero part' => '0']);

it('downloads listed Gmail files in bulk after their handles change', function (): void {
    Storage::fake('gmail-body-tests');
    config()->set('mailbox.attachment_disk', 'gmail-body-tests');
    $history = [];
    $part = ['partId' => '0', 'mimeType' => 'text/plain', 'filename' => 'notes.txt',
        'body' => ['attachmentId' => 'old-handle', 'size' => 2]];
    $resource = gmailBodyResource([
        ['id' => 'message', 'payload' => $part],
        ['id' => 'message', 'payload' => [...$part, 'body' => ['attachmentId' => 'new-handle', 'size' => 2]]],
        ['data' => 'T0s', 'size' => 2],
    ], $history);

    $file = $resource->downloadAttachments()->sole();

    Storage::disk('gmail-body-tests')->assertExists($file->path);
    expect(Storage::disk('gmail-body-tests')->get($file->path))->toBe('OK');
    expect($history)->toHaveCount(3);
    expect($history[2]['request']->getUri()->getPath())->toBe('/gmail/v1/users/reader%40example.com/messages/message/attachments/new-handle');
});

it('keeps Gmail download handles usable when a part identity is missing or empty', function (array $identity): void {
    $history = [];
    $message = ['id' => 'message', 'payload' => [...$identity, 'mimeType' => 'text/plain', 'filename' => 'notes.txt',
        'body' => ['attachmentId' => 'legacy-handle', 'size' => 2]]];
    $resource = gmailBodyResource([$message, $message, ['data' => 'T0s', 'size' => 2]], $history);

    $attachment = $resource->attachments()->sole();
    $stream = $resource->attachment($attachment->id)->stream();

    try {
        expect($attachment->id)->toBe('legacy-handle');
        expect((string) $stream)->toBe('OK');
    } finally {
        $stream->close();
    }
})->with(['missing part' => [[]], 'empty part' => [['partId' => '']]]);

it('rejects unsupported charsets and invalid text rather than replacing bytes', function (string $charset, string $data): void {
    $history = [];
    $resource = gmailBodyResource([['id' => 'message', 'payload' => [
        'mimeType' => 'text/plain',
        'headers' => [['name' => 'Content-Type', 'value' => 'text/plain; charset='.$charset]],
        'body' => ['data' => $data],
    ]]], $history);

    expect(fn () => $resource->get())->toThrow(MailboxException::class);
})->with([['unknown-charset', 'SGVsbG8'], ['utf-8', '_w']]);

it('preserves Gmail UTF-8 text when its size describes the original charset bytes', function (bool $external): void {
    $history = [];
    $body = $external ? ['attachmentId' => 'body', 'size' => 4] : ['data' => 'Y2Fmw6k', 'size' => 4];
    $responses = [['id' => 'message', 'payload' => ['mimeType' => 'text/plain', 'headers' => [
        ['name' => 'Content-Type', 'value' => 'text/plain; charset=iso-8859-1'],
        ['name' => 'Content-Transfer-Encoding', 'value' => 'quoted-printable'],
    ], 'body' => $body]]];
    if ($external) {
        $responses[] = ['data' => 'Y2Fmw6k', 'size' => 4];
    }
    $resource = gmailBodyResource($responses, $history);

    expect($resource->body()->content)->toBe('café')
        ->and($history)->toHaveCount($external ? 2 : 1);
})->with([false, true]);

it('rejects unexplained Gmail text size or charset normalization', function (array $headers, string $encoded, mixed $size): void {
    $history = [];
    $resource = gmailBodyResource([['id' => 'message', 'payload' => [
        'mimeType' => 'text/plain', 'headers' => $headers, 'body' => ['data' => $encoded, 'size' => $size],
    ]]], $history);

    expect(fn () => $resource->body())->toThrow(MailboxException::class)
        ->and($history)->toHaveCount(1);
})->with([
    'missing charset' => [[], 'Y2Fmw6k', 4],
    'unsupported charset' => [[['name' => 'Content-Type', 'value' => 'text/plain; charset=unknown-charset']], 'Y2Fmw6k', 4],
    'conflicting charsets' => [[['name' => 'Content-Type', 'value' => 'text/plain; charset=UTF-8'],
        ['name' => 'Content-Type', 'value' => 'text/plain; charset=iso-8859-1']], 'Y2Fmw6k', 4],
    'conflicting charset parameters' => [[['name' => 'Content-Type', 'value' => 'text/plain; charset=UTF-8; charset=iso-8859-1']], 'Y2Fmw6k', 4],
    'invalid UTF-8 mismatch' => [[['name' => 'Content-Type', 'value' => 'text/plain; charset=iso-8859-1']], '_w', 2],
    'lossy roundtrip' => [[['name' => 'Content-Type', 'value' => 'text/plain; charset=iso-8859-1']], '8J-YgA', 1],
    'incorrect reencoded size' => [[['name' => 'Content-Type', 'value' => 'text/plain; charset=iso-8859-1']], 'Y2Fmw6k', 3],
    'noninteger size' => [[['name' => 'Content-Type', 'value' => 'text/plain; charset=iso-8859-1']], 'Y2Fmw6k', '4'],
    'negative size' => [[['name' => 'Content-Type', 'value' => 'text/plain; charset=iso-8859-1']], 'Y2Fmw6k', -1],
]);

it('charges the actual Gmail UTF-8 bytes against the body budget after normalization', function (): void {
    $history = [];
    $resource = gmailBodyResource([['id' => 'message', 'payload' => [
        'mimeType' => 'text/plain',
        'headers' => [['name' => 'Content-Type', 'value' => 'text/plain; charset=iso-8859-1']],
        'body' => ['data' => 'w6k', 'size' => 1],
    ]]], $history);

    expect(fn () => $resource->body(new BodyReadLimits(maxBodyBytes: 1)))->toThrow(ResponseLimitExceededException::class)
        ->and($history)->toHaveCount(1);
});

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
    $reads = 0;
    $stream = new FnStream([
        'getSize' => fn (): ?int => null,
        'eof' => fn (): bool => false,
        'read' => function (int $length) use (&$reads): string {
            if ($reads++ === 0) {
                return '{"data":"private-partial-text';
            }
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

    $content = null;
    $failure = null;
    try {
        $content = $resource->body()->content;
    } catch (MailboxException $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(ProviderTransportException::class)
        ->and((string) $failure)->not->toContain('Private provider stream detail.', 'private-partial-text')
        ->and($content)->toBeNull()
        ->and($history)->toHaveCount(2)
        ->and($reads)->toBe(2)
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

<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\RequestInterface;
use Pyle\Mailbox\Contracts\AttachmentResource;
use Pyle\Mailbox\Contracts\SupportsAttachmentReadLimits;
use Pyle\Mailbox\DTOs\AttachmentReadLimits;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Events\AttachmentDownloaded;
use Pyle\Mailbox\Events\AttachmentSkipped;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('rejects truncated Gmail attachment encoding instead of returning an empty file', function (): void {
    $history = [];
    $attachment = attachmentReadResource('gmail', [
        ['payload' => ['mimeType' => 'application/octet-stream', 'filename' => 'file.bin', 'body' => ['attachmentId' => 'attachment', 'size' => 1]]],
        ['size' => 1, 'data' => 'A'],
    ], $history);

    expect(fn () => $attachment->stream())->toThrow(MailboxException::class);
});

it('rejects Gmail attachment bytes that do not match both provider size declarations', function (string $operation): void {
    Storage::fake('attachment-read-tests');
    config()->set('mailbox.attachment_disk', 'attachment-read-tests');
    Storage::disk('attachment-read-tests')->put('host-owned.txt', 'retain');
    $history = [];
    $attachment = attachmentReadResource('gmail', [
        ['payload' => ['mimeType' => 'application/octet-stream', 'filename' => 'file.bin', 'body' => ['attachmentId' => 'attachment', 'size' => 4]]],
        ['size' => 3, 'data' => 'T0s'],
    ], $history);

    expect(fn () => $attachment->{$operation}())->toThrow(MailboxException::class);
    expect(Storage::disk('attachment-read-tests')->allFiles())->toBe(['host-owned.txt']);
})->with(['stream', 'download']);

it('does not turn a missing Gmail attachment payload into valid empty content', function (): void {
    $history = [];
    $attachment = attachmentReadResource('gmail', [
        ['payload' => ['mimeType' => 'application/octet-stream', 'filename' => 'file.bin', 'body' => ['attachmentId' => 'attachment', 'size' => 0]]],
        [],
    ], $history);

    expect(fn () => $attachment->stream())->toThrow(MailboxException::class);
});

it('returns actual valid empty Gmail attachments', function (array $body): void {
    $history = [];
    $attachment = attachmentReadResource('gmail', [
        ['payload' => ['mimeType' => 'application/octet-stream', 'filename' => 'file.bin', 'body' => ['attachmentId' => 'attachment', 'size' => 0]]],
        $body,
    ], $history);
    $stream = $attachment->stream();

    try {
        expect((string) $stream)->toBe('')->and($stream->getSize())->toBe(0);
    } finally {
        $stream->close();
    }
})->with([[['size' => 0]], [['size' => 0, 'data' => '']]]);

it('bounds actual Gmail response bytes across metadata and content despite a small HTTP declaration', function (): void {
    $history = [];
    $attachment = attachmentReadResource('gmail', [
        ['payload' => ['mimeType' => 'application/octet-stream', 'filename' => 'file.bin', 'body' => ['attachmentId' => 'attachment', 'size' => 3]]],
        new Response(200, ['Content-Length' => '2'], '{"data":"'.str_repeat('YWFh', 200).'","size":3}'),
    ], $history);
    expect($attachment)->toBeInstanceOf(SupportsAttachmentReadLimits::class);
    $bounded = $attachment->withReadLimits(new AttachmentReadLimits(maxResponseBytes: 256));

    expect(fn () => $bounded->stream())->toThrow(ResponseLimitExceededException::class);
    expect($history)->toHaveCount(2)
        ->and($history[1]['response']->getBody()->isReadable())->toBeFalse();
});

it('rejects dense Gmail JSON before native decoding after transport initialization', function (bool $malformed): void {
    $history = [];
    $json = '{"ignored":['.str_repeat('{"a":0},', 20000).'{}],"payload":{"partId":"attachment","mimeType":"application/octet-stream","filename":"empty.bin","body":{"data":"","size":0}}}';
    if ($malformed) {
        $json = substr($json, 0, -1);
    }
    $attachment = attachmentReadResource('gmail', [
        ['payload' => ['partId' => 'attachment', 'mimeType' => 'application/octet-stream', 'filename' => 'empty.bin', 'body' => ['data' => '', 'size' => 0]]],
        new Response(200, [], $json),
    ], $history);
    unset($json);
    $attachment->metadata();
    $bounded = $attachment->withReadLimits(new AttachmentReadLimits(maxMemoryBytes: 2097152));
    memory_reset_peak_usage();
    $baseline = memory_get_usage();

    expect(fn () => $bounded->stream())->toThrow(ResponseLimitExceededException::class);
    expect(memory_get_peak_usage() - $baseline)->toBeLessThan(2097152)
        ->and($history[1]['response']->getBody()->isReadable())->toBeFalse();
})->with(['valid JSON' => false, 'truncated JSON' => true])->group('attachment-memory');

it('decodes a ten MiB Gmail file without allocating whole decoded or reencoded copies', function (): void {
    $body = tmpfile();
    fwrite($body, '{"size":10485760,"data":"');
    $expected = hash_init('sha256');
    $remaining = 10485760;
    while ($remaining > 0) {
        $chunk = str_repeat('a', min(49152, $remaining));
        hash_update($expected, $chunk);
        fwrite($body, rtrim(strtr(base64_encode($chunk), '+/', '-_'), '='));
        $remaining -= strlen($chunk);
    }
    fwrite($body, '"}');
    rewind($body);
    unset($chunk);
    $history = [];
    $attachment = attachmentReadResource('gmail', [
        ['payload' => ['mimeType' => 'application/octet-stream', 'filename' => 'large.bin', 'body' => ['attachmentId' => 'attachment', 'size' => 10485760]]],
        new Response(200, [], Utils::streamFor($body)),
    ], $history);
    $bounded = $attachment->withReadLimits(new AttachmentReadLimits(maxMemoryBytes: 52428800));
    memory_reset_peak_usage();
    $baseline = memory_get_usage();
    $stream = $bounded->stream();
    $peak = memory_get_peak_usage() - $baseline;

    try {
        $actual = hash_init('sha256');
        $bytes = 0;
        while (! $stream->eof()) {
            $chunk = $stream->read(65536);
            $bytes += strlen($chunk);
            hash_update($actual, $chunk);
        }
        expect($bytes)->toBe(10485760)->and(hash_final($actual))->toBe(hash_final($expected));
        expect($peak)->toBeLessThan(52428800);
        fwrite(STDERR, sprintf("Gmail 10 MiB read peak growth: %d bytes\n", $peak));
    } finally {
        $stream->close();
    }
})->group('attachment-memory');

it('enforces Gmail file limits against declared and actual decoded bytes', function (int $declared, int $requests): void {
    $history = [];
    $attachment = attachmentReadResource('gmail', [
        ['payload' => ['mimeType' => 'application/octet-stream', 'filename' => 'file.bin', 'body' => ['attachmentId' => 'attachment', 'size' => $declared]]],
        ['size' => $declared, 'data' => 'YWFhYQ'],
    ], $history);
    $bounded = $attachment->withReadLimits(new AttachmentReadLimits(maxFileBytes: 3));

    expect(fn () => $bounded->stream())->toThrow(ResponseLimitExceededException::class);
    expect($history)->toHaveCount($requests);
})->with(['declared' => [4, 1], 'observed despite a small declaration' => [2, 2]]);

it('enforces Graph file limits against actual HTTP bytes before returning a stream', function (): void {
    $history = [];
    $attachment = attachmentReadResource('ms-graph', [
        ['id' => 'attachment', 'name' => 'file.bin', 'size' => 2],
        new Response(200, ['Content-Length' => '2'], 'four'),
    ], $history);
    expect($attachment)->toBeInstanceOf(SupportsAttachmentReadLimits::class);
    $bounded = $attachment->withReadLimits(new AttachmentReadLimits(maxFileBytes: 3));

    expect(fn () => $bounded->stream())->toThrow(ResponseLimitExceededException::class);
    expect($history)->toHaveCount(2)->and($history[1]['response']->getBody()->isReadable())->toBeFalse();
});

it('stores a ten MiB Graph file with bounded memory after transport initialization', function (): void {
    Storage::fake('attachment-read-tests');
    config()->set('mailbox.attachment_disk', 'attachment-read-tests');
    Event::fake([AttachmentDownloaded::class, AttachmentSkipped::class]);
    $body = tmpfile();
    $chunk = str_repeat('g', 65536);
    for ($index = 0; $index < 160; $index++) {
        fwrite($body, $chunk);
    }
    rewind($body);
    $expected = hash_init('sha256');
    hash_update_stream($expected, $body);
    rewind($body);
    unset($chunk);
    $history = [];
    $metadata = ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'attachment', 'name' => 'large.bin', 'size' => 10485760, 'isInline' => true, 'contentId' => '<large>'];
    $attachment = attachmentReadResource('ms-graph', [
        $metadata,
        $metadata,
        new Response(200, ['Content-Length' => '10485760'], Utils::streamFor($body)),
    ], $history);
    $attachment->metadata();
    $bounded = $attachment->withReadLimits(new AttachmentReadLimits(maxMemoryBytes: 2097152));
    memory_reset_peak_usage();
    $baseline = memory_get_usage();
    $file = $bounded->download();
    $peak = memory_get_peak_usage() - $baseline;

    expect($peak)->toBeLessThan(2097152);
    expect($file->size)->toBe(10485760)->and($file->isInline)->toBeTrue()->and($file->contentId)->toBe('<large>');
    $saved = Storage::disk($file->disk)->readStream($file->path);
    try {
        $actual = hash_init('sha256');
        expect(hash_update_stream($actual, $saved))->toBe(10485760)->and(hash_final($actual))->toBe(hash_final($expected));
    } finally {
        fclose($saved);
    }
    Event::assertDispatchedTimes(AttachmentDownloaded::class, 1);
    Event::assertNotDispatched(AttachmentSkipped::class);
    fwrite(STDERR, sprintf("Graph 10 MiB download peak growth: %d bytes\n", $peak));
})->group('attachment-memory');

it('rejects incomplete or malformed Graph HTTP content lengths', function (string $declared): void {
    $history = [];
    $attachment = attachmentReadResource('ms-graph', [
        ['id' => 'attachment', 'name' => 'file.bin', 'size' => 2],
        new Response(200, ['Content-Length' => $declared], 'OK'),
    ], $history);

    expect(fn () => $attachment->stream())->toThrow(MailboxException::class);
    expect($history[1]['response']->getBody()->isReadable())->toBeFalse();
})->with(['truncated' => '3', 'excess bytes' => '1', 'negative' => '-1', 'nonnumeric' => 'bad', 'integer overflow' => '18446744073709551616']);

it('preserves unrelated files when the hash-suffix destination also exists', function (string $provider): void {
    Storage::fake('attachment-read-tests');
    config()->set('mailbox.attachment_disk', 'attachment-read-tests');
    Event::fake([AttachmentDownloaded::class, AttachmentSkipped::class]);
    $preferred = 'mailbox-attachments/reader_example_com/message/file.bin';
    $fallback = 'mailbox-attachments/reader_example_com/message/file-11507a0e2f5e.bin';
    Storage::disk('attachment-read-tests')->put($preferred, 'host-original');
    Storage::disk('attachment-read-tests')->put($fallback, 'host-collision');
    $history = [];
    $attachment = attachmentReadResource($provider, $provider === 'gmail' ? [
        ['payload' => ['mimeType' => 'application/octet-stream', 'filename' => 'file.bin', 'body' => ['attachmentId' => 'attachment', 'size' => 3]]],
        ['size' => 3, 'data' => 'bmV3'],
    ] : [
        ['id' => 'attachment', 'name' => 'file.bin', 'size' => 3],
        new Response(200, [], 'new'),
    ], $history);

    expect(fn () => $attachment->download())->toThrow(MailboxException::class);
    expect(Storage::disk('attachment-read-tests')->get($preferred))->toBe('host-original')
        ->and(Storage::disk('attachment-read-tests')->get($fallback))->toBe('host-collision');
    Event::assertNotDispatched(AttachmentDownloaded::class);
    Event::assertNotDispatched(AttachmentSkipped::class);
})->with(['gmail', 'ms-graph']);

it('reserves memory before inspecting dense Graph error JSON', function (): void {
    $history = [];
    $metadata = ['id' => 'attachment', 'name' => 'file.bin', 'size' => 2];
    $attachment = attachmentReadResource('ms-graph', [
        $metadata,
        new Response(400, [], '{"error":{"code":"bad"},"ignored":['.str_repeat('{"a":0},', 20000).'{}]}'),
    ], $history);
    $attachment->metadata();
    $bounded = $attachment->withReadLimits(new AttachmentReadLimits(maxMemoryBytes: 2097152));
    memory_reset_peak_usage();
    $baseline = memory_get_usage();

    expect(fn () => $bounded->metadata())->toThrow(ResponseLimitExceededException::class);
    expect(memory_get_peak_usage() - $baseline)->toBeLessThan(2097152);
})->group('attachment-memory');

it('reserves memory for Gmail MIME parameters before invoking the existing parser', function (): void {
    $header = 'application/octet-stream; ';
    for ($index = 0; $index < 1000; $index++) {
        $header .= 'parameter'.$index.'=x; ';
    }
    $history = [];
    $attachment = attachmentReadResource('gmail', [
        ['payload' => ['partId' => 'attachment', 'filename' => 'file.bin', 'body' => ['data' => 'T0s', 'size' => 2]]],
        ['payload' => ['partId' => 'attachment', 'mimeType' => 'application/octet-stream', 'headers' => [['name' => 'Content-Type', 'value' => $header.'name="file.bin"']], 'body' => ['data' => 'T0s', 'size' => 2]]],
    ], $history);
    $attachment->metadata();
    $bounded = $attachment->withReadLimits(new AttachmentReadLimits(maxMemoryBytes: 2097152));
    memory_reset_peak_usage();
    $baseline = memory_get_usage();

    expect(fn () => $bounded->stream())->toThrow(ResponseLimitExceededException::class);
    expect(memory_get_peak_usage() - $baseline)->toBeLessThan(2097152);
})->group('attachment-memory');

it('reserves path copies before passing long provider names to storage', function (): void {
    Storage::fake('attachment-read-tests');
    config()->set('mailbox.attachment_disk', 'attachment-read-tests');
    $history = [];
    $attachment = attachmentReadResource('ms-graph', [
        ['id' => 'attachment', 'name' => 'file.bin', 'size' => 2],
        ['id' => 'attachment', 'name' => str_repeat('n', 262144), 'size' => 2],
        new Response(200, [], 'OK'),
    ], $history);
    $attachment->metadata();
    $bounded = $attachment->withReadLimits(new AttachmentReadLimits(maxMemoryBytes: 2097152));
    memory_reset_peak_usage();
    $baseline = memory_get_usage();

    expect(fn () => $bounded->download())->toThrow(ResponseLimitExceededException::class);
    expect(memory_get_peak_usage() - $baseline)->toBeLessThan(2097152)
        ->and(Storage::disk('attachment-read-tests')->allFiles())->toBeEmpty();
})->group('attachment-memory');

it('closes interrupted provider and temporary streams without storing partial files or leaking diagnostics', function (string $provider): void {
    Storage::fake('attachment-read-tests');
    config()->set('mailbox.attachment_disk', 'attachment-read-tests');
    Storage::disk('attachment-read-tests')->put('host-owned.txt', 'retain');
    $reads = 0;
    $closed = false;
    $source = Utils::streamFor('');
    $body = FnStream::decorate($source, [
        'read' => function (int $length) use (&$reads): string {
            if ($reads++ === 0) {
                return 'partial';
            }
            throw new RuntimeException('https://provider.example/?access_token=interruption-private');
        },
        'close' => function () use (&$closed, $source): void {
            $closed = true;
            $source->close();
        },
    ]);
    $metadata = $provider === 'gmail'
        ? ['payload' => ['filename' => 'file.bin', 'body' => ['attachmentId' => 'attachment', 'size' => 7]]]
        : ['id' => 'attachment', 'name' => 'file.bin', 'size' => 7];
    $history = [];
    $attachment = attachmentReadResource($provider, [$metadata, $metadata, new Response(200, [], $body)], $history);
    $attachment->metadata();
    $resources = get_resources('stream');
    $failure = null;
    try {
        $attachment->download();
    } catch (MailboxException $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(MailboxException::class)->and($closed)->toBeTrue();
    expect($failure->getMessage())->not->toContain('interruption-private');
    $files = array_filter(array_map(
        static fn ($resource): string => (string) (stream_get_meta_data($resource)['uri'] ?? ''),
        array_diff_key(get_resources('stream'), $resources),
    ), static fn (string $path): bool => is_file($path));
    expect($files)->toBeEmpty()
        ->and(Storage::disk('attachment-read-tests')->allFiles())->toBe(['host-owned.txt']);
})->with(['gmail', 'ms-graph']);

it('removes owned file streams on explicit close or caller abandonment', function (string $provider, bool $close): void {
    $history = [];
    $attachment = attachmentReadResource($provider, $provider === 'gmail' ? [
        ['payload' => ['partId' => 'attachment', 'filename' => 'file.bin', 'body' => ['data' => 'T0s', 'size' => 2]]],
    ] : [
        ['id' => 'attachment', 'name' => 'file.bin', 'size' => 2],
        new Response(200, ['Content-Length' => '2'], 'OK'),
    ], $history);
    $stream = $attachment->stream();
    $path = $stream->getMetadata('uri');
    expect((string) $stream)->toBe('OK')->and(is_file($path))->toBeTrue();

    if ($close) {
        $stream->close();
    } else {
        unset($stream);
    }
    clearstatcache(true, $path);
    expect(is_file($path))->toBeFalse();
})->with([
    ['gmail', true], ['gmail', false], ['ms-graph', true], ['ms-graph', false],
]);

it('preserves valid empty files and gzip media bytes without treating them as HTTP encoding', function (string $bytes): void {
    $history = [];
    $attachment = attachmentReadResource('ms-graph', [
        ['id' => 'attachment', 'name' => 'file.gz', 'size' => strlen($bytes)],
        new Response(200, ['Content-Type' => 'application/gzip', 'Content-Length' => (string) strlen($bytes)], $bytes),
    ], $history);
    $stream = $attachment->stream();
    try {
        expect((string) $stream)->toBe($bytes)->and($stream->getSize())->toBe(strlen($bytes));
    } finally {
        $stream->close();
    }
})->with(['empty' => '', 'literal zero' => '0', 'gzip file' => gzencode('file')]);

it('keeps provider storage segments contained and replaces dot-only file names', function (string $provider): void {
    Storage::fake('attachment-read-tests');
    config()->set('mailbox.attachment_disk', 'attachment-read-tests');
    $history = [];
    $attachment = attachmentReadResource($provider, $provider === 'gmail' ? [
        ['payload' => ['partId' => 'attachment', 'filename' => '..', 'body' => ['data' => 'T0s', 'size' => 2]]],
    ] : [
        ['id' => 'attachment', 'name' => '..', 'size' => 2], new Response(200, [], 'OK'),
    ], $history, 'reader/department@example.com', 'opaque/id+=');

    $file = $attachment->download();
    expect($file->name)->toBe('..')
        ->and($file->path)->toBe('mailbox-attachments/reader_department_example_com/opaque_id+=/attachment.bin')
        ->and(Storage::disk($file->disk)->get($file->path))->toBe('OK');
})->with(['gmail', 'ms-graph']);

it('rejects hostile attachment redirects and closes their response without exposing access material', function (string $provider): void {
    $history = [];
    $responses = [new Response(302, ['Location' => 'https://evil.example/file?access_token=redirect-private'], 'redirect')];
    if ($provider === 'ms-graph') {
        array_unshift($responses, ['id' => 'attachment', 'name' => 'file.bin', 'size' => 2]);
    }
    $attachment = attachmentReadResource($provider, $responses, $history);
    $failure = null;
    try {
        $attachment->stream();
    } catch (MailboxException $exception) {
        $failure = $exception;
    }

    $content = $history[array_key_last($history)];
    expect($failure)->toBeInstanceOf(MailboxException::class)
        ->and($history)->toHaveCount($provider === 'ms-graph' ? 2 : 1)
        ->and($content['options']['allow_redirects'])->toBeFalse()
        ->and($content['response']->getBody()->isReadable())->toBeFalse();
    if ($provider === 'ms-graph') {
        expect($content['request']->getUri()->getPath())->toEndWith('/$value');
    }
    expect($failure->getMessage())->not->toContain('redirect-private');
})->with(['gmail', 'ms-graph']);

it('preserves a Graph file size hint while storing bounded measured raw bytes', function (): void {
    Storage::fake('attachment-read-tests');
    config()->set('mailbox.attachment_disk', 'attachment-read-tests');
    $history = [];
    $metadata = ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'attachment', 'name' => 'file.bin', 'size' => 99];
    $attachment = attachmentReadResource('ms-graph', [
        $metadata, $metadata, new Response(200, ['Content-Length' => '2'], 'OK'),
    ], $history)->withReadLimits(new AttachmentReadLimits(maxFileBytes: 2));

    expect($attachment->metadata()->size)->toBe(99);
    $file = $attachment->download();

    expect($file->size)->toBe(2);
    expect(Storage::disk($file->disk)->get($file->path))->toBe('OK');
    expect($history)->toHaveCount(3);
});

it('rejects incomplete Graph file streams against the HTTP content length', function (string $bytes): void {
    $response = static fn (RequestInterface $request): Response => str_ends_with($request->getUri()->getPath(), '/$value')
        ? new Response(200, ['Content-Length' => '4'], $bytes)
        : new Response(200, [], '{"@odata.type":"#microsoft.graph.fileAttachment","id":"attachment","name":"file.bin","size":99}');
    $history = [];
    $attachment = attachmentReadResource('ms-graph', [$response, $response], $history);

    expect(fn () => $attachment->stream())->toThrow(MailboxException::class, 'The provider response length does not match its declaration.');
})->with(['short chunked content' => 'OK', 'empty incomplete content' => '']);

it('shares the Graph stream response budget across metadata and file bytes', function (): void {
    $metadata = '{"id":"attachment","size":2}';
    $response = static fn (RequestInterface $request): Response => new Response(200, [], str_ends_with($request->getUri()->getPath(), '/$value') ? 'OK' : $metadata);
    $history = [];
    $attachment = attachmentReadResource('ms-graph', [$response, $response], $history)
        ->withReadLimits(new AttachmentReadLimits(maxResponseBytes: strlen($metadata) + 1));

    expect(fn () => $attachment->stream())->toThrow(ResponseLimitExceededException::class);
    expect($history)->toHaveCount(2)->and($history[1]['response']->getBody()->isReadable())->toBeFalse();
});

it('retains Graph item MIME bytes whose length differs from the item metadata size', function (): void {
    $history = [];
    $attachment = attachmentReadResource('ms-graph', [
        ['@odata.type' => '#microsoft.graph.itemAttachment', 'id' => 'attachment', 'name' => 'message.eml', 'size' => 99],
        new Response(200, [], "MIME-Version: 1.0\r\n\r\nHello"),
    ], $history);
    $stream = $attachment->stream();

    try {
        expect((string) $stream)->toBe("MIME-Version: 1.0\r\n\r\nHello")->and($stream->getSize())->toBe(26);
    } finally {
        $stream->close();
    }
});

it('rejects unsupported Graph reference streams before requesting file bytes', function (): void {
    $history = [];
    $attachment = attachmentReadResource('ms-graph', [
        ['@odata.type' => '#microsoft.graph.referenceAttachment', 'id' => 'attachment', 'name' => 'reference', 'size' => 2],
        new Response(200, [], 'OK'),
    ], $history);

    expect(fn () => $attachment->stream())->toThrow(MailboxException::class, 'This Graph attachment form does not provide supported file bytes.');
    expect($history)->toHaveCount(1);
});

it('reports stored byte length while retaining the provider metadata declaration', function (string $provider, int $declared, int $observed): void {
    Storage::fake('attachment-read-tests');
    config()->set('mailbox.attachment_disk', 'attachment-read-tests');
    $metadata = $provider === 'gmail'
        ? ['payload' => ['partId' => 'attachment', 'mimeType' => 'application/octet-stream', 'filename' => 'file.bin', 'body' => ['data' => 'T0s']]]
        : ['@odata.type' => '#microsoft.graph.itemAttachment', 'id' => 'attachment', 'name' => 'message.eml', 'contentType' => 'message/rfc822', 'size' => 99];
    $history = [];
    $attachment = attachmentReadResource($provider, $provider === 'gmail' ? [$metadata, $metadata] : [
        $metadata, $metadata, new Response(200, ['Content-Length' => '26'], "MIME-Version: 1.0\r\n\r\nHello"),
    ], $history);

    expect($attachment->metadata()->size)->toBe($declared);
    $file = $attachment->download();
    expect($file->size)->toBe($observed)
        ->and(Storage::disk($file->disk)->size($file->path))->toBe($observed);
})->with(['omitted Gmail declaration' => ['gmail', 0, 2], 'Graph item MIME representation' => ['ms-graph', 99, 26]]);

it('rejects malformed or unsupported attachment content forms without storing bytes', function (string $provider, array $metadata): void {
    Storage::fake('attachment-read-tests');
    config()->set('mailbox.attachment_disk', 'attachment-read-tests');
    $history = [];
    $attachment = attachmentReadResource($provider, [$metadata, new Response(200, [], 'OK')], $history);

    expect(fn () => $attachment->download())->toThrow(MailboxException::class);
    expect(Storage::disk('attachment-read-tests')->allFiles())->toBeEmpty();
})->with([
    'Gmail wrong inline type' => ['gmail', ['payload' => ['partId' => 'attachment', 'filename' => 'file.bin', 'body' => ['data' => 100, 'size' => 2]]]],
    'Gmail absent empty evidence' => ['gmail', ['payload' => ['partId' => 'attachment', 'filename' => 'file.bin', 'body' => []]]],
    'Gmail ambiguous inline and external bytes' => ['gmail', ['payload' => ['partId' => 'attachment', 'filename' => 'file.bin', 'body' => ['data' => 'T0s', 'attachmentId' => 'attachment', 'size' => 2]]]],
    'Graph reference attachment' => ['ms-graph', ['@odata.type' => '#microsoft.graph.referenceAttachment', 'id' => 'attachment', 'name' => 'file.bin', 'size' => 2]],
    'Graph wrong identity' => ['ms-graph', ['id' => 'different', 'name' => 'file.bin', 'size' => 2]],
    'Graph malformed size' => ['ms-graph', ['id' => 'attachment', 'name' => 'file.bin', 'size' => '2']],
]);

it('rejects HTTP content encoding before reading bytes and disables transparent inflation', function (string $provider): void {
    $reads = 0;
    $closed = false;
    $source = Utils::streamFor('encoded');
    $body = FnStream::decorate($source, [
        'read' => function (int $length) use (&$reads, $source): string {
            $reads++;

            return $source->read($length);
        },
        'close' => function () use (&$closed, $source): void {
            $closed = true;
            $source->close();
        },
    ]);
    $history = [];
    $responses = [new Response(200, ['Content-Encoding' => 'gzip'], $body)];
    if ($provider === 'ms-graph') {
        array_unshift($responses, ['id' => 'attachment', 'name' => 'file.bin', 'size' => 2]);
    }
    $attachment = attachmentReadResource($provider, $responses, $history);

    expect(fn () => $attachment->stream())->toThrow(MailboxException::class);
    $content = $history[array_key_last($history)];
    expect($reads)->toBe(0)->and($closed)->toBeTrue()
        ->and($content['request']->getHeaderLine('Accept-Encoding'))->toBe('identity')
        ->and($content['options']['decode_content'])->toBeFalse();
    if ($provider === 'ms-graph') {
        expect($history)->toHaveCount(2)
            ->and($content['request']->getUri()->getPath())->toEndWith('/$value');
    }
})->with(['gmail', 'ms-graph']);

/**
 * @param  list<array<string, mixed>|Response|Throwable|Closure>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function attachmentReadResource(string $provider, array $responses, array &$history, string $mailbox = 'reader@example.com', string $messageId = 'message'): AttachmentResource
{
    $stack = HandlerStack::create(new MockHandler(array_map(
        static fn (array|Response|Throwable|Closure $response): Response|Throwable|Closure => is_array($response)
            ? new Response(200, [], json_encode($response, JSON_THROW_ON_ERROR))
            : $response,
        $responses,
    )));
    $stack->push(Middleware::history($history));
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => [$mailbox], 'token' => 'selected-token'],
    ]);

    return Mailbox::forGrant(
        new GrantContext('connection', 'grant', '1', $provider, $mailbox),
        $tokens,
        new Client(['handler' => $stack]),
    )->message($messageId)->attachment('attachment');
}

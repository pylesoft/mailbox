<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Pyle\Mailbox\Contracts\GmailPushVerifier;
use Pyle\Mailbox\Contracts\SupportsConversationRead;
use Pyle\Mailbox\Contracts\SupportsMessageSubmission;
use Pyle\Mailbox\Contracts\SupportsSubmissionLimits;
use Pyle\Mailbox\Drivers\Gmail\Contracts\SupportsGmailWatch;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GmailWatchConfiguration;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\PreparedFile;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\SubmissionLimits;
use Pyle\Mailbox\Enums\SubmissionOutcome;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;
use ZBateson\MailMimeParser\MailMimeParser;

it('submits immutable international files and inline parts with exact caller intent', function (): void {
    $history = [];
    $mailbox = fileSubmissionMailbox([new Response(200, [], '{"id":"accepted-file-message"}')], $history);
    $attachment = "document\x00\xff\r\nbytes";
    $inline = "image\x00\xfe";
    $message = fileSubmissionMessage([
        new PreparedFile('document-1', 'Résumé Montréal.bin', 'application/octet-stream', strlen($attachment), static fn () => Utils::streamFor($attachment), hash('sha256', $attachment)),
        new PreparedFile('inline-1', 'Été.png', 'image/png', strlen($inline), static fn () => Utils::streamFor($inline), hash('sha256', $inline), inline: true, contentId: 'image-1@example.com'),
    ]);

    $result = $mailbox->submit($message);

    expect($result->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($result->correlationId)->toBe('files-operation-1')
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getMethod())->toBe('POST');
    $payload = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $mime = base64_decode(strtr($payload['raw'], '-_', '+/'), true);
    $parsed = (new MailMimeParser)->parse($mime, false);
    $files = $parsed->getAllAttachmentParts();

    expect($parsed->getTextContent())->toBe('Chosen text')
        ->and($parsed->getHtmlContent())->toBe('<p>Chosen HTML <img src="cid:image-1@example.com"></p>')
        ->and($parsed->getHeader('To')->getAddresses()[0]->getEmail())->toBe('to@example.com')
        ->and($parsed->getHeader('Cc')->getAddresses()[0]->getEmail())->toBe('cc@example.com')
        ->and($parsed->getHeader('Bcc')->getAddresses()[0]->getEmail())->toBe('bcc@example.com')
        ->and($files)->toHaveCount(2)
        ->and($files[0]->getFilename())->toBe('Été.png')
        ->and($files[0]->getContentType())->toBe('image/png')
        ->and($files[0]->getContentDisposition())->toBe('inline')
        ->and($files[0]->getContentId())->toBe('image-1@example.com')
        ->and($files[0]->getContent())->toBe($inline)
        ->and($files[1]->getFilename())->toBe('Résumé Montréal.bin')
        ->and($files[1]->getContentType())->toBe('application/octet-stream')
        ->and($files[1]->getContentDisposition())->toBe('attachment')
        ->and($files[1]->getContent())->toBe($attachment)
        ->and(config('database.default'))->toBe('sdk-forbidden');
});

it('preserves a selected inline file without inventing a Content-ID or rewriting HTML', function (): void {
    $history = [];
    $mailbox = fileSubmissionMailbox([new Response(200, [], '{"id":"accepted-file-message"}')], $history);
    $message = fileSubmissionMessage([
        new PreparedFile('inline-1', 'chosen-image', 'image/png', 5, static fn () => Utils::streamFor('image'), hash('sha256', 'image'), inline: true),
    ], '<p><img src="cid:chosen-image"></p>');

    $mailbox->submit($message);

    $payload = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $parsed = (new MailMimeParser)->parse(base64_decode(strtr($payload['raw'], '-_', '+/'), true), false);
    expect($parsed->getHtmlContent())->toBe('<p><img src="cid:chosen-image"></p>')
        ->and($parsed->getAttachmentPart(0)->getContentDisposition())->toBe('inline')
        ->and($parsed->getAttachmentPart(0)->getContentId())->toBeNull()
        ->and($parsed->getAttachmentPart(0)->getFilename())->toBe('chosen-image')
        ->and($parsed->getAttachmentPart(0)->getContent())->toBe('image');
});

it('retains provider-neutral Content-IDs and rejects unsupported Gmail IDs before opening sources', function (): void {
    $opened = false;
    $file = new PreparedFile('host-1', 'image.png', 'image/png', 5, static function () use (&$opened) {
        $opened = true;

        return Utils::streamFor('image');
    }, hash('sha256', 'image'), inline: true, contentId: 'chosen-graph-id');
    $history = [];
    $mailbox = fileSubmissionMailbox([], $history);

    expect($file->contentId)->toBe('chosen-graph-id');
    expect(fn () => $mailbox->submit(fileSubmissionMessage([$file])))->toThrow(UnsupportedCapabilityException::class)
        ->and($opened)->toBeFalse()
        ->and($history)->toBe([]);
});

it('rejects duplicate selected file identities or Content-IDs before opening sources', function (bool $duplicateIdentity): void {
    $opened = false;
    $source = static function () use (&$opened) {
        $opened = true;

        return Utils::streamFor('bytes');
    };
    $files = [
        new PreparedFile('first', 'first.bin', 'application/octet-stream', 5, $source, hash('sha256', 'bytes'), contentId: 'first@example.com'),
        new PreparedFile($duplicateIdentity ? 'first' : 'second', 'second.bin', 'application/octet-stream', 5, $source, hash('sha256', 'bytes'), contentId: $duplicateIdentity ? 'second@example.com' : 'first@example.com'),
    ];
    $history = [];
    $mailbox = fileSubmissionMailbox([], $history);

    expect(fn () => $mailbox->submit(fileSubmissionMessage($files)))->toThrow(InvalidArgumentException::class)
        ->and($opened)->toBeFalse()
        ->and($history)->toBe([]);
})->with(['duplicate identity' => true, 'duplicate Content-ID' => false]);

it('enforces configurable decoded file limits before opening any selected source', function (bool $aggregate): void {
    $opened = false;
    $source = static function () use (&$opened) {
        $opened = true;

        return Utils::streamFor('bytes');
    };
    $files = [new PreparedFile('first', 'first.bin', 'application/octet-stream', 5, $source, hash('sha256', 'bytes'))];
    if ($aggregate) {
        $files[] = new PreparedFile('second', 'second.bin', 'application/octet-stream', 5, $source, hash('sha256', 'bytes'));
    }
    $history = [];
    $mailbox = fileSubmissionMailbox([], $history);
    expect($mailbox)->toBeInstanceOf(SupportsSubmissionLimits::class);
    $limited = $mailbox->withSubmissionLimits(new SubmissionLimits(maxFileBytes: $aggregate ? 5 : 4, maxTotalFileBytes: 9));

    expect(fn () => $limited->submit(fileSubmissionMessage($files)))->toThrow(UnsupportedCapabilityException::class)
        ->and($opened)->toBeFalse()
        ->and($history)->toBe([]);
})->with(['single file' => false, 'aggregate files' => true]);

it('retains watch notification and lifecycle authority after configuring submission limits', function (): void {
    $history = [];
    $handler = HandlerStack::create(new MockHandler([new Response(200, [], '{"historyId":"100","expiration":"1893456000123"}')]));
    $handler->push(Middleware::history($history));
    $tokens = new TestGrantTokenProvider(['grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['sender@example.com'], 'token' => 'private-token']]);
    $context = new GrantContext('connection', 'grant', '1', 'gmail', 'sender@example.com');
    $limited = Mailbox::forGrant($context, $tokens, new Client(['handler' => $handler]))
        ->withSubmissionLimits(new SubmissionLimits(maxFileBytes: 4));
    expect($limited)->toBeInstanceOf(SupportsGmailWatch::class)->toBeInstanceOf(SupportsConversationRead::class);
    $configuration = new GmailWatchConfiguration('projects/project/topics/mail', 'projects/project/subscriptions/mail-push', 'https://host.example/gmail-push', 'push@project.iam.gserviceaccount.com', '123456789');
    $registration = $limited->watch($configuration);
    $request = new Request('POST', 'https://host.example/gmail-push', ['Content-Type' => 'application/json'], json_encode([
        'message' => ['messageId' => 'notification-1', 'data' => base64_encode('{"emailAddress":"sender@example.com","historyId":"110"}')],
        'subscription' => 'projects/project/subscriptions/mail-push',
    ], JSON_THROW_ON_ERROR));
    $verifier = new class implements GmailPushVerifier
    {
        public function verify(RequestInterface $request): array
        {
            return ['iss' => 'https://accounts.google.com', 'aud' => 'https://host.example/gmail-push', 'sub' => '123456789',
                'email' => 'push@project.iam.gserviceaccount.com', 'email_verified' => true, 'iat' => time() - 60, 'exp' => time() + 600];
        }
    };
    $hint = $limited->notification($request, $registration, $verifier);
    expect($hint->context)->toBe($context)->and($hint->historyId)->toBe('110');
    $file = new PreparedFile('host-1', 'file.bin', 'application/octet-stream', 5, static fn () => Utils::streamFor('bytes'), hash('sha256', 'bytes'));
    expect(fn () => $limited->submit(fileSubmissionMessage([$file])))->toThrow(UnsupportedCapabilityException::class);
    $tokens->grants['grant']['generation'] = '2';
    expect(fn () => $limited->notification($request, $registration, $verifier))->toThrow(GrantAccessDeniedException::class);
    expect(fn () => $limited->watch($configuration))->toThrow(GrantAccessDeniedException::class);
    expect($history)->toHaveCount(1);
});

it('bounds a buffering HTTP handler before it can materialize the whole Gmail JSON body', function (): void {
    $received = false;
    $attempts = 0;
    $body = null;
    $http = new Client(['handler' => static function (RequestInterface $request) use (&$received, &$attempts, &$body) {
        $attempts++;
        $body = $request->getBody();
        $buffer = (string) $body;
        $received = $buffer !== '';

        return Create::promiseFor(new Response(200, [], '{"id":"accepted-file-message"}'));
    }]);
    $history = [];
    $mailbox = fileSubmissionMailbox([], $history, $http)->withSubmissionLimits(new SubmissionLimits(maxMemoryBytes: 2_097_152));
    $bytes = str_repeat('x', 1_048_576);
    $file = new PreparedFile('host-1', 'bounded.bin', 'application/octet-stream', strlen($bytes), static fn () => Utils::streamFor($bytes), hash('sha256', $bytes));

    $result = $mailbox->submit(fileSubmissionMessage([$file]), maxRequestBytes: 4_194_304);

    expect($result->outcome)->toBe(SubmissionOutcome::Uncertain)
        ->and($result->status)->toBeNull()
        ->and($received)->toBeFalse()
        ->and($attempts)->toBe(1)
        ->and($body->getSize())->toBeNull();
});

it('closes owned submission resources after acceptance or uncertainty and preserves host storage', function (bool $timeout): void {
    $path = tempnam(sys_get_temp_dir(), 'mailbox-host-');
    file_put_contents($path, 'host-original');
    $source = $body = null;
    $attempts = 0;
    $http = new Client(['handler' => static function (RequestInterface $request) use ($timeout, &$body, &$attempts) {
        $attempts++;
        $body = $request->getBody();
        if ($timeout) {
            throw new ConnectException('private-network-secret', new Request('POST', 'https://private-upload-secret'));
        }

        return Create::promiseFor(new Response(200, [], '{"id":"accepted-file-message"}'));
    }]);
    $file = new PreparedFile('host-1', 'host.bin', 'application/octet-stream', 13, static function () use ($path, &$source) {
        $source = Utils::streamFor(fopen($path, 'rb'));

        return $source;
    }, hash('sha256', 'host-original'));
    $history = [];
    $mailbox = fileSubmissionMailbox([], $history, $http);
    try {
        $result = $mailbox->submit(fileSubmissionMessage([$file]));

        expect($result->outcome)->toBe($timeout ? SubmissionOutcome::Uncertain : SubmissionOutcome::Accepted)
            ->and($attempts)->toBe(1)
            ->and($source->isReadable())->toBeFalse()
            ->and($body->isReadable())->toBeFalse()
            ->and($body->getSize())->toBeNull()
            ->and(file_get_contents($path))->toBe('host-original');
        expect(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('private-network-secret');
        expect(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('private-upload-secret');
    } finally {
        unlink($path);
    }
})->with(['accepted' => false, 'lost acknowledgement' => true]);

it('keeps source content and its fingerprint out of ordinary prepared file debug and JSON', function (): void {
    $bytes = 'private-file-content';
    $fingerprint = hash('sha256', $bytes);
    $file = new PreparedFile('host-1', 'safe-name.bin', 'application/octet-stream', strlen($bytes), static fn () => Utils::streamFor($bytes), $fingerprint);

    foreach ([json_encode($file, JSON_THROW_ON_ERROR), print_r($file, true)] as $representation) {
        expect($representation)->not->toContain($bytes);
        expect($representation)->not->toContain($fingerprint);
    }
});

it('retains every selected same-name attachment without inferring inline content from HTML', function (): void {
    $history = [];
    $mailbox = fileSubmissionMailbox([new Response(200, [], '{"id":"accepted-file-message"}')], $history);
    $files = [
        new PreparedFile('first', 'same-name', 'application/octet-stream', 3, static fn () => Utils::streamFor('one'), hash('sha256', 'one')),
        new PreparedFile('second', 'same-name', 'application/octet-stream', 3, static fn () => Utils::streamFor('two'), hash('sha256', 'two')),
    ];
    $mailbox->submit(fileSubmissionMessage($files, '<p><img src="cid:same-name"></p>'));

    $payload = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $parsed = (new MailMimeParser)->parse(base64_decode(strtr($payload['raw'], '-_', '+/'), true), false);
    $parts = $parsed->getAllAttachmentParts();
    expect($parsed->getHtmlContent())->toBe('<p><img src="cid:same-name"></p>')
        ->and($parts)->toHaveCount(2)
        ->and($parts[0]->getContent())->toBe('one')
        ->and($parts[1]->getContent())->toBe('two')
        ->and($parts[0]->getContentDisposition())->toBe('attachment')
        ->and($parts[1]->getContentDisposition())->toBe('attachment')
        ->and($parts[0]->getContentId())->toBeNull()
        ->and($parts[1]->getContentId())->toBeNull();
});

it('rejects unsafe prepared file metadata before any source can open', function (array $changes): void {
    $opened = false;
    $arguments = array_replace([
        'identity' => 'host-1', 'name' => 'file.bin', 'mediaType' => 'application/octet-stream', 'byteLength' => 5,
        'source' => static function () use (&$opened) {
            $opened = true;

            return Utils::streamFor('bytes');
        }, 'sha256' => hash('sha256', 'bytes'),
    ], $changes);

    expect(fn () => new PreparedFile(...$arguments))->toThrow(InvalidArgumentException::class)
        ->and($opened)->toBeFalse();
})->with([
    'filename injection' => [['name' => "file.bin\r\nBcc: hidden@example.com"]],
    'type injection' => [['mediaType' => "application/octet-stream\r\nX-Hidden: yes"]],
    'Content-ID injection' => [['contentId' => "chosen@example.com\r\nX-Hidden: yes"]],
    'missing byte binding' => [['sha256' => '']],
]);

it('rejects a same-length changed source after reopening with the original persisted fingerprint', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'mailbox-host-');
    file_put_contents($path, 'first');
    $source = static fn () => Utils::streamFor(fopen($path, 'rb'));
    $file = new PreparedFile('stable-host-identity', 'stable-name.bin', 'application/octet-stream', 5, $source, hash('sha256', 'first'));
    try {
        $snapshot = $file->openVerified();
        expect((string) $snapshot)->toBe('first');
        $snapshot->close();
        file_put_contents($path, 'other');
        $resumed = new PreparedFile($file->identity, $file->name, $file->mediaType, $file->byteLength, $source, $file->fingerprint());
        $history = [];
        $mailbox = fileSubmissionMailbox([], $history);

        expect(fn () => $mailbox->submit(fileSubmissionMessage([$resumed])))->toThrow(MailboxException::class)
            ->and($history)->toBe([])
            ->and(file_get_contents($path))->toBe('other');
    } finally {
        unlink($path);
    }
});

it('submits an explicitly bound zero-byte file as an empty attachment', function (): void {
    $history = [];
    $mailbox = fileSubmissionMailbox([new Response(200, [], '{"id":"accepted-empty-file"}')], $history);
    $file = new PreparedFile('empty', 'empty.bin', 'application/octet-stream', 0, static fn () => Utils::streamFor(''), hash('sha256', ''));

    $result = $mailbox->submit(fileSubmissionMessage([$file]));

    $payload = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $parsed = (new MailMimeParser)->parse(base64_decode(strtr($payload['raw'], '-_', '+/'), true), false);
    $part = $parsed->getAttachmentPart(0);
    $sections = explode("\r\n\r\n", (string) $part->getStream(), 2);
    expect($result->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($parsed->getAllAttachmentParts())->toHaveCount(1)
        ->and($part->getFilename())->toBe('empty.bin')
        ->and($part->getContentTransferEncoding())->toBe('base64')
        ->and($sections)->toHaveCount(2)
        ->and(base64_decode($sections[1], true))->toBe('');
});

it('rejects a no-progress source that has not reached EOF rather than treating it as empty content', function (): void {
    $history = [];
    $mailbox = fileSubmissionMailbox([], $history);
    $file = new PreparedFile('empty', 'empty.bin', 'application/octet-stream', 0, static fn () => FnStream::decorate(Utils::streamFor(''), [
        'read' => static fn (int $length): string => '', 'eof' => static fn (): bool => false,
    ]), hash('sha256', ''));

    expect(fn () => $mailbox->submit(fileSubmissionMessage([$file])))->toThrow(MailboxException::class)
        ->and($history)->toBe([]);
});

it('rejects invalid source lengths fingerprints and interrupted reads before any send', function (int $length, string $fingerprint, bool $interrupted): void {
    $closed = false;
    $source = static function () use (&$closed, $interrupted) {
        $stream = Utils::streamFor('bytes');

        return FnStream::decorate($stream, [
            'read' => static function (int $bytes) use ($interrupted, $stream): string {
                if ($interrupted) {
                    throw new RuntimeException('private-host-path private-file-secret');
                }

                return $stream->read($bytes);
            },
            'close' => static function () use (&$closed, $stream): void {
                $closed = true;
                $stream->close();
            },
        ]);
    };
    $file = new PreparedFile('host-1', 'source.bin', 'application/octet-stream', $length, $source, $fingerprint);
    $history = [];
    $mailbox = fileSubmissionMailbox([], $history);

    try {
        $mailbox->submit(fileSubmissionMessage([$file]));
        test()->fail('Invalid or interrupted file bytes must not be sent.');
    } catch (MailboxException $exception) {
        expect($exception->getMessage())->not->toContain('private-host-path');
        expect($exception->getMessage())->not->toContain('private-file-secret');
        expect($exception->getPrevious())->toBeNull();
    }
    expect($history)->toBe([])->and($closed)->toBeTrue();
})->with([
    'longer than declared' => [4, hash('sha256', 'bytes'), false],
    'shorter than declared' => [6, hash('sha256', 'bytes'), false],
    'changed content fingerprint' => [5, hash('sha256', 'other'), false],
    'read interruption' => [5, hash('sha256', 'bytes'), true],
]);

/** @param list<PreparedFile> $files */
function fileSubmissionMessage(array $files, string $htmlBody = '<p>Chosen HTML <img src="cid:image-1@example.com"></p>'): PreparedMessage
{
    return new PreparedMessage(
        sender: new EmailAddressDto('Sender', 'sender@example.com'),
        to: [new EmailAddressDto('To', 'to@example.com')],
        cc: [new EmailAddressDto('Copy', 'cc@example.com')],
        bcc: [new EmailAddressDto('Private', 'bcc@example.com')],
        subject: 'Selected files',
        correlationId: 'files-operation-1',
        textBody: 'Chosen text',
        htmlBody: $htmlBody,
        files: $files,
    );
}

/**
 * @param  list<mixed>  $responses
 * @param  array<int, mixed>  $history
 */
function fileSubmissionMailbox(array $responses, array &$history, ?Client $http = null): SupportsMessageSubmission
{
    $handler = HandlerStack::create(new MockHandler($responses));
    // Retain only these small fixture requests after the SDK closes its owned wire spool.
    $handler->push(Middleware::mapRequest(static fn (RequestInterface $request): RequestInterface => $request->withBody(Utils::streamFor((string) $request->getBody()))));
    $handler->push(Middleware::history($history));
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['sender@example.com'], 'token' => 'private-token'],
    ]);

    return Mailbox::forGrant(
        new GrantContext('connection', 'grant', '1', 'gmail', 'sender@example.com'),
        $tokens,
        $http ?? new Client(['handler' => $handler]),
    );
}

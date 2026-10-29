<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Pyle\Mailbox\Contracts\SupportsMessageSubmission;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\PreparedFile;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\Enums\SubmissionOutcome;
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
 * @param list<mixed> $responses
 * @param array<int, mixed> $history
 */
function fileSubmissionMailbox(array $responses, array &$history): SupportsMessageSubmission
{
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::history($history));
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['sender@example.com'], 'token' => 'private-token'],
    ]);

    return Mailbox::forGrant(
        new GrantContext('connection', 'grant', '1', 'gmail', 'sender@example.com'),
        $tokens,
        new Client(['handler' => $handler]),
    );
}

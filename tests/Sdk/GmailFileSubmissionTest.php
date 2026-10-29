<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Pyle\Mailbox\Contracts\SupportsMessageSubmission;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\PreparedFile;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\Enums\SubmissionOutcome;
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

/** @param list<PreparedFile> $files */
function fileSubmissionMessage(array $files): PreparedMessage
{
    return new PreparedMessage(
        sender: new EmailAddressDto('Sender', 'sender@example.com'),
        to: [new EmailAddressDto('To', 'to@example.com')],
        cc: [new EmailAddressDto('Copy', 'cc@example.com')],
        bcc: [new EmailAddressDto('Private', 'bcc@example.com')],
        subject: 'Selected files',
        correlationId: 'files-operation-1',
        textBody: 'Chosen text',
        htmlBody: '<p>Chosen HTML <img src="cid:image-1@example.com"></p>',
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

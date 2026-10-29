<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\PreparedFile;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\SubmissionLimits;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\Part\Multipart\RelatedPart;
use Symfony\Component\Mime\Part\TextPart;
use Throwable;

final class PreparedMessageMime
{
    public static function encode(PreparedMessage $message, int $maxBytes = 1_048_576, ?SubmissionLimits $limits = null): string
    {
        $limits ??= new SubmissionLimits;
        $budget = new ResponseBudget($maxBytes, $limits->maxMemoryBytes);
        self::preflight($message, $maxBytes, $limits, $budget);
        $mime = '';
        foreach (self::chunks($message, $maxBytes, $budget) as $chunk) {
            $budget->assertMemoryAvailable(strlen($mime) + strlen($chunk) * 2 + 65_536);
            $mime .= $chunk;
        }

        return $mime;
    }

    public static function gmailPayload(PreparedMessage $message, int $maxRequestBytes, ?SubmissionLimits $limits = null, ?string $threadId = null, ?ResponseBudget $budget = null): StreamInterface
    {
        $limits ??= new SubmissionLimits;
        $budget ??= new ResponseBudget($maxRequestBytes, $limits->maxMemoryBytes);
        if ($budget->maxBytes !== $maxRequestBytes || $budget->maxMemoryBytes !== $limits->maxMemoryBytes
            || ($threadId !== null && preg_match('/\A[A-Za-z0-9_-]{1,512}\z/', $threadId) !== 1)) {
            throw new InvalidArgumentException('The Gmail payload requires matching wire and memory budgets and a valid optional thread ID.');
        }
        $payload = null;
        try {
            self::preflight($message, $maxRequestBytes, $limits, $budget);
            $payload = self::temporaryFile();
            self::write($payload, '{"raw":"', $budget);
            $tail = '';
            foreach (self::chunks($message, min($maxRequestBytes, 36_700_160), $budget) as $chunk) {
                $budget->assertMemoryAvailable(strlen($chunk) * 4 + 65_536);
                $chunk = $tail.$chunk;
                $length = strlen($chunk) - strlen($chunk) % 3;
                $tail = substr($chunk, $length);
                self::write($payload, strtr(base64_encode(substr($chunk, 0, $length)), '+/', '-_'), $budget);
            }
            self::write($payload, rtrim(strtr(base64_encode($tail), '+/', '-_'), '=').'"', $budget);
            if ($threadId !== null) {
                self::write($payload, ',"threadId":'.json_encode($threadId, JSON_THROW_ON_ERROR), $budget);
            }
            self::write($payload, '}', $budget);
            rewind($payload);

            return new SubmissionBody(Utils::streamFor($payload), $budget);
        } catch (Throwable $exception) {
            if (is_resource($payload)) {
                fclose($payload);
            }
            if ($exception instanceof ResponseLimitExceededException) {
                throw new UnsupportedCapabilityException('The Gmail request exceeds its wire or memory allocation budget.');
            }
            throw $exception;
        }
    }

    private static function preflight(PreparedMessage $message, int $maxBytes, SubmissionLimits $limits, ResponseBudget $budget): void
    {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('The submission byte budget must be positive.');
        }
        $remainingFileBytes = $limits->maxTotalFileBytes;
        $metadataBytes = strlen($message->subject) + strlen($message->correlationId)
            + self::metadataBytes($message->headers, [$message->to, $message->cc, $message->bcc, [$message->sender]], $message->files);
        if ($metadataBytes > intdiv(PHP_INT_MAX - 262_144, 8)) {
            throw new UnsupportedCapabilityException('The prepared metadata exceeds its allocation budget.');
        }
        // shortcut: allocation reservations target measured 64-bit PHP 8.4; remeasure other runtime heap guarantees.
        $budget->assertMemoryAvailable($metadataBytes * 8 + 262_144);
        self::validate($message);
        $inputBytes = strlen($message->textBody ?? '') + strlen($message->htmlBody ?? '');
        foreach ($message->files as $file) {
            if ($file->byteLength > min($limits->maxFileBytes, $remainingFileBytes)) {
                throw new UnsupportedCapabilityException('The prepared files exceed the decoded file byte budget.');
            }
            $remainingFileBytes -= $file->byteLength;
            if ($file->byteLength > $maxBytes - $inputBytes) {
                throw new UnsupportedCapabilityException('The prepared content exceeds the submission byte budget.');
            }
            $inputBytes += $file->byteLength;
            if ($file->contentId !== null) {
                try {
                    new Address($file->contentId);
                } catch (ExceptionInterface) {
                    throw new UnsupportedCapabilityException('Gmail MIME requires a Content-ID in addr-spec form.');
                }
            }
        }
        if ($inputBytes > $maxBytes) {
            throw new UnsupportedCapabilityException('The prepared content exceeds the submission byte budget.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @param  list<array<array-key, mixed>>  $addressLists
     * @param  array<array-key, mixed>  $files
     */
    private static function metadataBytes(array $headers, array $addressLists, array $files): int
    {
        $metadataBytes = 0;
        foreach ($headers as $name => $value) {
            $metadataBytes += (is_string($name) ? strlen($name) : 0) + (is_string($value) ? strlen($value) : 0);
        }
        foreach ($addressLists as $addresses) {
            foreach ($addresses as $address) {
                if ($address instanceof EmailAddressDto) {
                    $metadataBytes += strlen($address->name) + strlen($address->address) + 4096;
                }
            }
        }
        foreach ($files as $file) {
            if ($file instanceof PreparedFile) {
                $metadataBytes += strlen($file->name) + strlen($file->mediaType) + strlen($file->identity) + strlen($file->contentId ?? '') + 4096;
            }
        }

        return $metadataBytes;
    }

    /** @return iterable<string> */
    private static function chunks(PreparedMessage $message, int $maxBytes, ResponseBudget $budget): iterable
    {
        $snapshots = [];
        try {
            $email = (new Email)
                ->from(self::address($message->sender))
                ->to(...array_map(self::address(...), $message->to))
                ->cc(...array_map(self::address(...), $message->cc))
                ->bcc(...array_map(self::address(...), $message->bcc))
                ->subject($message->subject);
            $body = null;
            if ($message->textBody !== null) {
                $snapshot = self::temporaryFile();
                $snapshots[] = $snapshot;
                self::write($snapshot, $message->textBody);
                $body = new TextPart($snapshot, 'utf-8', 'plain', 'base64');
            }
            if ($message->htmlBody !== null) {
                $snapshot = self::temporaryFile();
                $snapshots[] = $snapshot;
                self::write($snapshot, $message->htmlBody);
                $html = new TextPart($snapshot, 'utf-8', 'html', 'base64');
                $body = $body === null ? $html : new AlternativePart($body, $html);
            }
            if ($body === null) {
                throw new InvalidArgumentException('The prepared message requires text or HTML content.');
            }
            foreach ($message->headers as $name => $value) {
                if (in_array(strtolower($name), ['message-id', 'in-reply-to', 'references'], true)) {
                    $email->getHeaders()->addIdHeader($name, MessageIdentifiers::parse($value));
                } else {
                    $email->getHeaders()->addTextHeader($name, $value);
                }
            }
            $email->getHeaders()->addTextHeader('X-Mailbox-Correlation-Id', $message->correlationId);
            $inlineParts = $attachments = [];
            foreach ($message->files as $file) {
                $budget->assertMemoryAvailable(262_144);
                $snapshot = $file->openVerified()->detach();
                if (! is_resource($snapshot)) {
                    throw new MailboxException('The verified prepared file snapshot is unavailable.');
                }
                $snapshots[] = $snapshot;
                $part = new DataPart($snapshot, $file->name, $file->mediaType);
                if ($file->inline) {
                    $part->asInline();
                }
                if ($file->contentId !== null) {
                    $part->setContentId($file->contentId);
                }
                if ($file->inline && $file->contentId !== null) {
                    $inlineParts[] = $part;
                } else {
                    $attachments[] = $part;
                }
            }
            if ($inlineParts !== []) {
                $body = new RelatedPart($body, ...$inlineParts);
            }
            if ($attachments !== []) {
                $body = new MixedPart($body, ...$attachments);
            }
            $email->setBody($body);
            $email->ensureValidity();
            $headers = $email->getPreparedHeaders();
            // Gmail routes recipients from MIME headers; Symfony removes Bcc for envelope-based transports.
            if ($message->bcc !== []) {
                $headers->addMailboxListHeader('Bcc', array_map(self::address(...), $message->bcc));
            }

            $header = $headers->toString();
            $mimeBytes = strlen($header);
            if ($mimeBytes > $maxBytes) {
                throw new UnsupportedCapabilityException('The encoded MIME exceeds its byte budget.');
            }
            yield $header;
            foreach ($email->getBody()->toIterable() as $chunk) {
                if (strlen($chunk) > $maxBytes - $mimeBytes) {
                    throw new UnsupportedCapabilityException('The encoded MIME exceeds the submission byte budget.');
                }
                $mimeBytes += strlen($chunk);
                yield $chunk;
            }
        } catch (ExceptionInterface) {
            throw new InvalidArgumentException('The prepared message contains an invalid or unsupported MIME field.');
        } finally {
            foreach ($snapshots as $snapshot) {
                if (is_resource($snapshot)) {
                    fclose($snapshot);
                }
            }
        }
    }

    /** @return resource */
    private static function temporaryFile(): mixed
    {
        $stream = @tmpfile();
        if ($stream === false) {
            throw new MailboxException('Private submission spooling is unavailable.');
        }

        return $stream;
    }

    /** @param resource $stream */
    private static function write(mixed $stream, string $bytes, ?ResponseBudget $budget = null): void
    {
        $budget?->consume(strlen($bytes));
        if ($bytes !== '' && @fwrite($stream, $bytes) !== strlen($bytes)) {
            throw new MailboxException('The private submission spool could not be written.');
        }
    }

    private static function address(EmailAddressDto $address): Address
    {
        return new Address($address->address, $address->name);
    }

    public static function validate(PreparedMessage $message): void
    {
        self::headerText($message->subject);
        self::headerText($message->correlationId);
        if (trim($message->correlationId) === '' || strlen($message->correlationId) > 512) {
            throw new InvalidArgumentException('A nonempty caller correlation of at most 512 bytes is required.');
        }
        if ($message->to === [] && $message->cc === [] && $message->bcc === []) {
            throw new InvalidArgumentException('The prepared message requires explicit recipients.');
        }
        foreach ([$message->to, $message->cc, $message->bcc, [$message->sender]] as $addresses) {
            self::validateAddresses($addresses);
        }
        self::validateHeaders($message->headers);
        if ($message->textBody === null && $message->htmlBody === null) {
            throw new InvalidArgumentException('The prepared message requires text or HTML content.');
        }
        foreach ([$message->textBody, $message->htmlBody] as $body) {
            if ($body !== null && ! mb_check_encoding($body, 'UTF-8')) {
                throw new InvalidArgumentException('Prepared text and HTML must be valid UTF-8.');
            }
        }
        self::validateFiles($message->files);
    }

    /** @param array<array-key, mixed> $files */
    private static function validateFiles(array $files): void
    {
        if (! array_is_list($files)) {
            throw new InvalidArgumentException('Prepared files must be a list.');
        }
        $identities = $contentIds = [];
        foreach ($files as $file) {
            if (! $file instanceof PreparedFile) {
                throw new UnsupportedCapabilityException('Prepared files require structured immutable file sources.');
            }
            if (isset($identities[$file->identity]) || ($file->contentId !== null && isset($contentIds[$file->contentId]))) {
                throw new InvalidArgumentException('Prepared file identities and supplied Content-IDs must be unique.');
            }
            $identities[$file->identity] = true;
            if ($file->contentId !== null) {
                $contentIds[$file->contentId] = true;
            }
        }
    }

    /** @param array<array-key, mixed> $addresses */
    private static function validateAddresses(array $addresses): void
    {
        if (! array_is_list($addresses)) {
            throw new InvalidArgumentException('Prepared recipients must be address lists.');
        }
        foreach ($addresses as $address) {
            if (! $address instanceof EmailAddressDto) {
                throw new InvalidArgumentException('Prepared recipients must be structured email addresses.');
            }
            self::headerText($address->name);
            self::headerText($address->address);
            if ($address->address !== trim($address->address)) {
                throw new InvalidArgumentException('Prepared email addresses must not contain surrounding whitespace.');
            }
            try {
                self::address($address);
            } catch (ExceptionInterface) {
                throw new InvalidArgumentException('The prepared message contains an invalid email address.');
            }
        }
    }

    /** @param array<array-key, mixed> $headers */
    private static function validateHeaders(array $headers): void
    {
        $names = [];
        foreach ($headers as $name => $value) {
            if (! is_string($name) || ! is_string($value) || preg_match('/\A[A-Za-z][A-Za-z0-9-]*\z/', $name) !== 1) {
                throw new InvalidArgumentException('Prepared headers require supported field names and text values.');
            }
            $normalized = strtolower($name);
            if (in_array($normalized, ['from', 'sender', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date', 'return-path', 'mime-version', 'dkim-signature', 'x-mailbox-correlation-id'], true)
                || str_starts_with($normalized, 'content-') || str_starts_with($normalized, 'resent-') || in_array($normalized, $names, true)) {
                throw new InvalidArgumentException('Prepared headers cannot override structured message fields or MIME structure.');
            }
            self::headerText($value);
            if (in_array($normalized, ['message-id', 'in-reply-to', 'references'], true)) {
                $ids = MessageIdentifiers::parse($value);
                if ($normalized === 'message-id' && count($ids) !== 1) {
                    throw new InvalidArgumentException('A chosen Message-ID requires exactly one identifier.');
                }
            }
            $names[] = $normalized;
        }
    }

    private static function headerText(string $value): void
    {
        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArgumentException('Prepared header fields cannot contain control characters or invalid UTF-8.');
        }
    }
}

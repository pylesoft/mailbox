<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

use InvalidArgumentException;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\PreparedFile;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface;
use Symfony\Component\Mime\Part\DataPart;

final class PreparedMessageMime
{
    public static function encode(PreparedMessage $message, int $maxBytes = 1_048_576): string
    {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('The submission byte budget must be positive.');
        }
        self::validate($message);
        $inputBytes = strlen($message->textBody ?? '') + strlen($message->htmlBody ?? '') + strlen($message->subject) + strlen($message->correlationId);
        foreach ($message->headers as $name => $value) {
            $inputBytes += strlen($name) + strlen($value);
        }
        foreach ([$message->to, $message->cc, $message->bcc, [$message->sender]] as $addresses) {
            foreach ($addresses as $address) {
                $inputBytes += strlen($address->name) + strlen($address->address);
            }
        }
        foreach ($message->files as $file) {
            $inputBytes += $file->byteLength + strlen($file->name) + strlen($file->mediaType);
        }
        if ($inputBytes > $maxBytes) {
            throw new UnsupportedCapabilityException('The prepared content exceeds the submission byte budget.');
        }
        try {
            return self::serialize($message, $maxBytes);
        } catch (ExceptionInterface) {
            throw new InvalidArgumentException('The prepared message contains an invalid or unsupported MIME field.');
        }
    }

    private static function serialize(PreparedMessage $message, int $maxBytes): string
    {
        $email = (new Email)
            ->from(self::address($message->sender))
            ->to(...array_map(self::address(...), $message->to))
            ->cc(...array_map(self::address(...), $message->cc))
            ->bcc(...array_map(self::address(...), $message->bcc))
            ->subject($message->subject);
        if ($message->textBody !== null) {
            $email->text($message->textBody, 'utf-8');
        }
        if ($message->htmlBody !== null) {
            $email->html($message->htmlBody, 'utf-8');
        }
        foreach ($message->headers as $name => $value) {
            $email->getHeaders()->addTextHeader($name, $value);
        }
        $email->getHeaders()->addTextHeader('X-Mailbox-Correlation-Id', $message->correlationId);
        $snapshots = [];
        try {
            foreach ($message->files as $file) {
                $snapshot = $file->openVerified()->detach();
                $snapshots[] = $snapshot;
                $part = new DataPart($snapshot, $file->name, $file->mediaType);
                if ($file->inline) {
                    $part->asInline();
                }
                if ($file->contentId !== null) {
                    $part->setContentId($file->contentId);
                }
                $email->addPart($part);
            }
            $email->ensureValidity();
            $headers = $email->getPreparedHeaders();
            // Gmail routes recipients from MIME headers; Symfony removes Bcc for envelope-based transports.
            if ($message->bcc !== []) {
                $headers->addMailboxListHeader('Bcc', array_map(self::address(...), $message->bcc));
            }

            $mime = $headers->toString();
            foreach ($email->getBody()->toIterable() as $chunk) {
                if (strlen($mime) + strlen($chunk) > $maxBytes) {
                    throw new UnsupportedCapabilityException('The encoded MIME exceeds the submission byte budget.');
                }
                $mime .= $chunk;
            }

            return $mime;
        } finally {
            foreach ($snapshots as $snapshot) {
                if (is_resource($snapshot)) {
                    fclose($snapshot);
                }
            }
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
        if (! array_is_list($message->files)) {
            throw new InvalidArgumentException('Prepared files must be a list.');
        }
        foreach ($message->files as $file) {
            if (! $file instanceof PreparedFile) {
                throw new UnsupportedCapabilityException('Prepared files require structured immutable file sources.');
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
            if (in_array($normalized, ['from', 'sender', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date', 'message-id', 'return-path', 'mime-version', 'dkim-signature', 'x-mailbox-correlation-id'], true)
                || str_starts_with($normalized, 'content-') || str_starts_with($normalized, 'resent-') || in_array($normalized, $names, true)) {
                throw new InvalidArgumentException('Prepared headers cannot override structured message fields or MIME structure.');
            }
            self::headerText($value);
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

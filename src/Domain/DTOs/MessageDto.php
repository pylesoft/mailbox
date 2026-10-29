<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Pyle\Mailbox\DTOs\Concerns\ArraySerializable;
use Pyle\Mailbox\Enums\Importance;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Support\GmailMime;
use Pyle\Mailbox\Support\ReceivedHeaders;

/** @implements Arrayable<string, mixed> */
final readonly class MessageDto implements Arrayable, JsonSerializable
{
    use ArraySerializable;

    /**
     * @param  array<EmailAddressDto>  $toRecipients
     * @param  array<EmailAddressDto>  $ccRecipients
     * @param  array<EmailAddressDto>  $bccRecipients
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public string $subject,
        public ?string $bodyPreview,
        public ?BodyDto $body,
        public ?EmailAddressDto $from,
        public ?EmailAddressDto $sender,
        public array $toRecipients,
        public array $ccRecipients,
        public array $bccRecipients,
        public ?CarbonImmutable $receivedAt,
        public ?CarbonImmutable $sentAt,
        public bool $isRead,
        public bool $isDraft,
        public bool $hasAttachments,
        public Importance $importance,
        public ?string $conversationId,
        public ?string $internetMessageId,
        public ?string $parentFolderId,
        public array $raw = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromMsGraph(array $data): self
    {
        foreach (['from', 'sender'] as $name) {
            if (isset($data[$name]) && ! is_array($data[$name])) {
                throw new MailboxException('Malformed received Graph sender.');
            }
        }

        return new self(
            id: (string) ($data['id'] ?? ''),
            subject: (string) ($data['subject'] ?? ''),
            bodyPreview: isset($data['bodyPreview']) ? (string) $data['bodyPreview'] : null,
            body: isset($data['body']) && is_array($data['body']) ? BodyDto::fromMsGraph($data['body']) : null,
            from: isset($data['from']) && is_array($data['from']) ? EmailAddressDto::fromMsGraph($data['from']) : null,
            sender: isset($data['sender']) && is_array($data['sender']) ? EmailAddressDto::fromMsGraph($data['sender']) : null,
            toRecipients: self::mapAddresses($data['toRecipients'] ?? []),
            ccRecipients: self::mapAddresses($data['ccRecipients'] ?? []),
            bccRecipients: self::mapAddresses($data['bccRecipients'] ?? []),
            receivedAt: self::parseDate($data['receivedDateTime'] ?? null),
            sentAt: self::parseDate($data['sentDateTime'] ?? null),
            isRead: (bool) ($data['isRead'] ?? false),
            isDraft: (bool) ($data['isDraft'] ?? false),
            hasAttachments: (bool) ($data['hasAttachments'] ?? false),
            importance: Importance::tryFrom((string) ($data['importance'] ?? 'normal')) ?? Importance::NORMAL,
            conversationId: isset($data['conversationId']) ? (string) $data['conversationId'] : null,
            internetMessageId: isset($data['internetMessageId']) ? (string) $data['internetMessageId'] : null,
            parentFolderId: isset($data['parentFolderId']) ? (string) $data['parentFolderId'] : null,
            raw: $data,
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromGmail(array $data): self
    {
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $headers = $payload['headers'] ?? [];
        if (! is_array($headers) || ! array_is_list($headers)) {
            throw new MailboxException('Malformed received Gmail headers.');
        }
        foreach ($headers as $header) {
            if (! is_array($header) || ! is_string($header['name'] ?? null) || ! is_string($header['value'] ?? null)) {
                throw new MailboxException('Malformed received Gmail header.');
            }
        }
        $labelIds = array_values(array_filter((array) ($data['labelIds'] ?? []), 'is_string'));
        $subject = self::headerValue($headers, 'subject') ?? (string) ($data['subject'] ?? '');
        $fromValue = self::headerValue($headers, 'from');
        $senderValue = self::headerValue($headers, 'sender') ?? $fromValue;

        return new self(
            id: (string) ($data['id'] ?? ''),
            subject: ReceivedHeaders::text($subject),
            bodyPreview: isset($data['snippet']) ? (string) $data['snippet'] : null,
            body: GmailMime::body($payload),
            from: is_string($fromValue) ? EmailAddressDto::fromGmail($fromValue) : null,
            sender: is_string($senderValue) ? EmailAddressDto::fromGmail($senderValue) : null,
            toRecipients: self::parseAddressHeaders($headers, 'to'),
            ccRecipients: self::parseAddressHeaders($headers, 'cc'),
            bccRecipients: self::parseAddressHeaders($headers, 'bcc'),
            receivedAt: self::parseInternalDate($data['internalDate'] ?? null),
            sentAt: self::parseHeaderDate(self::headerValue($headers, 'date')),
            isRead: ! in_array('UNREAD', $labelIds, true),
            isDraft: in_array('DRAFT', $labelIds, true),
            hasAttachments: GmailMime::files($payload) !== [],
            importance: self::resolveImportance($headers),
            conversationId: isset($data['threadId']) ? (string) $data['threadId'] : null,
            internetMessageId: self::headerValue($headers, 'message-id'),
            parentFolderId: self::resolveParentFolder($labelIds),
            raw: $data,
        );
    }

    /** @return array<EmailAddressDto> */
    private static function mapAddresses(mixed $addresses): array
    {
        if (! is_array($addresses) || ! array_is_list($addresses)) {
            throw new MailboxException('Malformed received Graph recipient collection.');
        }

        return array_map(
            static fn (mixed $address): EmailAddressDto => EmailAddressDto::fromMsGraph(is_array($address) ? $address : []),
            $addresses,
        );
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    /** @param array<int, array<string, mixed>> $headers */
    private static function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (strtolower((string) ($header['name'] ?? '')) !== strtolower($name)) {
                continue;
            }

            return trim((string) ($header['value'] ?? ''));
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $headers
     * @return array<EmailAddressDto>
     */
    private static function parseAddressHeaders(array $headers, string $name): array
    {
        $addresses = [];
        foreach ($headers as $header) {
            if (strtolower((string) ($header['name'] ?? '')) === $name) {
                array_push($addresses, ...ReceivedHeaders::addresses((string) ($header['value'] ?? '')));
            }
        }

        return $addresses;
    }

    private static function parseInternalDate(mixed $value): ?CarbonImmutable
    {
        if (is_numeric($value)) {
            return CarbonImmutable::createFromTimestampUTC((int) floor(((float) $value) / 1000));
        }

        return null;
    }

    private static function parseHeaderDate(?string $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    /** @param array<int, array<string, mixed>> $headers */
    private static function resolveImportance(array $headers): Importance
    {
        $importance = strtolower((string) (self::headerValue($headers, 'importance') ?? ''));

        if ($importance === 'high') {
            return Importance::HIGH;
        }

        if ($importance === 'low') {
            return Importance::LOW;
        }

        $priority = strtolower((string) (self::headerValue($headers, 'x-priority') ?? ''));

        if (str_starts_with($priority, '1') || str_starts_with($priority, '2')) {
            return Importance::HIGH;
        }

        if (str_starts_with($priority, '5')) {
            return Importance::LOW;
        }

        return Importance::NORMAL;
    }

    /** @param array<int, string> $labelIds */
    private static function resolveParentFolder(array $labelIds): ?string
    {
        $priority = ['INBOX', 'SENT', 'DRAFT', 'TRASH', 'SPAM', 'ALL_MAIL'];

        foreach ($priority as $label) {
            if (in_array($label, $labelIds, true)) {
                return $label;
            }
        }

        return $labelIds[0] ?? null;
    }
}

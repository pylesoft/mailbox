<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Pyle\Mailbox\DTOs\Concerns\ArraySerializable;
use Pyle\Mailbox\Enums\Importance;

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
        return new self(
            id: (string) ($data['id'] ?? ''),
            subject: (string) ($data['subject'] ?? ''),
            bodyPreview: $data['snippet'] ?? null,
            body: null,
            from: null,
            sender: null,
            toRecipients: [],
            ccRecipients: [],
            bccRecipients: [],
            receivedAt: null,
            sentAt: null,
            isRead: true,
            isDraft: false,
            hasAttachments: false,
            importance: Importance::NORMAL,
            conversationId: $data['threadId'] ?? null,
            internetMessageId: null,
            parentFolderId: null,
            raw: $data,
        );
    }

    /** @param array<int, mixed> $addresses
     * @return array<EmailAddressDto>
     */
    private static function mapAddresses(array $addresses): array
    {
        return array_values(array_map(
            static fn (mixed $address): EmailAddressDto => EmailAddressDto::fromMsGraph(is_array($address) ? $address : []),
            $addresses,
        ));
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}

<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Pyle\Mailbox\DTOs\Concerns\ArraySerializable;

/** @implements Arrayable<string, mixed> */
final readonly class AttachmentDto implements Arrayable, JsonSerializable
{
    use ArraySerializable;

    public function __construct(
        public string $id,
        public string $name,
        public string $contentType,
        public int $size,
        public bool $isInline,
        public ?string $contentId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromMsGraph(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            contentType: (string) ($data['contentType'] ?? 'application/octet-stream'),
            size: (int) ($data['size'] ?? 0),
            isInline: (bool) ($data['isInline'] ?? false),
            contentId: isset($data['contentId']) ? (string) $data['contentId'] : null,
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromGmail(array $data): self
    {
        $body = is_array($data['body'] ?? null) ? $data['body'] : [];
        $headers = is_array($data['headers'] ?? null) ? $data['headers'] : [];

        return new self(
            id: (string) ($body['attachmentId'] ?? $data['attachmentId'] ?? $data['id'] ?? ''),
            name: (string) ($data['filename'] ?? $data['name'] ?? ''),
            contentType: (string) ($data['mimeType'] ?? $data['contentType'] ?? 'application/octet-stream'),
            size: (int) ($body['size'] ?? $data['size'] ?? 0),
            isInline: self::isInline($headers),
            contentId: self::contentId($headers),
        );
    }

    /** @param array<int, mixed> $headers */
    private static function isInline(array $headers): bool
    {
        foreach ($headers as $header) {
            if (! is_array($header)) {
                continue;
            }

            $name = strtolower((string) ($header['name'] ?? ''));
            if ($name !== 'content-disposition') {
                continue;
            }

            $value = strtolower((string) ($header['value'] ?? ''));

            return str_contains($value, 'inline');
        }

        return false;
    }

    /** @param array<int, mixed> $headers */
    private static function contentId(array $headers): ?string
    {
        foreach ($headers as $header) {
            if (! is_array($header)) {
                continue;
            }

            if (strtolower((string) ($header['name'] ?? '')) !== 'content-id') {
                continue;
            }

            $value = trim((string) ($header['value'] ?? ''));

            return $value !== '' ? $value : null;
        }

        return null;
    }
}

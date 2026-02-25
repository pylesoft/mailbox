<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Pyle\Mailbox\DTOs\Concerns\ArraySerializable;

/** @implements Arrayable<string, mixed> */
final readonly class BodyDto implements Arrayable, JsonSerializable
{
    use ArraySerializable;

    public function __construct(
        public string $contentType,
        public string $content,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromMsGraph(array $data): self
    {
        return new self(
            contentType: strtolower((string) ($data['contentType'] ?? 'text')),
            content: (string) ($data['content'] ?? ''),
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromGmail(array $data): self
    {
        $mimeType = strtolower((string) ($data['mimeType'] ?? 'text/plain'));
        $normalizedContentType = str_contains($mimeType, 'html') ? 'html' : 'text';
        $body = is_array($data['body'] ?? null) ? $data['body'] : [];
        $content = self::decodeBase64Url((string) ($body['data'] ?? ''));

        return new self(
            contentType: $normalizedContentType,
            content: $content,
        );
    }

    private static function decodeBase64Url(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }

        $normalized = strtr($encoded, '-_', '+/');
        $padding = strlen($normalized) % 4;

        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($normalized, true) ?: '';
    }
}

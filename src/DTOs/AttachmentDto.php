<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Pyle\Mailbox\DTOs\Concerns\ArraySerializable;

/** @implements Arrayable<string, mixed> */
final readonly class AttachmentDto implements JsonSerializable, Arrayable
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
}

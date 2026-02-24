<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Pyle\Mailbox\DTOs\Concerns\ArraySerializable;

/** @implements Arrayable<string, mixed> */
final readonly class BodyDto implements JsonSerializable, Arrayable
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
}

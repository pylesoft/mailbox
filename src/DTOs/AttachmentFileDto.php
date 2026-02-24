<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Pyle\Mailbox\DTOs\Concerns\ArraySerializable;

/** @implements Arrayable<string, mixed> */
final readonly class AttachmentFileDto implements JsonSerializable, Arrayable
{
    use ArraySerializable;

    public function __construct(
        public string $id,
        public string $name,
        public string $contentType,
        public int $size,
        public bool $isInline,
        public ?string $contentId,
        public string $path,
        public string $disk,
        public bool $alreadyExisted,
    ) {}
}

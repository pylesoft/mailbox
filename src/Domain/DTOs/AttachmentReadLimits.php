<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use InvalidArgumentException;

final readonly class AttachmentReadLimits
{
    public function __construct(
        public int $maxResponseBytes = 67108864,
        public int $maxFileBytes = 33554432,
        public int $maxMemoryBytes = 134217728,
    ) {
        foreach ([$maxResponseBytes, $maxFileBytes, $maxMemoryBytes] as $limit) {
            if ($limit < 1) {
                throw new InvalidArgumentException('Attachment read limits must be positive.');
            }
        }
    }
}

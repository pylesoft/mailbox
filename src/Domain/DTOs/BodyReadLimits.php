<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use InvalidArgumentException;

final readonly class BodyReadLimits
{
    public function __construct(
        public int $maxResponseBytes = 8388608,
        public int $maxBodyBytes = 4194304,
        public int $maxHydrations = 32,
        public int $maxParts = 256,
    ) {
        foreach ([$maxResponseBytes, $maxBodyBytes, $maxHydrations, $maxParts] as $limit) {
            if ($limit < 1) {
                throw new InvalidArgumentException('Body read limits must be positive.');
            }
        }
    }
}

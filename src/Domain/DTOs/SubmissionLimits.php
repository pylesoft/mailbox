<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use InvalidArgumentException;

final readonly class SubmissionLimits
{
    public function __construct(
        public int $maxFileBytes = PHP_INT_MAX,
        public int $maxTotalFileBytes = PHP_INT_MAX,
        public int $maxMemoryBytes = 8_388_608,
    ) {
        if ($maxFileBytes < 1 || $maxTotalFileBytes < 1 || $maxMemoryBytes < 1) {
            throw new InvalidArgumentException('Submission file and memory limits must be positive.');
        }
    }
}

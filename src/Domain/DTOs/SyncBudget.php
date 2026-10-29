<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use InvalidArgumentException;

final readonly class SyncBudget
{
    public function __construct(
        public int $maxPages = 1,
        public int $maxItems = 100,
        public int $maxRequests = 10,
        public int $maxResponseBytes = 8388608,
    ) {
        foreach ([$maxPages, $maxItems, $maxRequests, $maxResponseBytes] as $limit) {
            if ($limit < 1) {
                throw new InvalidArgumentException('Synchronization budgets must be positive.');
            }
        }
    }
}

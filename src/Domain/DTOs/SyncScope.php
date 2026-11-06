<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SyncScope
{
    public function __construct(
        public ?string $folderId = null,
        public ?DateTimeImmutable $importSince = null,
    ) {
        if ($folderId !== null && trim($folderId) === '') {
            throw new InvalidArgumentException('A synchronization folder must be non-empty or null for the whole mailbox.');
        }

        if ($importSince !== null && $importSince->format('u') !== '000000') {
            throw new InvalidArgumentException('Synchronization cutoffs require whole-second precision.');
        }
    }
}

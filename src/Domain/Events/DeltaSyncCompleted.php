<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Events;

final readonly class DeltaSyncCompleted
{
    public function __construct(
        public string $driver,
        public string $mailbox,
        public string $folder,
        public int $created,
        public int $updated,
        public int $deleted,
    ) {}
}

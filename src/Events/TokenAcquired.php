<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Events;

final readonly class TokenAcquired
{
    public function __construct(
        public string $driver,
        public int $expiresIn,
    ) {}
}

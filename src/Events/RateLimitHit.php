<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Events;

final readonly class RateLimitHit
{
    public function __construct(
        public string $driver,
        public string $mailbox,
        public int $retryAfter,
        public string $endpoint,
    ) {}
}

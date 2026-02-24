<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Events;

use Carbon\CarbonImmutable;

final readonly class SecretExpirationWarning
{
    public function __construct(
        public string $driver,
        public CarbonImmutable $expiresAt,
        public int $daysRemaining,
    ) {}
}

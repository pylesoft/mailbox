<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Events;

final readonly class TokenRefreshFailed
{
    public function __construct(
        public string $driver,
        public string $error,
        public ?string $guidance,
    ) {}
}

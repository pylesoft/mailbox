<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Events;

final readonly class ApiError
{
    public function __construct(
        public string $driver,
        public string $mailbox,
        public int $status,
        public string $error,
        public string $endpoint,
    ) {}
}

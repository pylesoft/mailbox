<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Pyle\Mailbox\DTOs\Concerns\ArraySerializable;

/** @implements Arrayable<string, mixed> */
final readonly class ConnectionTestResult implements Arrayable, JsonSerializable
{
    use ArraySerializable;

    /** @param array<string> $accessibleMailboxes */
    public function __construct(
        public bool $success,
        public ?string $error,
        public ?int $latencyMs,
        public ?string $authenticatedAs,
        public array $accessibleMailboxes = [],
    ) {}
}

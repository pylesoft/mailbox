<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Pyle\Mailbox\DTOs\Concerns\ArraySerializable;

/** @implements Arrayable<string, mixed> */
final readonly class HealthCheckResult implements Arrayable, JsonSerializable
{
    use ArraySerializable;

    public function __construct(
        public bool $healthy,
        public bool $tokenValid,
        public ?int $tokenExpiresIn,
        public bool $apiReachable,
        public ?int $latencyMs,
        public ?CarbonImmutable $secretExpiresAt,
        public bool $secretExpirationWarning,
    ) {}
}

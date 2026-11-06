<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
final readonly class MessageQueryRejection implements Arrayable, JsonSerializable
{
    public function __construct(
        #[\SensitiveParameter] public string $providerMessageId,
        public string $reason = 'invalid_received_header',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['reason' => $this->reason];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}

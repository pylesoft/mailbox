<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
final readonly class MessageQueryResult implements Arrayable, JsonSerializable
{
    /**
     * @param  Collection<int, MessageDto>  $messages
     * @param  Collection<int, MessageQueryRejection>  $rejections
     */
    public function __construct(
        #[\SensitiveParameter] public Collection $messages,
        #[\SensitiveParameter] public Collection $rejections,
        public bool $exhausted,
        #[\SensitiveParameter] public ?string $nextContinuation = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['messageCount' => $this->messages->count(), 'rejectionCount' => $this->rejections->count(), 'exhausted' => $this->exhausted];
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

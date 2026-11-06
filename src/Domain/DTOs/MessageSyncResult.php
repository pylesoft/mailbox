<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use JsonSerializable;
use Pyle\Mailbox\Models\MailboxMessage;

/** @implements Arrayable<string, mixed> */
final readonly class MessageSyncResult implements Arrayable, JsonSerializable
{
    /**
     * @param  Collection<int, MailboxMessage>  $messages
     * @param  Collection<int, array{providerMessageId: string, message: MailboxMessage}>  $persisted
     * @param  Collection<int, MessageQueryRejection>  $rejections
     * @param  Collection<int, string>  $excludedProviderMessageIds
     */
    public function __construct(
        #[\SensitiveParameter] public Collection $messages,
        #[\SensitiveParameter] public Collection $persisted,
        #[\SensitiveParameter] public Collection $rejections,
        #[\SensitiveParameter] public Collection $excludedProviderMessageIds,
        public bool $exhausted,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['messageCount' => $this->messages->count(), 'rejectionCount' => $this->rejections->count(), 'excludedCount' => $this->excludedProviderMessageIds->count(), 'exhausted' => $this->exhausted];
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

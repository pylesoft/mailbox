<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use InvalidArgumentException;
use JsonSerializable;

final readonly class ConversationRead implements JsonSerializable
{
    /**
     * @param  list<MessageDto>  $messages
     * @param  list<array{messageId: string, attachment: AttachmentDto}>  $files
     */
    public function __construct(
        public SyncIdentity $identity,
        public string $conversationId,
        public array $messages = [],
        public array $files = [],
        public bool $complete = false,
        public ?SyncContinuation $continuation = null,
        public ?string $reason = null,
    ) {
        if ($complete === ($continuation !== null)) {
            throw new InvalidArgumentException('A conversation read requires either complete content or an unfinished continuation.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [...get_object_vars($this), 'continuation' => $this->continuation?->__debugInfo()];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}

<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Pyle\Mailbox\Enums\ReplyForwardOperation;

final readonly class ReplyForwardContext
{
    public function __construct(
        public ReplyForwardOperation $operation,
        public ConversationRead $conversation,
        public string $sourceMessageId,
    ) {}
}

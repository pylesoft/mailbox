<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\DTOs\BodyReadLimits;
use Pyle\Mailbox\DTOs\ConversationRead;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncContinuation;
use Pyle\Mailbox\DTOs\SyncScope;

interface SupportsConversationRead
{
    public function conversation(string $conversationId, SyncScope $scope, SyncBudget $budget, #[\SensitiveParameter] ?SyncContinuation $continuation = null, ?BodyReadLimits $limits = null): ConversationRead;
}

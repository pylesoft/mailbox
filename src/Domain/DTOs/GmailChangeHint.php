<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

final readonly class GmailChangeHint
{
    public string $provider;

    public function __construct(
        public GrantContext $context,
        public string $topic,
        public string $subscription,
        public ?string $messageId,
        public ?string $historyId,
        public string $reason,
    ) {
        $this->provider = 'gmail';
    }
}

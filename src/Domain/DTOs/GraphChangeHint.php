<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

final readonly class GraphChangeHint
{
    public string $provider;

    public function __construct(
        public GrantContext $context,
        public string $subscriptionId,
        public string $resource,
        public string $reason,
        public ?string $notificationId = null,
    ) {
        $this->provider = 'ms-graph';
    }
}

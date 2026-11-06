<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use DateTimeImmutable;

final readonly class GraphSubscriptionRegistration
{
    public string $provider;

    public function __construct(
        public GrantContext $context,
        public GraphSubscriptionConfiguration $configuration,
        public string $subscriptionId,
        public DateTimeImmutable $expiresAt,
        public string $status = 'active',
    ) {
        $this->provider = 'ms-graph';
        if ($context->driver !== 'ms-graph' || ! in_array($status, ['active', 'removed'], true)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._=-]{0,1023}\z/', $subscriptionId) !== 1) {
            throw new \InvalidArgumentException('A Graph subscription requires a selected Graph context and a valid subscription identity.');
        }
    }

    public function lifecycle(): string
    {
        return $this->status === 'active' && $this->expiresAt <= new DateTimeImmutable ? 'expired' : $this->status;
    }
}

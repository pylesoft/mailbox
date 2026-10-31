<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use DateTimeImmutable;

final readonly class GmailWatchRegistration
{
    public string $provider;

    public function __construct(
        public GrantContext $context,
        public GmailWatchConfiguration $configuration,
        public string $historyId,
        public DateTimeImmutable $expiresAt,
        public string $status = 'active',
    ) {
        $this->provider = 'gmail';
        if (! in_array($context->driver, ['gmail', 'google-workspace'], true)
            || ! in_array($status, ['active', 'stopped', 'removed'], true) || preg_match('/\A[0-9]+\z/', $historyId) !== 1) {
            throw new \InvalidArgumentException('A Gmail watch registration requires a selected Gmail context, valid history evidence and lifecycle status.');
        }
    }

    public function lifecycle(): string
    {
        return $this->status === 'active' && $this->expiresAt <= new DateTimeImmutable ? 'expired' : $this->status;
    }
}

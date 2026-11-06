<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

final readonly class GrantIdentity
{
    public function __construct(
        public string $provider,
        public string $issuer,
        public string $subject,
        public string $clientId,
        public ?string $tenantId = null,
    ) {}

    public function matches(self $other): bool
    {
        return $this->provider === $other->provider
            && $this->issuer === $other->issuer
            && $this->subject === $other->subject
            && $this->clientId === $other->clientId
            && $this->tenantId === $other->tenantId;
    }
}

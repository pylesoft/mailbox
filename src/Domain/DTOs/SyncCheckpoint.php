<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

final readonly class SyncCheckpoint
{
    public function __construct(
        public SyncIdentity $identity,
        #[\SensitiveParameter] public string $position,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['identity' => $this->identity, 'position' => '[redacted]'];
    }
}

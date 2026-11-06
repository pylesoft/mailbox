<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

final readonly class SyncContinuation
{
    /** @param array<string, mixed> $position */
    public function __construct(
        public SyncIdentity $identity,
        public string $phase,
        #[\SensitiveParameter] public array $position,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['identity' => $this->identity, 'phase' => $this->phase, 'position' => '[redacted]'];
    }
}

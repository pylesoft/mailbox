<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Pyle\Mailbox\Exceptions\InvalidSyncStateException;

final readonly class SyncIdentity
{
    public function __construct(
        public string $provider,
        public GrantContext $context,
        public SyncScope $scope,
        public int $formatVersion = 1,
    ) {}

    public function assertMatches(self $expected): void
    {
        if ($this->formatVersion !== 1
            || $this->formatVersion !== $expected->formatVersion
            || $this->provider !== $expected->provider
            || get_object_vars($this->context) !== get_object_vars($expected->context)
            || $this->scope->folderId !== $expected->scope->folderId
            || $this->scope->importSince?->format('U.u') !== $expected->scope->importSince?->format('U.u')) {
            throw new InvalidSyncStateException('Synchronization state does not match the selected provider, grant, generation, mailbox, scope, cutoff or supported format.');
        }
    }
}

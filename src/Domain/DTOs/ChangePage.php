<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use InvalidArgumentException;
use JsonSerializable;

final readonly class ChangePage implements JsonSerializable
{
    /**
     * IDs are reconciliation evidence, not complete message content.
     *
     * @param  list<string>  $upserts
     * @param  list<string>  $removals
     * @param  list<array{folderId: string, upserts: list<string>, removals: list<string>}>  $folderChanges
     * @param  list<string>  $coveredFolders
     */
    public function __construct(
        public array $upserts = [],
        public array $removals = [],
        public ?SyncContinuation $continuation = null,
        public ?SyncCheckpoint $checkpoint = null,
        public bool $resetRequired = false,
        public ?string $reason = null,
        public array $folderChanges = [],
        public array $coveredFolders = [],
    ) {
        if ((int) ($continuation !== null) + (int) ($checkpoint !== null) + (int) $resetRequired !== 1) {
            throw new InvalidArgumentException('A change page requires exactly one continuation, completed checkpoint or reset requirement.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [...get_object_vars($this), 'continuation' => $this->continuation?->__debugInfo(),
            'checkpoint' => $this->checkpoint?->__debugInfo()];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}

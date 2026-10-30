<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use InvalidArgumentException;
use JsonSerializable;

final readonly class FolderCatalogue implements JsonSerializable
{
    /**
     * @param  list<FolderDto>  $folders
     * @param  array<string, string>  $wellKnownFolderIds
     * @param  list<string>  $rootFolderIds
     */
    public function __construct(
        public SyncIdentity $identity,
        public array $folders = [],
        public array $wellKnownFolderIds = [],
        public bool $complete = false,
        #[\SensitiveParameter] public ?SyncContinuation $continuation = null,
        public ?string $reason = null,
        public array $rootFolderIds = [],
    ) {
        if ($complete === ($continuation !== null)) {
            throw new InvalidArgumentException('A folder catalogue requires either completion or an unfinished continuation.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [...get_object_vars($this), 'continuation' => $this->continuation?->__debugInfo()];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}

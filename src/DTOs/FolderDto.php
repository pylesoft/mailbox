<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Pyle\Mailbox\DTOs\Concerns\ArraySerializable;
use Pyle\Mailbox\Enums\WellKnownFolder;

/** @implements Arrayable<string, mixed> */
final readonly class FolderDto implements JsonSerializable, Arrayable
{
    use ArraySerializable;

    /**
     * @param array<FolderDto> $children
     */
    public function __construct(
        public string $id,
        public string $displayName,
        public ?string $parentFolderId,
        public int $childFolderCount,
        public int $totalItemCount,
        public int $unreadItemCount,
        public ?string $path,
        public ?WellKnownFolder $wellKnownName,
        public array $children = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromMsGraph(array $data, ?string $path = null): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            displayName: (string) ($data['displayName'] ?? ''),
            parentFolderId: isset($data['parentFolderId']) ? (string) $data['parentFolderId'] : null,
            childFolderCount: (int) ($data['childFolderCount'] ?? 0),
            totalItemCount: (int) ($data['totalItemCount'] ?? 0),
            unreadItemCount: (int) ($data['unreadItemCount'] ?? 0),
            path: $path,
            wellKnownName: self::resolveWellKnown($data['displayName'] ?? null),
            children: [],
        );
    }

    private static function resolveWellKnown(?string $displayName): ?WellKnownFolder
    {
        if ($displayName === null) {
            return null;
        }

        return match (strtolower($displayName)) {
            'inbox' => WellKnownFolder::INBOX,
            'drafts' => WellKnownFolder::DRAFTS,
            'sent items', 'sentitems' => WellKnownFolder::SENT,
            'deleted items', 'deleteditems' => WellKnownFolder::DELETED,
            'junk email', 'junkemail' => WellKnownFolder::JUNK,
            'archive' => WellKnownFolder::ARCHIVE,
            'outbox' => WellKnownFolder::OUTBOX,
            default => null,
        };
    }

    /** @param array<FolderDto> $children */
    public function withChildren(array $children): self
    {
        return new self(
            id: $this->id,
            displayName: $this->displayName,
            parentFolderId: $this->parentFolderId,
            childFolderCount: $this->childFolderCount,
            totalItemCount: $this->totalItemCount,
            unreadItemCount: $this->unreadItemCount,
            path: $this->path,
            wellKnownName: $this->wellKnownName,
            children: $children,
        );
    }

    public function withPath(?string $path): self
    {
        return new self(
            id: $this->id,
            displayName: $this->displayName,
            parentFolderId: $this->parentFolderId,
            childFolderCount: $this->childFolderCount,
            totalItemCount: $this->totalItemCount,
            unreadItemCount: $this->unreadItemCount,
            path: $path,
            wellKnownName: $this->wellKnownName,
            children: $this->children,
        );
    }
}

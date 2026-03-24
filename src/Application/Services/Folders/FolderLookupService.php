<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\Folders;

use Illuminate\Support\Collection;
use Pyle\Mailbox\DTOs\FolderDto;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Facades\Mailbox as MailboxFacade;
use Pyle\Mailbox\Models\Mailbox;

class FolderLookupService
{
    /**
     * @return Collection<int, array{id: string, display_name: string, path: string, parent_id: string|null, child_folder_count: int|null}>
     */
    public function listTree(Mailbox $mailbox, int $maxDepth = 10): Collection
    {
        $folders = MailboxFacade::forMailbox($mailbox)->folders()->tree(max(1, $maxDepth));
        $items = [];
        $visited = [];

        foreach ($folders as $folder) {
            $this->flattenFolderTree($folder, '', null, 0, max(1, $maxDepth), $items, $visited);
        }

        return collect($items)->sortBy('path')->values();
    }

    /**
     * @return array{id: string, display_name: string, path: string, parent_id: string|null, child_folder_count: int|null}|null
     */
    public function findByName(
        Mailbox $mailbox,
        string $folderName,
        string|WellKnownFolder|null $root = null,
        bool $caseSensitive = true,
    ): ?array {
        $folder = MailboxFacade::forMailbox($mailbox)->folders()->find($folderName, $root, $caseSensitive);

        if (! $folder instanceof FolderDto) {
            return null;
        }

        return [
            'id' => $folder->id,
            'display_name' => $folder->displayName,
            'path' => (string) ($folder->path ?: $folder->displayName),
            'parent_id' => $folder->parentFolderId,
            'child_folder_count' => $folder->childFolderCount,
        ];
    }

    /**
     * @param  array<int, array{id: string, display_name: string, path: string, parent_id: string|null, child_folder_count: int|null}>  $items
     * @param  array<string, bool>  $visited
     */
    private function flattenFolderTree(
        FolderDto $folder,
        string $currentPath,
        ?string $parentId,
        int $depth,
        int $maxDepth,
        array &$items,
        array &$visited,
    ): void {
        if ($depth >= $maxDepth || isset($visited[$folder->id])) {
            return;
        }

        $visited[$folder->id] = true;
        $path = $currentPath === ''
            ? $folder->displayName
            : $currentPath.'/'.$folder->displayName;

        $items[] = [
            'id' => $folder->id,
            'display_name' => $folder->displayName,
            'path' => $path,
            'parent_id' => $parentId,
            'child_folder_count' => $folder->childFolderCount,
        ];

        foreach ($folder->children as $child) {
            $this->flattenFolderTree($child, $path, $folder->id, $depth + 1, $maxDepth, $items, $visited);
        }
    }
}

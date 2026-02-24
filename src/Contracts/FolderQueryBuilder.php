<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Illuminate\Support\Collection;
use Pyle\Mailbox\DTOs\FolderDto;
use Pyle\Mailbox\Enums\WellKnownFolder;

interface FolderQueryBuilder
{
    /** @return Collection<int, FolderDto> */
    public function get(): Collection;

    /** @return Collection<int, FolderDto> */
    public function tree(int $maxDepth = 10): Collection;

    public function find(string $name, string|WellKnownFolder|null $root = null, bool $caseSensitive = true): ?FolderDto;

    public function create(string $name, ?string $parentId = null): FolderDto;

    public function createPath(string $path): FolderDto;
}

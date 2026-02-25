<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\FolderQueryBuilder;
use Pyle\Mailbox\DTOs\FolderDto;
use Pyle\Mailbox\Enums\WellKnownFolder;

class GmailFolderQuery implements FolderQueryBuilder
{
    public function __construct(
        private readonly GmailClient $client,
        private readonly string $mailbox,
    ) {}

    /** @return Collection<int, FolderDto> */
    public function get(): Collection
    {
        $payload = $this->client->get(sprintf('users/%s/labels', rawurlencode($this->mailbox)), mailbox: $this->mailbox);

        return collect((array) ($payload['labels'] ?? []))
            ->filter(fn (mixed $label): bool => is_array($label))
            ->map(fn (array $label): FolderDto => FolderDto::fromGmail($label))
            ->map(fn (FolderDto $folder): FolderDto => $folder->withPath($folder->displayName))
            ->values();
    }

    /** @return Collection<int, FolderDto> */
    public function tree(int $maxDepth = 10): Collection
    {
        $all = $this->get();
        $paths = $all->keyBy(fn (FolderDto $folder): string => $folder->path ?? $folder->displayName);

        /** @var array<string, array<string>> $childrenMap */
        $childrenMap = [];

        foreach ($paths as $path => $folder) {
            if ($path === '' || ! str_contains($path, '/')) {
                continue;
            }

            $parentPath = $this->parentPath($path);
            if ($parentPath === null) {
                continue;
            }

            $childrenMap[$parentPath] ??= [];
            $childrenMap[$parentPath][] = $path;
        }

        $roots = $paths->keys()
            ->filter(fn (string $path): bool => $path !== '' && ! str_contains($path, '/'))
            ->values();

        return $roots
            ->map(function (string $path) use ($paths, $childrenMap, $maxDepth): ?FolderDto {
                $folder = $paths->get($path);

                return $folder instanceof FolderDto
                    ? $this->buildNode($folder, $path, $paths, $childrenMap, 1, $maxDepth)
                    : null;
            })
            ->filter(fn (?FolderDto $folder): bool => $folder instanceof FolderDto)
            ->values();
    }

    public function find(string $name, string|WellKnownFolder|null $root = null, bool $caseSensitive = true): ?FolderDto
    {
        $folders = $this->flatten($this->tree(20));
        $needle = $caseSensitive ? $name : mb_strtolower($name);
        $rootPath = null;

        if ($root !== null) {
            $resolvedRoot = GmailLabelResolver::resolve($root);
            $rootFolder = $folders->first(fn (FolderDto $folder): bool => $folder->id === $resolvedRoot || $folder->displayName === $resolvedRoot);
            $rootPath = $rootFolder?->path;
        }

        return $folders->first(function (FolderDto $folder) use ($needle, $caseSensitive, $rootPath): bool {
            if (is_string($rootPath) && $rootPath !== '') {
                $path = $folder->path ?? '';
                if ($path !== $rootPath && ! str_starts_with($path, $rootPath.'/')) {
                    return false;
                }
            }

            $value = $caseSensitive ? $folder->displayName : mb_strtolower($folder->displayName);

            return $value === $needle;
        });
    }

    public function create(string $name, ?string $parentId = null): FolderDto
    {
        $labelName = $parentId !== null && trim($parentId) !== ''
            ? trim($parentId, '/').'/'.trim($name, '/')
            : trim($name, '/');

        $payload = $this->client->post(
            sprintf('users/%s/labels', rawurlencode($this->mailbox)),
            [
                'name' => $labelName,
                'labelListVisibility' => 'labelShow',
                'messageListVisibility' => 'show',
            ],
            $this->mailbox,
        );

        return FolderDto::fromGmail($payload)->withPath((string) ($payload['name'] ?? $labelName));
    }

    public function createPath(string $path): FolderDto
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        if ($segments === []) {
            throw new \InvalidArgumentException('Path must contain at least one segment.');
        }

        $currentPath = '';
        $existingByName = $this->get()->keyBy(fn (FolderDto $folder): string => $folder->displayName);

        foreach ($segments as $segment) {
            $currentPath = trim($currentPath.'/'.$segment, '/');

            if ($existingByName->has($currentPath)) {
                continue;
            }

            $created = $this->create($segment, $this->parentPath($currentPath));
            $existingByName->put($created->displayName, $created);
        }

        $resolved = $existingByName->get($currentPath);

        if (! $resolved instanceof FolderDto) {
            throw new \RuntimeException(sprintf('Failed to create folder path "%s".', $path));
        }

        return $resolved->withPath($currentPath);
    }

    /** @param Collection<int, FolderDto> $folders
     * @return Collection<int, FolderDto>
     */
    private function flatten(Collection $folders): Collection
    {
        return $folders->flatMap(function (FolderDto $folder): array {
            $items = [$folder];

            if ($folder->children !== []) {
                $items = array_merge($items, $this->flatten(collect($folder->children))->all());
            }

            return $items;
        })->values();
    }

    /** @param Collection<string, FolderDto> $all
     * @param  array<string, array<string>>  $childrenMap
     */
    private function buildNode(FolderDto $folder, string $path, Collection $all, array $childrenMap, int $depth, int $maxDepth): FolderDto
    {
        if ($depth >= $maxDepth) {
            return $folder;
        }

        $childrenPaths = $childrenMap[$path] ?? [];

        if ($childrenPaths === []) {
            return $folder;
        }

        $children = collect($childrenPaths)
            ->map(function (string $childPath) use ($all, $childrenMap, $depth, $maxDepth): ?FolderDto {
                $child = $all->get($childPath);

                if (! $child instanceof FolderDto) {
                    return null;
                }

                return $this->buildNode($child, $childPath, $all, $childrenMap, $depth + 1, $maxDepth);
            })
            ->filter(fn (?FolderDto $child): bool => $child instanceof FolderDto)
            ->values()
            ->all();

        return $folder->withChildren($children);
    }

    private function parentPath(string $path): ?string
    {
        if (! str_contains($path, '/')) {
            return null;
        }

        $parts = explode('/', $path);
        array_pop($parts);
        $parent = trim(implode('/', $parts), '/');

        return $parent !== '' ? $parent : null;
    }
}

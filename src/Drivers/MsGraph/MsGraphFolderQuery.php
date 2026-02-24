<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\FolderQueryBuilder;
use Pyle\Mailbox\DTOs\FolderDto;
use Pyle\Mailbox\Enums\WellKnownFolder;

class MsGraphFolderQuery implements FolderQueryBuilder
{
    public function __construct(
        private readonly GraphClient $client,
        private readonly string $mailbox,
    ) {}

    /** @return Collection<int, FolderDto> */
    public function get(): Collection
    {
        $response = $this->client->get(
            sprintf('users/%s/mailFolders', rawurlencode($this->mailbox)),
            ['$top' => 200],
            $this->mailbox,
        );

        return collect((array) ($response['value'] ?? []))
            ->map(fn (mixed $item): FolderDto => FolderDto::fromMsGraph(is_array($item) ? $item : []))
            ->values();
    }

    /** @return Collection<int, FolderDto> */
    public function tree(int $maxDepth = 10): Collection
    {
        $roots = $this->get();

        return $roots->map(fn (FolderDto $folder): FolderDto => $this->withChildrenRecursive($folder, 1, $maxDepth, $folder->displayName));
    }

    public function find(string $name, string|WellKnownFolder|null $root = null, bool $caseSensitive = true): ?FolderDto
    {
        $items = $root === null
            ? $this->tree()
            : $this->childrenOf(FolderIdResolver::resolve($root), $root instanceof WellKnownFolder ? $root->name : (string) $root, 1, 10);

        $needle = $caseSensitive ? $name : mb_strtolower($name);

        return $this->flatten($items)->first(function (FolderDto $folder) use ($needle, $caseSensitive): bool {
            $value = $caseSensitive ? $folder->displayName : mb_strtolower($folder->displayName);

            return $value === $needle;
        });
    }

    public function create(string $name, ?string $parentId = null): FolderDto
    {
        $endpoint = $parentId === null
            ? sprintf('users/%s/mailFolders', rawurlencode($this->mailbox))
            : sprintf('users/%s/mailFolders/%s/childFolders', rawurlencode($this->mailbox), rawurlencode($parentId));

        $payload = $this->client->post($endpoint, ['displayName' => $name], $this->mailbox);

        return FolderDto::fromMsGraph($payload);
    }

    public function createPath(string $path): FolderDto
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        if ($segments === []) {
            throw new \InvalidArgumentException('Path must contain at least one segment.');
        }

        $parent = null;
        $currentPath = [];

        foreach ($segments as $segment) {
            $currentPath[] = $segment;
            $existing = $this->find($segment, $parent, true);

            if ($existing !== null) {
                $parent = $existing->id;

                continue;
            }

            $created = $this->create($segment, is_string($parent) ? $parent : null);
            $parent = $created->id;
        }

        $folder = $this->client->get(
            sprintf('users/%s/mailFolders/%s', rawurlencode($this->mailbox), rawurlencode((string) $parent)),
            mailbox: $this->mailbox,
        );

        return FolderDto::fromMsGraph($folder, implode('/', $currentPath));
    }

    private function withChildrenRecursive(FolderDto $folder, int $depth, int $maxDepth, string $path): FolderDto
    {
        $current = $folder->withPath($path);

        if ($depth >= $maxDepth || $folder->childFolderCount < 1) {
            return $current;
        }

        $children = $this->childrenOf($folder->id, $path, $depth + 1, $maxDepth);

        return $current->withChildren($children->all());
    }

    /** @return Collection<int, FolderDto> */
    private function childrenOf(string $folderId, string $path, int $depth, int $maxDepth): Collection
    {
        $response = $this->client->get(
            sprintf('users/%s/mailFolders/%s/childFolders', rawurlencode($this->mailbox), rawurlencode($folderId)),
            ['$top' => 200],
            $this->mailbox,
        );

        return collect((array) ($response['value'] ?? []))
            ->map(function (mixed $item) use ($path, $depth, $maxDepth): FolderDto {
                $folder = FolderDto::fromMsGraph(is_array($item) ? $item : []);

                return $this->withChildrenRecursive($folder, $depth, $maxDepth, trim($path.'/'.$folder->displayName, '/'));
            })
            ->values();
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
}

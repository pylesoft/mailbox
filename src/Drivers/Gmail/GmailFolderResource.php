<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\FolderResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\DTOs\DeltaResultDto;
use Pyle\Mailbox\DTOs\FolderDto;
use Pyle\Mailbox\Exceptions\MailboxException;

class GmailFolderResource implements FolderResource
{
    public function __construct(
        private readonly GmailClient $client,
        private readonly GmailDeltaSync $deltaSync,
        private readonly string $mailbox,
        private readonly string $folderId,
    ) {}

    public function get(): FolderDto
    {
        $payload = $this->client->get($this->folderEndpoint(), mailbox: $this->mailbox);

        return FolderDto::fromGmail($payload)->withPath((string) ($payload['name'] ?? $payload['id'] ?? ''));
    }

    /** @return Collection<int, FolderDto> */
    public function children(): Collection
    {
        $current = $this->get();

        return (new GmailFolderQuery($this->client, $this->mailbox))
            ->get()
            ->filter(function (FolderDto $folder) use ($current): bool {
                $parentPath = $folder->parentFolderId;
                $currentPath = $current->path ?? $current->displayName;

                return is_string($parentPath) && $parentPath === $currentPath;
            })
            ->values();
    }

    public function messages(): MessageQueryBuilder
    {
        return (new GmailMessageQuery($this->client, $this->mailbox))->inFolder($this->folderId);
    }

    public function delta(?string $deltaToken = null): DeltaResultDto
    {
        return $this->deltaSync->syncFolder($this->mailbox, $this->folderId, $deltaToken);
    }

    public function delete(): void
    {
        $this->client->delete($this->folderEndpoint(), $this->mailbox);
    }

    public function moveTo(string $destinationParentId): FolderDto
    {
        throw new MailboxException(
            'Gmail labels do not support folder-parent moves through this API. Create the destination path and recreate/update labels instead.',
        );
    }

    private function folderEndpoint(): string
    {
        return sprintf('users/%s/labels/%s', rawurlencode($this->mailbox), rawurlencode($this->folderId));
    }
}

<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\FolderResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\DTOs\DeltaResultDto;
use Pyle\Mailbox\DTOs\FolderDto;

class MsGraphFolderResource implements FolderResource
{
    public function __construct(
        private readonly GraphClient $client,
        private readonly BatchRequest $batch,
        private readonly MsGraphDeltaSync $deltaSync,
        private readonly string $mailbox,
        private readonly string $folderId,
    ) {}

    public function get(): FolderDto
    {
        $payload = $this->client->get($this->folderEndpoint(), mailbox: $this->mailbox);

        return FolderDto::fromMsGraph($payload);
    }

    /** @return Collection<int, FolderDto> */
    public function children(): Collection
    {
        $payload = $this->client->get($this->folderEndpoint().'/childFolders', mailbox: $this->mailbox);

        return collect((array) ($payload['value'] ?? []))
            ->map(fn (mixed $item): FolderDto => FolderDto::fromMsGraph(is_array($item) ? $item : []))
            ->values();
    }

    public function messages(): MessageQueryBuilder
    {
        return (new MsGraphMessageQuery($this->client, $this->batch, $this->mailbox))
            ->inFolder($this->folderId);
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
        $payload = $this->client->post($this->folderEndpoint().'/move', [
            'destinationId' => $destinationParentId,
        ], $this->mailbox);

        return FolderDto::fromMsGraph($payload);
    }

    private function folderEndpoint(): string
    {
        return sprintf('users/%s/mailFolders/%s', rawurlencode($this->mailbox), rawurlencode($this->folderId));
    }
}

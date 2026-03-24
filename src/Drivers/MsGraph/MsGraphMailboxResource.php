<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Pyle\Mailbox\Contracts\FolderQueryBuilder;
use Pyle\Mailbox\Contracts\FolderResource;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\Drivers\MsGraph\FolderIdResolver;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphFolderResource;
use Pyle\Mailbox\Enums\WellKnownFolder;

class MsGraphMailboxResource implements MailboxResource
{
    public function __construct(
        private readonly GraphClient $client,
        private readonly BatchRequest $batch,
        private readonly MsGraphDeltaSync $deltaSync,
        private readonly string $emailAddress,
    ) {}

    public function messages(): MessageQueryBuilder
    {
        return new MsGraphMessageQuery($this->client, $this->batch, $this->emailAddress);
    }

    public function message(string $messageId): MessageResource
    {
        return new MsGraphMessageResource($this->client, $this->emailAddress, $messageId);
    }

    public function folders(): FolderQueryBuilder
    {
        return new MsGraphFolderQuery($this->client, $this->emailAddress);
    }

    public function folder(string|WellKnownFolder $folderId): FolderResource
    {
        return new MsGraphFolderResource(
            client: $this->client,
            batch: $this->batch,
            deltaSync: $this->deltaSync,
            mailbox: $this->emailAddress,
            folderId: FolderIdResolver::resolve($folderId),
        );
    }
}

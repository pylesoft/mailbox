<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Pyle\Mailbox\Contracts\FolderQueryBuilder;
use Pyle\Mailbox\Contracts\FolderResource;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\Drivers\Gmail\GmailFolderResource;
use Pyle\Mailbox\Drivers\Gmail\GmailLabelResolver;
use Pyle\Mailbox\Enums\WellKnownFolder;

class GmailMailboxResource implements MailboxResource
{
    public function __construct(
        private readonly GmailClient $client,
        private readonly GmailDeltaSync $deltaSync,
        private readonly string $emailAddress,
    ) {}

    public function messages(): MessageQueryBuilder
    {
        return new GmailMessageQuery($this->client, $this->emailAddress);
    }

    public function message(string $messageId): MessageResource
    {
        return new GmailMessageResource($this->client, $this->emailAddress, $messageId);
    }

    public function folders(): FolderQueryBuilder
    {
        return new GmailFolderQuery($this->client, $this->emailAddress);
    }

    public function folder(string|WellKnownFolder $folderId): FolderResource
    {
        return new GmailFolderResource(
            client: $this->client,
            deltaSync: $this->deltaSync,
            mailbox: $this->emailAddress,
            folderId: GmailLabelResolver::resolve($folderId),
        );
    }
}

<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Pyle\Mailbox\Contracts\FolderQueryBuilder;
use Pyle\Mailbox\Contracts\FolderResource;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\Contracts\SupportsInitialSync;
use Pyle\Mailbox\Contracts\SupportsIncrementalSync;
use Pyle\Mailbox\DTOs\ChangePage;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncCheckpoint;
use Pyle\Mailbox\DTOs\SyncContinuation;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;

class GmailMailboxResource implements MailboxResource, SupportsInitialSync, SupportsIncrementalSync
{
    public function __construct(
        private readonly GmailClient $client,
        private readonly GmailDeltaSync $deltaSync,
        private readonly string $emailAddress,
        private readonly ?GrantContext $grantContext = null,
    ) {}

    public function initialSync(SyncScope $scope, SyncBudget $budget, ?SyncContinuation $continuation = null): ChangePage
    {
        if ($this->grantContext === null) {
            throw new UnsupportedCapabilityException('Bounded synchronization requires an explicit grant context.');
        }

        return (new GmailInitialSync($this->client, $this->grantContext))->page($scope, $budget, $continuation);
    }

    public function incrementalSync(SyncScope $scope, SyncBudget $budget, SyncCheckpoint $checkpoint, ?SyncContinuation $continuation = null): ChangePage
    {
        if ($this->grantContext === null) {
            throw new UnsupportedCapabilityException('Bounded synchronization requires an explicit grant context.');
        }

        return (new GmailHistorySync($this->client, $this->grantContext))->page($scope, $budget, $checkpoint, $continuation);
    }

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

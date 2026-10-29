<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Pyle\Mailbox\Contracts\FolderQueryBuilder;
use Pyle\Mailbox\Contracts\FolderResource;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\Contracts\SupportsConversationRead;
use Pyle\Mailbox\Contracts\SupportsIncrementalSync;
use Pyle\Mailbox\Contracts\SupportsInitialSync;
use Pyle\Mailbox\Contracts\SupportsMessageSubmission;
use Pyle\Mailbox\Contracts\SupportsSubmissionLimits;
use Pyle\Mailbox\DTOs\BodyReadLimits;
use Pyle\Mailbox\DTOs\ChangePage;
use Pyle\Mailbox\DTOs\ConversationRead;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\SubmissionResult;
use Pyle\Mailbox\DTOs\SubmissionLimits;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncCheckpoint;
use Pyle\Mailbox\DTOs\SyncContinuation;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;

class GmailMailboxResource implements MailboxResource, SupportsConversationRead, SupportsIncrementalSync, SupportsInitialSync, SupportsMessageSubmission, SupportsSubmissionLimits
{
    public function __construct(
        private readonly GmailClient $client,
        private readonly GmailDeltaSync $deltaSync,
        private readonly string $emailAddress,
        private readonly ?GrantContext $grantContext = null,
        private readonly ?SubmissionLimits $submissionLimits = null,
    ) {}

    public function conversation(string $conversationId, SyncScope $scope, SyncBudget $budget, #[\SensitiveParameter] ?SyncContinuation $continuation = null, ?BodyReadLimits $limits = null): ConversationRead
    {
        if ($this->grantContext === null) {
            throw new UnsupportedCapabilityException('Conversation reads require an explicit grant context.');
        }

        return (new GmailConversationReader($this->client, $this->grantContext))->read($conversationId, $scope, $budget, $continuation, $limits);
    }

    public function submit(PreparedMessage $message, int $maxRequestBytes = 1_048_576): SubmissionResult
    {
        return $this->client->submitMessage($message, $this->emailAddress, $maxRequestBytes, $this->submissionLimits);
    }

    public function withSubmissionLimits(SubmissionLimits $limits): SupportsMessageSubmission
    {
        return new self(
            client: $this->client,
            deltaSync: $this->deltaSync,
            emailAddress: $this->emailAddress,
            grantContext: $this->grantContext,
            submissionLimits: $limits,
        );
    }

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

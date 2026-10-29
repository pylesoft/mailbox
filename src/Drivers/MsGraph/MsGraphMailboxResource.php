<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Pyle\Mailbox\Contracts\FolderQueryBuilder;
use Pyle\Mailbox\Contracts\FolderResource;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\Contracts\SupportsConversationRead;
use Pyle\Mailbox\Contracts\SupportsDraftAttachmentPreparation;
use Pyle\Mailbox\Contracts\SupportsDraftSubmission;
use Pyle\Mailbox\Contracts\SupportsDraftSubmissionInspection;
use Pyle\Mailbox\Contracts\SupportsIncrementalSync;
use Pyle\Mailbox\Contracts\SupportsInitialSync;
use Pyle\Mailbox\DTOs\BodyReadLimits;
use Pyle\Mailbox\DTOs\ChangePage;
use Pyle\Mailbox\DTOs\ConversationRead;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\SubmissionEvidence;
use Pyle\Mailbox\DTOs\SubmissionLimits;
use Pyle\Mailbox\DTOs\SubmissionPreparation;
use Pyle\Mailbox\DTOs\SubmissionResult;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncCheckpoint;
use Pyle\Mailbox\DTOs\SyncContinuation;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;

class MsGraphMailboxResource implements MailboxResource, SupportsConversationRead, SupportsDraftAttachmentPreparation, SupportsDraftSubmission, SupportsDraftSubmissionInspection, SupportsIncrementalSync, SupportsInitialSync
{
    public function __construct(
        private readonly GraphClient $client,
        private readonly BatchRequest $batch,
        private readonly MsGraphDeltaSync $deltaSync,
        private readonly string $emailAddress,
        private readonly ?GrantContext $grantContext = null,
    ) {}

    public function conversation(string $conversationId, SyncScope $scope, SyncBudget $budget, #[\SensitiveParameter] ?SyncContinuation $continuation = null, ?BodyReadLimits $limits = null): ConversationRead
    {
        if ($this->grantContext === null) {
            throw new UnsupportedCapabilityException('Conversation reads require an explicit grant context.');
        }

        return (new MsGraphConversationReader($this->client, $this->grantContext))->read($conversationId, $scope, $budget, $continuation, $limits);
    }

    public function prepareSubmission(PreparedMessage $message, int $maxRequestBytes = 4_000_000): SubmissionPreparation
    {
        return $this->draftSubmission()->prepare($message, $maxRequestBytes);
    }

    public function submitPrepared(SubmissionPreparation $preparation, PreparedMessage $message): SubmissionResult
    {
        return $this->draftSubmission()->submit($preparation, $message);
    }

    public function prepareAttachments(SubmissionPreparation $preparation, PreparedMessage $message, ?SubmissionLimits $limits = null, int $maxRequestBytes = 4_000_000): SubmissionPreparation
    {
        return $this->draftSubmission()->prepareAttachments($preparation, $message, $limits ?? new SubmissionLimits, $maxRequestBytes);
    }

    public function inspectSubmission(SubmissionPreparation $preparation, PreparedMessage $message): SubmissionEvidence
    {
        return $this->draftSubmission()->inspect($preparation, $message);
    }

    private function draftSubmission(): MsGraphDraftSubmission
    {
        if ($this->grantContext === null) {
            throw new UnsupportedCapabilityException('Draft submission requires an explicit grant context.');
        }

        return new MsGraphDraftSubmission($this->client, $this->grantContext);
    }

    public function initialSync(SyncScope $scope, SyncBudget $budget, ?SyncContinuation $continuation = null): ChangePage
    {
        return $this->boundedSync()->page($scope, $budget, null, $continuation);
    }

    public function incrementalSync(SyncScope $scope, SyncBudget $budget, SyncCheckpoint $checkpoint, ?SyncContinuation $continuation = null): ChangePage
    {
        return $this->boundedSync()->page($scope, $budget, $checkpoint, $continuation);
    }

    private function boundedSync(): MsGraphBoundedSync
    {
        if ($this->grantContext === null) {
            throw new UnsupportedCapabilityException('Bounded synchronization requires an explicit grant context.');
        }

        return new MsGraphBoundedSync($this->client, $this->grantContext);
    }

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

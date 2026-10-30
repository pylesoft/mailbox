<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Psr\Http\Message\RequestInterface;
use Pyle\Mailbox\Contracts\FolderQueryBuilder;
use Pyle\Mailbox\Contracts\FolderResource;
use Pyle\Mailbox\Contracts\GmailPushVerifier;
use Pyle\Mailbox\Contracts\GrantAccessGuard;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\Contracts\SupportsConversationRead;
use Pyle\Mailbox\Contracts\SupportsIncrementalSync;
use Pyle\Mailbox\Contracts\SupportsInitialSync;
use Pyle\Mailbox\Contracts\SupportsMessageSubmission;
use Pyle\Mailbox\Contracts\SupportsSubmissionLimits;
use Pyle\Mailbox\Drivers\Gmail\Contracts\SupportsGmailSubmissionInspection;
use Pyle\Mailbox\Drivers\Gmail\Contracts\SupportsGmailWatch;
use Pyle\Mailbox\DTOs\BodyReadLimits;
use Pyle\Mailbox\DTOs\ChangePage;
use Pyle\Mailbox\DTOs\ConversationRead;
use Pyle\Mailbox\DTOs\GmailChangeHint;
use Pyle\Mailbox\DTOs\GmailWatchConfiguration;
use Pyle\Mailbox\DTOs\GmailWatchRegistration;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\SubmissionEvidence;
use Pyle\Mailbox\DTOs\SubmissionLimits;
use Pyle\Mailbox\DTOs\SubmissionResult;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncCheckpoint;
use Pyle\Mailbox\DTOs\SyncContinuation;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;

class GmailMailboxResource implements MailboxResource, SupportsConversationRead, SupportsGmailSubmissionInspection, SupportsGmailWatch, SupportsIncrementalSync, SupportsInitialSync, SupportsMessageSubmission, SupportsSubmissionLimits
{
    public function __construct(
        private readonly GmailClient $client,
        private readonly GmailDeltaSync $deltaSync,
        private readonly string $emailAddress,
        private readonly ?GrantContext $grantContext = null,
        private readonly ?GrantAccessGuard $grantGuard = null,
        private readonly ?SubmissionLimits $submissionLimits = null,
    ) {}

    /** @param list<string> $labelIds */
    public function watch(GmailWatchConfiguration $configuration, array $labelIds = []): GmailWatchRegistration
    {
        return $this->watchClient()->register($configuration, $labelIds);
    }

    public function notification(#[\SensitiveParameter] RequestInterface $request, GmailWatchRegistration $registration, #[\SensitiveParameter] ?GmailPushVerifier $verifier = null, int $maxRequestBytes = 65_536): GmailChangeHint
    {
        return $this->watchClient()->notification($request, $registration, $verifier, $maxRequestBytes);
    }

    public function stopWatch(GmailWatchRegistration $registration): GmailWatchRegistration
    {
        return $this->watchClient()->stop($registration);
    }

    public function watchCatchUpHint(GmailWatchRegistration $registration): GmailChangeHint
    {
        return $this->watchClient()->catchUpHint($registration);
    }

    private function watchClient(): GmailWatch
    {
        if ($this->grantContext === null || $this->grantGuard === null) {
            throw new UnsupportedCapabilityException('Gmail watches require an explicit grant context and lifecycle guard.');
        }

        return new GmailWatch($this->client, $this->grantContext, $this->grantGuard);
    }

    /** @return array{provider: string, mailbox: string} */
    public function __debugInfo(): array
    {
        return ['provider' => 'gmail', 'mailbox' => $this->emailAddress];
    }

    public function conversation(string $conversationId, SyncScope $scope, SyncBudget $budget, #[\SensitiveParameter] ?SyncContinuation $continuation = null, ?BodyReadLimits $limits = null): ConversationRead
    {
        if ($this->grantContext === null) {
            throw new UnsupportedCapabilityException('Conversation reads require an explicit grant context.');
        }

        return (new GmailConversationReader($this->client, $this->grantContext))->read($conversationId, $scope, $budget, $continuation, $limits);
    }

    public function submit(PreparedMessage $message, int $maxRequestBytes = 1_048_576): SubmissionResult
    {
        return $this->client->submitMessage($message, $this->emailAddress, $maxRequestBytes, $this->submissionLimits, $this->grantContext);
    }

    public function inspectSubmission(SubmissionResult $submission): SubmissionEvidence
    {
        if ($this->grantContext === null) {
            throw new UnsupportedCapabilityException('Gmail submission inspection requires an explicit grant context.');
        }

        return $this->client->inspectSubmission($submission, $this->grantContext);
    }

    public function withSubmissionLimits(SubmissionLimits $limits): SupportsMessageSubmission
    {
        return new self(
            client: $this->client,
            deltaSync: $this->deltaSync,
            emailAddress: $this->emailAddress,
            grantContext: $this->grantContext,
            grantGuard: $this->grantGuard,
            submissionLimits: $limits,
        );
    }

    public function initialSync(SyncScope $scope, SyncBudget $budget, #[\SensitiveParameter] ?SyncContinuation $continuation = null): ChangePage
    {
        if ($this->grantContext === null) {
            throw new UnsupportedCapabilityException('Bounded synchronization requires an explicit grant context.');
        }

        return (new GmailInitialSync($this->client, $this->grantContext))->page($scope, $budget, $continuation);
    }

    public function incrementalSync(SyncScope $scope, SyncBudget $budget, #[\SensitiveParameter] SyncCheckpoint $checkpoint, #[\SensitiveParameter] ?SyncContinuation $continuation = null): ChangePage
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

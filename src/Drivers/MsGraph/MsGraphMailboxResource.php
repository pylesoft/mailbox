<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Pyle\Mailbox\Contracts\FolderQueryBuilder;
use Pyle\Mailbox\Contracts\FolderResource;
use Pyle\Mailbox\Contracts\GrantAccessGuard;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\Contracts\SupportsConversationRead;
use Pyle\Mailbox\Contracts\SupportsDraftAttachmentPreparation;
use Pyle\Mailbox\Contracts\SupportsDraftSubmission;
use Pyle\Mailbox\Contracts\SupportsDraftSubmissionInspection;
use Pyle\Mailbox\Contracts\SupportsIncrementalSync;
use Pyle\Mailbox\Contracts\SupportsInitialSync;
use Pyle\Mailbox\Drivers\MsGraph\Contracts\SupportsGraphSharedMailboxLive;
use Pyle\Mailbox\Drivers\MsGraph\Contracts\SupportsGraphSubscriptions;
use Pyle\Mailbox\Drivers\MsGraph\Contracts\VerifiesGraphSharedMailboxLiveAccess;
use Pyle\Mailbox\DTOs\BodyReadLimits;
use Pyle\Mailbox\DTOs\ChangePage;
use Pyle\Mailbox\DTOs\ConversationRead;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\GraphChangeHint;
use Pyle\Mailbox\DTOs\GraphSubscriptionConfiguration;
use Pyle\Mailbox\DTOs\GraphSubscriptionRegistration;
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

class MsGraphMailboxResource implements MailboxResource, SupportsConversationRead, SupportsDraftAttachmentPreparation, SupportsDraftSubmission, SupportsDraftSubmissionInspection, SupportsGraphSharedMailboxLive, SupportsGraphSubscriptions, SupportsIncrementalSync, SupportsInitialSync
{
    public function __construct(
        private readonly GraphClient $client,
        private readonly BatchRequest $batch,
        private readonly MsGraphDeltaSync $deltaSync,
        private readonly string $emailAddress,
        private readonly ?GrantContext $grantContext = null,
        private readonly ?GrantAccessGuard $grantGuard = null,
    ) {}

    /** @return array{provider: string, mailbox: string} */
    public function __debugInfo(): array
    {
        return ['provider' => 'ms-graph', 'mailbox' => $this->emailAddress];
    }

    public function requireSharedMailboxLive(GraphSubscriptionRegistration $registration): void
    {
        if ($this->grantContext === null || ! $this->grantGuard instanceof VerifiesGraphSharedMailboxLiveAccess) {
            throw new UnsupportedCapabilityException('Required shared-mailbox live mode needs a captured host verifier for application authority and push setup.');
        }
        $this->grantGuard->assertActive($this->grantContext);
        if (! $this->grantContext->matches($registration->context) || $registration->lifecycle() !== 'active') {
            throw new UnsupportedCapabilityException('Required shared-mailbox live mode needs an active, unexpired subscription for the exact selected grant context.');
        }
        $this->grantGuard->assertSharedMailboxLive($this->grantContext, $registration->configuration);
        $this->grantGuard->assertActive($this->grantContext);
    }

    public function createSubscription(GraphSubscriptionConfiguration $configuration, \DateTimeImmutable $expiresAt): GraphSubscriptionRegistration
    {
        return $this->subscriptions()->create($configuration, $expiresAt);
    }

    public function renewSubscription(GraphSubscriptionRegistration $registration, \DateTimeImmutable $expiresAt): GraphSubscriptionRegistration
    {
        return $this->subscriptions()->renew($registration, $expiresAt);
    }

    public function removeSubscription(GraphSubscriptionRegistration $registration): GraphSubscriptionRegistration
    {
        return $this->subscriptions()->remove($registration);
    }

    public function subscriptionHandshake(#[\SensitiveParameter] RequestInterface $request, GraphSubscriptionConfiguration $configuration): ResponseInterface
    {
        return $this->subscriptions()->handshake($request, $configuration);
    }

    /**
     * @param  list<GraphSubscriptionRegistration>  $registrations
     * @return list<GraphChangeHint>
     */
    public function subscriptionNotifications(#[\SensitiveParameter] RequestInterface $request, array $registrations, int $maxRequestBytes = 65_536): array
    {
        return $this->subscriptions()->notifications($request, $registrations, $maxRequestBytes);
    }

    private function subscriptions(): MsGraphSubscriptions
    {
        if ($this->grantContext === null || $this->grantGuard === null) {
            throw new UnsupportedCapabilityException('Graph subscriptions require an explicit grant context and lifecycle guard.');
        }

        return new MsGraphSubscriptions($this->client, $this->grantContext, $this->grantGuard);
    }

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

    public function inspectAttachments(SubmissionPreparation $preparation, PreparedMessage $message, ?SubmissionLimits $limits = null): SubmissionPreparation
    {
        return $this->draftSubmission()->inspectAttachments($preparation, $message, $limits ?? new SubmissionLimits);
    }

    private function draftSubmission(): MsGraphDraftSubmission
    {
        if ($this->grantContext === null) {
            throw new UnsupportedCapabilityException('Draft submission requires an explicit grant context.');
        }

        return new MsGraphDraftSubmission($this->client, $this->grantContext);
    }

    public function initialSync(SyncScope $scope, SyncBudget $budget, #[\SensitiveParameter] ?SyncContinuation $continuation = null): ChangePage
    {
        return $this->boundedSync()->page($scope, $budget, null, $continuation);
    }

    public function incrementalSync(SyncScope $scope, SyncBudget $budget, #[\SensitiveParameter] SyncCheckpoint $checkpoint, #[\SensitiveParameter] ?SyncContinuation $continuation = null): ChangePage
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

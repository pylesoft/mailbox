<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\Persistence;

use Carbon\Carbon;
use Closure;
use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\SupportsExactMessageRead;
use Pyle\Mailbox\Contracts\SupportsMessageQueryResults;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\DTOs\MessageQueryResult;
use Pyle\Mailbox\DTOs\MessageSyncResult;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\MatchOperator;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Facades\Mailbox as MailboxFacade;
use Pyle\Mailbox\Models\Mailbox;
use Pyle\Mailbox\Models\MailboxMessage;
use Pyle\Mailbox\Support\MessageMatcher;
use Pyle\Mailbox\Support\ProviderMessageId;
use RuntimeException;

class MessageSyncService
{
    public function __construct(
        private readonly MessageSyncRuleTree $ruleTree = new MessageSyncRuleTree,
        private readonly MailboxMessagePersister $persister = new MailboxMessagePersister,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return Collection<int, MailboxMessage>
     */
    public function syncMailbox(Mailbox $mailbox, array $options = []): Collection
    {
        return $this->sync($mailbox, $options, false, null)->messages;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  Closure(Closure(): mixed): mixed|null  $guardedWrite
     */
    public function syncMailboxResult(Mailbox $mailbox, array $options = [], ?Closure $guardedWrite = null): MessageSyncResult
    {
        return $this->sync($mailbox, $options, true, $guardedWrite);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  Closure(Closure(): mixed): mixed|null  $guardedWrite
     */
    private function sync(Mailbox $mailbox, array $options, bool $isolateHeaders, ?Closure $guardedWrite): MessageSyncResult
    {
        $request = MessageSyncRequest::from($options, $this->ruleTree);
        $this->ensureMailboxHasDriver($mailbox);
        $plan = $this->buildPlan($request, Carbon::now('UTC'));
        $mailboxResource = MailboxFacade::forMailbox($mailbox);
        $outcome = $this->fetchMessages($mailboxResource, $plan, $isolateHeaders);
        $messages = $outcome->messages->sortBy(fn (MessageDto $message): int => $message->receivedAt?->getTimestamp() ?? 0)->values();
        $persisted = $this->persistMessages($mailboxResource, $mailbox->id, $messages, $plan, $guardedWrite);
        if ($outcome->rejections->isEmpty()) {
            $write = fn (): bool => $mailbox->update(['last_synced_at' => now()]);
            $guardedWrite === null ? $write() : $guardedWrite($write);
        }

        return new MessageSyncResult(
            messages: $persisted->pluck('message')->unique('id')->sortBy('received_at')->values(),
            persisted: $persisted,
            rejections: $outcome->rejections,
            excludedProviderMessageIds: $messages->pluck('id')->diff($persisted->pluck('providerMessageId'))->values(),
            exhausted: $outcome->exhausted,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  Closure(Closure(): mixed): mixed|null  $guardedWrite
     */
    public function syncMailboxMessage(Mailbox $mailbox, string $providerMessageId, array $options = [], ?Closure $guardedWrite = null): ?MailboxMessage
    {
        if (! ProviderMessageId::isValid($providerMessageId)) {
            throw new MailboxException('A valid provider message identity is required.');
        }
        $request = MessageSyncRequest::from($options, $this->ruleTree);
        $this->ensureMailboxHasDriver($mailbox);
        $plan = $this->buildPlan($request, Carbon::now('UTC'));
        $resource = MailboxFacade::forMailbox($mailbox);
        $messageResource = $resource->message($providerMessageId);
        if (! $messageResource instanceof SupportsExactMessageRead) {
            throw new UnsupportedCapabilityException('Targeted recovery requires exact message reads.');
        }
        $message = $messageResource->getExact();
        if ($message->id !== $providerMessageId) {
            throw new MailboxException('Provider returned a mismatched message identity.');
        }
        if (! $this->matchesFolder($resource, $plan, $message, (string) $mailbox->connection->driver)) {
            return null;
        }
        $filters = $plan->filters();
        if (empty($request->filters()['received_after'])) {
            unset($filters['received_after']);
        }
        foreach (['received_after', 'received_before'] as $key) {
            if (! empty($filters[$key])) {
                $date = Carbon::parse((string) $filters[$key])->utc();
                if ($message->receivedAt === null || ($key === 'received_after' ? $message->receivedAt->lt($date) : $message->receivedAt->gt($date))) {
                    return null;
                }
            }
        }
        if (! empty($filters['internet_message_id'])) {
            $id = trim((string) $filters['internet_message_id']);
            $id = str_starts_with($id, '<') ? $id : "<{$id}>";
            if ($message->internetMessageId !== $id) {
                return null;
            }
        }
        $targetPlan = new MessageSyncPlan(
            filters: $filters,
            ruleTree: $plan->ruleTree(),
            folderReference: $plan->folderReference(),
            matcher: $this->buildMatcher($filters, $plan->ruleTree(), true),
            requiresAttachmentMetadata: $plan->requiresAttachmentMetadata(),
            persistAttachments: $plan->persistAttachments(),
        );

        return $this->persistMessages($resource, $mailbox->id, collect([$message]), $targetPlan, $guardedWrite)->first()['message'] ?? null;
    }

    private function matchesFolder(MailboxResource $resource, MessageSyncPlan $plan, MessageDto $message, string $driver): bool
    {
        $folder = $plan->folderReference();
        $folderId = null;
        if ($folder !== null) {
            $folderId = $resource->folder($folder)->get()->id;
            if (trim($folderId) === '') {
                throw new MailboxException('Provider returned an invalid folder identity.');
            }
        }
        if (in_array($driver, ['gmail', 'google-workspace'], true)) {
            $labels = $message->raw['labelIds'] ?? null;
            if (! is_array($labels) || ! array_is_list($labels)) {
                throw new MailboxException('Gmail returned invalid folder membership.');
            }
            foreach ($labels as $label) {
                if (! is_string($label) || trim($label) === '') {
                    throw new MailboxException('Gmail returned invalid folder membership.');
                }
            }

            if (in_array('SPAM', $labels, true) || in_array('TRASH', $labels, true)) {
                return false;
            }

            return $folder === null || in_array($folderId, $labels, true);
        }
        if ($folder === null) {
            return true;
        }
        if ($message->parentFolderId === null || trim($message->parentFolderId) === '') {
            throw new MailboxException('Provider returned invalid folder membership.');
        }

        return $message->parentFolderId === $folderId;
    }

    private function ensureMailboxHasDriver(Mailbox $mailbox): void
    {
        $mailbox->loadMissing('connection');

        $driver = trim((string) $mailbox->connection->driver);

        if ($driver === '') {
            throw new RuntimeException('Mailbox connection driver is required.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildQueryFilters(MessageSyncRequest $request, Carbon $referenceTime): array
    {
        $savedFilters = $request->filters();
        $ruleTree = $request->ruleTree();

        if ($this->requiresAttachmentFilter($savedFilters, $ruleTree) && ! isset($savedFilters['has_attachments'])) {
            $savedFilters['has_attachments'] = true;
        }

        return [
            ...$savedFilters,
            'page_size' => $savedFilters['page_size'] ?? 25,
            'limit' => $savedFilters['limit'] ?? 100,
            'received_before' => $savedFilters['received_before'] ?? $referenceTime->toIso8601String(),
            'received_after' => $this->receivedAfterFilter($savedFilters, $referenceTime),
        ];
    }

    /**
     * @param  array<string, mixed>  $savedFilters
     * @param  array<string, mixed>  $ruleTree
     */
    private function requiresAttachmentFilter(array $savedFilters, array $ruleTree): bool
    {
        return ! empty($savedFilters['attachment_name_prefix'])
            || $this->ruleTree->requiresHasAttachmentsTrue($ruleTree);
    }

    /**
     * @param  array<string, mixed>  $savedFilters
     */
    private function receivedAfterFilter(array $savedFilters, Carbon $referenceTime): string
    {
        if (! empty($savedFilters['received_after'])) {
            return (string) $savedFilters['received_after'];
        }

        if (! empty($savedFilters['lookback_hours']) && is_numeric($savedFilters['lookback_hours'])) {
            return $referenceTime->copy()->subHours((int) $savedFilters['lookback_hours'])->toIso8601String();
        }

        return $referenceTime->copy()->subHours(6)->toIso8601String();
    }

    private function fetchMessages(MailboxResource $mailbox, MessageSyncPlan $plan, bool $isolateHeaders): MessageQueryResult
    {
        $folder = $plan->folderReference();
        $query = $folder === null ? $mailbox->messages()->allFolders() : $mailbox->messages()->inFolder($folder);
        $this->applyFilters($query, $plan);

        if ($isolateHeaders && $query instanceof SupportsMessageQueryResults) {
            return $query->getResult();
        }

        return new MessageQueryResult($query->get(), collect(), false);
    }

    private function applyFilters(MessageQueryBuilder $query, MessageSyncPlan $plan): void
    {
        $filters = $plan->filters();

        if (! empty($filters['received_after'])) {
            $query->where(FilterableField::RECEIVED_AT, 'ge', Carbon::parse((string) $filters['received_after'])->utc());
        }

        if (! empty($filters['received_before'])) {
            $query->where(FilterableField::RECEIVED_AT, 'le', Carbon::parse((string) $filters['received_before'])->utc());
        }

        if (! empty($filters['internet_message_id'])) {
            $messageId = trim((string) $filters['internet_message_id']);
            if ($messageId !== '' && ! str_starts_with($messageId, '<')) {
                $messageId = "<{$messageId}>";
            }

            $query->where('internetMessageId', 'eq', $messageId);
        }

        $fromAddresses = $this->normalizeStringArray($filters['from_email_addresses'] ?? null);
        if ($fromAddresses !== []) {
            if (count($fromAddresses) === 1) {
                $query->where(FilterableField::FROM_ADDRESS, 'eq', $fromAddresses[0]);
            } else {
                $query->whereAny(FilterableField::FROM_ADDRESS, 'eq', $fromAddresses);
            }
        }

        $subjectContains = $this->normalizeStringArray($filters['subject_contains'] ?? null);
        if ($subjectContains !== []) {
            if (count($subjectContains) === 1) {
                $query->where(FilterableField::SUBJECT, 'contains', $subjectContains[0]);
            } else {
                $query->whereAny(FilterableField::SUBJECT, 'contains', $subjectContains);
            }
        }

        if (isset($filters['has_attachments'])) {
            $query->where(FilterableField::HAS_ATTACHMENTS, 'eq', (bool) $filters['has_attachments']);
        }

        if (! empty($filters['importance'])) {
            $query->where(FilterableField::IMPORTANCE, 'eq', (string) $filters['importance']);
        }

        if (isset($filters['is_read'])) {
            $query->where(FilterableField::IS_READ, 'eq', (bool) $filters['is_read']);
        }

        $pageSize = isset($filters['page_size']) ? (int) $filters['page_size'] : 0;
        if ($pageSize > 0) {
            $query->pageSize($pageSize);
        }

        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 0;
        if ($limit > 0) {
            $query->take($limit);
        }

        $this->ruleTree->applyPushdown($query, $plan->ruleTree());
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): string => trim((string) $item),
            $value,
        ), fn (string $item): bool => $item !== ''));
    }

    /**
     * @param  Collection<int, MessageDto>  $messages
     * @param  Closure(Closure(): mixed): mixed|null  $guardedWrite
     * @return Collection<int, array{providerMessageId: string, message: MailboxMessage}>
     */
    private function persistMessages(
        MailboxResource $mailboxResource,
        int $mailboxId,
        Collection $messages,
        MessageSyncPlan $plan,
        ?Closure $guardedWrite = null,
    ): Collection {
        $persisted = collect();

        foreach ($messages as $message) {
            $messageResource = null;
            $attachments = collect();

            if ($plan->requiresAttachmentMetadata() && $message->hasAttachments) {
                $messageResource = $mailboxResource->message($message->id);
                $attachments = $messageResource->attachments();
            }

            $matcher = $plan->matcher();

            if ($matcher instanceof MessageMatcher && ! $matcher->matches($message, $attachments)) {
                continue;
            }

            $persisted->push(['providerMessageId' => $message->id, 'message' => $this->persister->upsert(
                $mailboxResource,
                $mailboxId,
                $message,
                $messageResource,
                $plan->requiresAttachmentMetadata() ? $attachments : null,
                $plan->persistAttachments(),
                $guardedWrite,
            )]);
        }

        return $persisted;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $ruleTree
     */
    private function buildMatcher(array $filters, array $ruleTree, bool $includeFilters = false): ?MessageMatcher
    {
        if ($ruleTree !== [] && ! $includeFilters) {
            return new MessageMatcher($ruleTree);
        }

        $conditions = $ruleTree === [] ? [] : [$ruleTree];

        $fromAddresses = $this->normalizeStringArray($filters['from_email_addresses'] ?? null);
        if ($fromAddresses !== []) {
            if (count($fromAddresses) === 1) {
                $conditions[] = [
                    'field' => FilterableField::FROM_ADDRESS->value,
                    'operator' => MatchOperator::EQUALS->value,
                    'value' => $fromAddresses[0],
                ];
            } else {
                $conditions[] = [
                    'operator' => 'OR',
                    'conditions' => array_map(
                        fn (string $address): array => [
                            'field' => FilterableField::FROM_ADDRESS->value,
                            'operator' => MatchOperator::EQUALS->value,
                            'value' => $address,
                        ],
                        $fromAddresses,
                    ),
                ];
            }
        }

        $subjectContains = $this->normalizeStringArray($filters['subject_contains'] ?? null);
        if ($subjectContains !== []) {
            if (count($subjectContains) === 1) {
                $conditions[] = [
                    'field' => FilterableField::SUBJECT->value,
                    'operator' => MatchOperator::CONTAINS->value,
                    'value' => $subjectContains[0],
                ];
            } else {
                $conditions[] = [
                    'operator' => 'OR',
                    'conditions' => array_map(
                        fn (string $value): array => [
                            'field' => FilterableField::SUBJECT->value,
                            'operator' => MatchOperator::CONTAINS->value,
                            'value' => $value,
                        ],
                        $subjectContains,
                    ),
                ];
            }
        }

        if (isset($filters['has_attachments'])) {
            $conditions[] = [
                'field' => FilterableField::HAS_ATTACHMENTS->value,
                'operator' => MatchOperator::EQUALS->value,
                'value' => (bool) $filters['has_attachments'],
            ];
        }

        if (isset($filters['is_read'])) {
            $conditions[] = [
                'field' => FilterableField::IS_READ->value,
                'operator' => MatchOperator::EQUALS->value,
                'value' => (bool) $filters['is_read'],
            ];
        }

        if (! empty($filters['importance'])) {
            $conditions[] = [
                'field' => FilterableField::IMPORTANCE->value,
                'operator' => MatchOperator::EQUALS->value,
                'value' => (string) $filters['importance'],
            ];
        }

        $namePrefix = trim((string) ($filters['attachment_name_prefix'] ?? ''));
        if ($namePrefix !== '') {
            $conditions[] = [
                'field' => FilterableField::ATTACHMENT_NAME->value,
                'operator' => MatchOperator::STARTS_WITH->value,
                'value' => $namePrefix,
            ];
        }

        if ($conditions === []) {
            return null;
        }

        return new MessageMatcher([
            'operator' => 'AND',
            'conditions' => $conditions,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $ruleTree
     */
    private function requiresAttachmentMetadata(array $filters, array $ruleTree): bool
    {
        if ($this->ruleTree->requiresAttachmentMetadata($ruleTree)) {
            return true;
        }

        return trim((string) ($filters['attachment_name_prefix'] ?? '')) !== '';
    }

    private function buildPlan(MessageSyncRequest $request, Carbon $referenceTime): MessageSyncPlan
    {
        $filters = $this->buildQueryFilters($request, $referenceTime);
        $ruleTree = $request->ruleTree();

        return new MessageSyncPlan(
            filters: $filters,
            ruleTree: $ruleTree,
            folderReference: $this->parseFolderReference($request->folderReference()),
            matcher: $this->buildMatcher($filters, $ruleTree),
            requiresAttachmentMetadata: $this->requiresAttachmentMetadata($filters, $ruleTree),
            persistAttachments: $request->persistAttachments(),
        );
    }

    private function parseFolderReference(mixed $reference): string|WellKnownFolder|null
    {
        if (! is_string($reference)) {
            return null;
        }

        $trimmed = trim($reference);
        if ($trimmed === '') {
            return null;
        }

        $normalized = strtolower($trimmed);
        $wellKnown = WellKnownFolder::tryFrom($normalized);
        if ($wellKnown instanceof WellKnownFolder) {
            return $wellKnown;
        }

        if (! str_starts_with($normalized, 'wk:')) {
            return $trimmed;
        }

        $wellKnown = WellKnownFolder::tryFrom(substr($normalized, 3));

        return $wellKnown ?? $trimmed;
    }
}

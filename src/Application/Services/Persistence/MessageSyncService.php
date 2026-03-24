<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\Persistence;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\MatchOperator;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Facades\Mailbox as MailboxFacade;
use Pyle\Mailbox\Models\Mailbox;
use Pyle\Mailbox\Models\MailboxMessage;
use Pyle\Mailbox\Support\MessageMatcher;
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
        $driver = $this->driverForMailbox($mailbox);
        $savedFilters = $this->savedFilters($options);
        $ruleTree = $this->ruleTree->extract($options['rule_tree'] ?? null, $savedFilters['rule_tree'] ?? null);
        unset($savedFilters['rule_tree']);

        $filters = $this->buildSyncFilters($options, $savedFilters, $ruleTree, Carbon::now('UTC'));
        $mailboxResource = MailboxFacade::forMailbox($mailbox);
        $matcher = $this->buildMatcher($savedFilters, $ruleTree);
        $requiresAttachmentMetadata = $this->requiresAttachmentMetadata($savedFilters, $ruleTree);
        $persistAttachments = ! array_key_exists('include_attachments', $options)
            || (bool) $options['include_attachments'];
        $messages = $this->messagesForSync($mailboxResource, $filters, $ruleTree, $driver);
        $persisted = $this->persistMessages(
            $mailboxResource,
            $mailbox->id,
            $messages,
            $matcher,
            $requiresAttachmentMetadata,
            $persistAttachments,
        );

        $mailbox->update(['last_synced_at' => now()]);

        return $persisted
            ->unique('id')
            ->sortBy('received_at')
            ->values();
    }

    private function driverForMailbox(Mailbox $mailbox): string
    {
        $mailbox->loadMissing('connection');

        $driver = trim((string) $mailbox->connection->driver);

        if ($driver === '') {
            throw new RuntimeException('Mailbox connection driver is required.');
        }

        return $driver;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function savedFilters(array $options): array
    {
        return isset($options['filters']) && is_array($options['filters'])
            ? $options['filters']
            : [];
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $savedFilters
     * @param  array<string, mixed>  $ruleTree
     * @return array<string, mixed>
     */
    private function buildSyncFilters(array $options, array $savedFilters, array $ruleTree, Carbon $referenceTime): array
    {
        if ($this->requiresAttachmentFilter($savedFilters, $ruleTree) && ! isset($savedFilters['has_attachments'])) {
            $savedFilters['has_attachments'] = true;
        }

        $filters = [
            ...$savedFilters,
            'page_size' => $savedFilters['page_size'] ?? 25,
            'limit' => $savedFilters['limit'] ?? 100,
            'received_before' => $savedFilters['received_before'] ?? $referenceTime->toIso8601String(),
            'received_after' => $this->receivedAfterFilter($savedFilters, $referenceTime),
        ];

        $folderReference = $this->folderReference($options);

        if ($folderReference !== null && $folderReference !== '') {
            $filters['mail_folder_id'] = $this->normalizeStoredFolderReference($folderReference);
        }

        return $filters;
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
     * @param  array<string, mixed>  $options
     */
    private function folderReference(array $options): ?string
    {
        if (isset($options['folder']) && is_string($options['folder'])) {
            return $options['folder'];
        }

        if (isset($options['folder_reference']) && is_string($options['folder_reference'])) {
            return $options['folder_reference'];
        }

        return null;
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

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $ruleTree
     * @return Collection<int, MessageDto>
     */
    private function fetchMessages(MailboxResource $mailbox, array $filters, array $ruleTree, string $driver): Collection
    {
        $folderReference = $this->parseFolderReference($filters['mail_folder_id'] ?? null);

        if ($folderReference !== null) {
            return $this->fetchUsingQuery($mailbox->messages()->inFolder($folderReference), $filters, $ruleTree, $driver);
        }

        return $this->fetchUsingQuery($mailbox->messages()->allFolders(), $filters, $ruleTree, $driver);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $ruleTree
     * @return Collection<int, MessageDto>
     */
    private function fetchUsingQuery(MessageQueryBuilder $query, array $filters, array $ruleTree, string $driver): Collection
    {
        $this->applyFilters($query, $filters, $ruleTree, $driver);

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $ruleTree
     * @return Collection<int, MessageDto>
     */
    private function messagesForSync(
        MailboxResource $mailboxResource,
        array $filters,
        array $ruleTree,
        string $driver,
    ): Collection {
        return $this->fetchMessages($mailboxResource, $filters, $ruleTree, $driver)
            ->sortBy(fn (MessageDto $message): int => $message->receivedAt?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $ruleTree
     */
    private function applyFilters(MessageQueryBuilder $query, array $filters, array $ruleTree, string $driver): void
    {
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

        $this->ruleTree->applyPushdown($query, $ruleTree, $driver);
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
     * @return Collection<int, MailboxMessage>
     */
    private function persistMessages(
        MailboxResource $mailboxResource,
        int $mailboxId,
        Collection $messages,
        ?MessageMatcher $matcher,
        bool $requiresAttachmentMetadata,
        bool $persistAttachments,
    ): Collection {
        $persisted = collect();

        foreach ($messages as $message) {
            $messageResource = null;
            $attachments = collect();

            if ($requiresAttachmentMetadata && $message->hasAttachments) {
                $messageResource = $mailboxResource->message($message->id);
                $attachments = $messageResource->attachments();
            }

            if ($matcher instanceof MessageMatcher && ! $matcher->matches($message, $attachments)) {
                continue;
            }

            $persisted->push($this->persister->upsert(
                $mailboxResource,
                $mailboxId,
                $message,
                $messageResource,
                $requiresAttachmentMetadata ? $attachments : null,
                $persistAttachments,
            ));
        }

        return $persisted;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $ruleTree
     */
    private function buildMatcher(array $filters, array $ruleTree): ?MessageMatcher
    {
        if ($ruleTree !== []) {
            return new MessageMatcher($ruleTree);
        }

        $conditions = [];

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

    private function normalizeStoredFolderReference(string $reference): string
    {
        $trimmed = trim($reference);
        if ($trimmed === '') {
            return $trimmed;
        }

        $normalized = strtolower($trimmed);
        if ($normalized === 'inbox' || $normalized === 'wk:inbox') {
            return WellKnownFolder::INBOX->value;
        }

        if (str_starts_with($normalized, 'wk:')) {
            $wellKnown = substr($normalized, 3);

            return $wellKnown !== '' ? $wellKnown : $trimmed;
        }

        return $trimmed;
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

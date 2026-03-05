<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\Persistence;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\EmailAddressDto;
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
    /**
     * @param  array<string, mixed>  $options
     * @return Collection<int, MailboxMessage>
     */
    public function syncMailbox(Mailbox $mailbox, array $options = []): Collection
    {
        $mailbox->loadMissing('connection');
        $driver = trim((string) $mailbox->connection->driver);

        if ($driver === '') {
            throw new RuntimeException('Mailbox connection driver is required.');
        }

        $savedFilters = isset($options['filters']) && is_array($options['filters']) ? $options['filters'] : [];
        $ruleTree = $this->extractRuleTree($options['rule_tree'] ?? null, $savedFilters['rule_tree'] ?? null);
        unset($savedFilters['rule_tree']);

        $folderReference = null;
        if (isset($options['folder']) && is_string($options['folder'])) {
            $folderReference = $options['folder'];
        } elseif (isset($options['folder_reference']) && is_string($options['folder_reference'])) {
            $folderReference = $options['folder_reference'];
        }

        $referenceTime = Carbon::now('UTC');
        $hasAttachmentNamePrefix = ! empty($savedFilters['attachment_name_prefix']);
        $hasAttachmentFilters = $hasAttachmentNamePrefix;

        if ($hasAttachmentFilters && ! isset($savedFilters['has_attachments'])) {
            $savedFilters['has_attachments'] = true;
        }

        $filters = [
            ...$savedFilters,
            'page_size' => $savedFilters['page_size'] ?? 25,
            'limit' => $savedFilters['limit'] ?? 100,
            'received_before' => $savedFilters['received_before'] ?? $referenceTime->toIso8601String(),
        ];

        if (! empty($savedFilters['received_after'])) {
            $filters['received_after'] = $savedFilters['received_after'];
        } elseif (! empty($savedFilters['lookback_hours']) && is_numeric($savedFilters['lookback_hours'])) {
            $filters['received_after'] = $referenceTime->copy()->subHours((int) $savedFilters['lookback_hours'])->toIso8601String();
        } else {
            $filters['received_after'] = $referenceTime->copy()->subHours(6)->toIso8601String();
        }

        if ($folderReference !== null && $folderReference !== '') {
            $filters['mail_folder_id'] = $this->normalizeStoredFolderReference($folderReference);
        }

        $mailboxResource = MailboxFacade::forMailbox($mailbox);
        $matcher = $this->buildMatcher($savedFilters, $ruleTree);
        $requiresAttachmentMetadata = $this->requiresAttachmentMetadata($savedFilters, $ruleTree);
        $persistAttachments = ! array_key_exists('include_attachments', $options)
            || (bool) $options['include_attachments'];
        $messages = $this->fetchMessages($mailboxResource, $filters, $ruleTree, $driver)
            ->sortBy(fn (MessageDto $message): int => $message->receivedAt?->getTimestamp() ?? 0)
            ->values();

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

            $persisted->push($this->upsertMessage(
                $mailboxResource,
                $mailbox->id,
                $message,
                $messageResource,
                $requiresAttachmentMetadata ? $attachments : null,
                $persistAttachments,
            ));
        }

        $mailbox->update(['last_synced_at' => now()]);

        return $persisted
            ->unique('id')
            ->sortBy('received_at')
            ->values();
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

        $this->applyRuleTreePushdown($query, $ruleTree, $driver);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(fn (mixed $item): string => trim((string) $item), $value), fn (string $item): bool => $item !== ''));
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
        if ($this->ruleTreeRequiresAttachmentMetadata($ruleTree)) {
            return true;
        }

        return trim((string) ($filters['attachment_name_prefix'] ?? '')) !== '';
    }

    /**
     * @param  Collection<int, AttachmentDto>|null  $prefetchedAttachments
     */
    private function upsertMessage(
        MailboxResource $mailboxResource,
        int $mailboxId,
        MessageDto $message,
        ?MessageResource $resource = null,
        ?Collection $prefetchedAttachments = null,
        bool $persistAttachments = true,
    ): MailboxMessage {
        $mailboxMessage = MailboxMessage::query()->updateOrCreate(
            [
                'mailbox_id' => $mailboxId,
                'canonical_message_key' => $this->canonicalMessageKey($message),
            ],
            [
                'provider_message_id' => $message->id,
                'internet_message_id' => $message->internetMessageId,
                'parent_folder_id' => $message->parentFolderId,
                'subject' => $message->subject,
                'body' => $message->body?->toArray(),
                'body_preview' => $message->bodyPreview,
                'from_address' => $this->normalizeAddress($message->from),
                'sender' => $this->normalizeAddress($message->sender),
                'to_recipients' => $this->normalizeAddressList($message->toRecipients),
                'cc_recipients' => $this->normalizeAddressList($message->ccRecipients),
                'bcc_recipients' => $this->normalizeAddressList($message->bccRecipients),
                'received_at' => $message->receivedAt,
                'sent_at' => $message->sentAt,
                'is_read' => $message->isRead,
                'is_draft' => $message->isDraft,
                'has_attachments' => $message->hasAttachments,
                'importance' => $message->importance->value,
                'conversation_id' => $message->conversationId,
                'raw_payload' => $message->raw,
            ],
        );

        if (! $persistAttachments) {
            return $mailboxMessage->fresh(['attachments']) ?? $mailboxMessage;
        }

        if (! $message->hasAttachments) {
            $mailboxMessage->attachments()->delete();

            return $mailboxMessage->fresh(['attachments']) ?? $mailboxMessage;
        }

        $resource ??= $mailboxResource->message($message->id);
        $attachments = $prefetchedAttachments ?? $resource->attachments();
        $persistedAttachmentIds = [];

        foreach ($attachments as $attachment) {
            if ($attachment->id === '') {
                continue;
            }

            $persistedAttachmentIds[] = $attachment->id;
            $content = (string) $resource->attachment($attachment->id)->stream();

            $mailboxMessage->attachments()->updateOrCreate(
                [
                    'mailbox_message_id' => $mailboxMessage->id,
                    'provider_attachment_id' => $attachment->id,
                ],
                [
                    'name' => $attachment->name,
                    'content_type' => $attachment->contentType,
                    'size' => $attachment->size,
                    'is_inline' => $attachment->isInline,
                    'content_id' => $attachment->contentId,
                    'content_bytes' => base64_encode($content),
                ],
            );
        }

        if ($persistedAttachmentIds === []) {
            $mailboxMessage->attachments()->delete();
        } else {
            $mailboxMessage->attachments()
                ->whereNotIn('provider_attachment_id', array_values(array_unique($persistedAttachmentIds)))
                ->delete();
        }

        return $mailboxMessage->fresh(['attachments']) ?? $mailboxMessage;
    }

    private function canonicalMessageKey(MessageDto $message): string
    {
        $internetMessageId = trim((string) ($message->internetMessageId ?? ''));
        if ($internetMessageId !== '') {
            return "internet:{$internetMessageId}";
        }

        return "provider:{$message->id}";
    }

    /**
     * @return array{name: string, address: string}|null
     */
    private function normalizeAddress(?EmailAddressDto $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return ['name' => $address->name, 'address' => $address->address];
    }

    /**
     * @param  array<int, EmailAddressDto>  $addresses
     * @return array<int, array{name: string, address: string}>
     */
    private function normalizeAddressList(array $addresses): array
    {
        return array_values(array_map(fn (EmailAddressDto $address): array => [
            'name' => $address->name,
            'address' => $address->address,
        ], $addresses));
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

    /**
     * @return array<string, mixed>
     */
    private function extractRuleTree(mixed $runtimeRuleTree, mixed $storedRuleTree): array
    {
        if ($this->isRuleTree($runtimeRuleTree)) {
            return $this->normalizeRuleTree($runtimeRuleTree);
        }

        if ($this->isRuleTree($storedRuleTree)) {
            return $this->normalizeRuleTree($storedRuleTree);
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $ruleTree
     */
    private function ruleTreeRequiresAttachmentMetadata(array $ruleTree): bool
    {
        foreach ($this->collectRuleTreeFields($ruleTree) as $field) {
            if (str_starts_with($field, 'attachment')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $ruleTree
     * @return array<int, string>
     */
    private function collectRuleTreeFields(array $ruleTree): array
    {
        $conditions = $ruleTree['conditions'] ?? null;

        if (! is_array($conditions)) {
            return [];
        }

        $fields = [];

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            if (isset($condition['conditions']) && is_array($condition['conditions'])) {
                $fields = [...$fields, ...$this->collectRuleTreeFields($condition)];

                continue;
            }

            $field = trim((string) ($condition['field'] ?? ''));
            if ($field !== '') {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $ruleTree
     */
    private function applyRuleTreePushdown(MessageQueryBuilder $query, array $ruleTree, string $driver): void
    {
        if ($ruleTree === []) {
            return;
        }

        $conditions = $this->collectAndOnlyConditions($ruleTree);

        if ($conditions === null || $conditions === []) {
            return;
        }

        foreach ($conditions as $condition) {
            $field = trim((string) ($condition['field'] ?? ''));
            $operator = trim((string) ($condition['operator'] ?? ''));
            $value = $condition['value'] ?? null;

            $filterableField = FilterableField::tryFrom($field);
            $matchOperator = MatchOperator::tryFrom($operator);

            if (! $filterableField instanceof FilterableField || ! $matchOperator instanceof MatchOperator) {
                continue;
            }

            if (! $filterableField->isServerPushable($driver)) {
                continue;
            }

            if (! in_array($matchOperator, $filterableField->operators(), true)) {
                continue;
            }

            if ($matchOperator === MatchOperator::BETWEEN && is_array($value) && count($value) === 2) {
                [$min, $max] = array_values($value);
                $query->where($field, 'ge', $min);
                $query->where($field, 'le', $max);

                continue;
            }

            $providerOperator = match ($matchOperator) {
                MatchOperator::EQUALS => 'eq',
                MatchOperator::CONTAINS => 'contains',
                MatchOperator::STARTS_WITH => 'starts_with',
                MatchOperator::ENDS_WITH => 'ends_with',
                MatchOperator::GREATER_THAN => 'gt',
                MatchOperator::LESS_THAN => 'lt',
                MatchOperator::BEFORE => 'lt',
                MatchOperator::AFTER => 'gt',
                default => null,
            };

            if (! is_string($providerOperator)) {
                continue;
            }

            $query->where($field, $providerOperator, $value);
        }
    }

    private function isRuleTree(mixed $value): bool
    {
        return is_array($value)
            && isset($value['operator'])
            && is_array($value['conditions'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $ruleTree
     * @return array<string, mixed>
     */
    private function normalizeRuleTree(array $ruleTree): array
    {
        $operator = strtoupper((string) ($ruleTree['operator'] ?? 'AND'));
        $conditions = $ruleTree['conditions'] ?? [];

        if (! is_array($conditions)) {
            $conditions = [];
        }

        $normalizedConditions = [];

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            if (isset($condition['conditions']) && is_array($condition['conditions'])) {
                $normalizedConditions[] = $this->normalizeRuleTree($condition);

                continue;
            }

            $field = trim((string) ($condition['field'] ?? ''));
            $conditionOperator = trim((string) ($condition['operator'] ?? ''));

            if ($field === '' || $conditionOperator === '') {
                continue;
            }

            $normalizedConditions[] = [
                'field' => $field,
                'operator' => $conditionOperator,
                'value' => $condition['value'] ?? null,
            ];
        }

        return [
            'operator' => $operator === 'OR' ? 'OR' : 'AND',
            'conditions' => $normalizedConditions,
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<int, array<string, mixed>>|null
     */
    private function collectAndOnlyConditions(array $group): ?array
    {
        if (strtoupper((string) ($group['operator'] ?? 'AND')) !== 'AND') {
            return null;
        }

        $conditions = $group['conditions'] ?? [];

        if (! is_array($conditions)) {
            return null;
        }

        $flattened = [];

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                return null;
            }

            if (isset($condition['conditions']) && is_array($condition['conditions'])) {
                $subConditions = $this->collectAndOnlyConditions($condition);

                if ($subConditions === null) {
                    return null;
                }

                $flattened = [...$flattened, ...$subConditions];

                continue;
            }

            $flattened[] = $condition;
        }

        return $flattened;
    }
}

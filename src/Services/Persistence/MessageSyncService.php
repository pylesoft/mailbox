<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\Persistence;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\MatchOperator;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Models\MailboxMessage;
use Pyle\Mailbox\Models\MonitoredMailbox;
use Pyle\Mailbox\Support\MessageMatcher;

class MessageSyncService
{
    /**
     * @param  array<string, mixed>  $options
     * @return Collection<int, MailboxMessage>
     */
    public function syncMailbox(MonitoredMailbox $mailbox, array $options = []): Collection
    {
        $mailbox->loadMissing('connection');

        $savedFilters = isset($options['filters']) && is_array($options['filters']) ? $options['filters'] : [];
        $folderReference = null;
        if (isset($options['folder']) && is_string($options['folder'])) {
            $folderReference = $options['folder'];
        } elseif (isset($options['folder_reference']) && is_string($options['folder_reference'])) {
            $folderReference = $options['folder_reference'];
        }

        $referenceTime = Carbon::now('UTC');
        $hasAttachmentNamePrefix = ! empty($savedFilters['attachment_name_prefix']);
        $hasAttachmentIsPdf = ! empty($savedFilters['attachment_is_pdf']) && $savedFilters['attachment_is_pdf'] === true;
        $hasAttachmentFilters = $hasAttachmentNamePrefix || $hasAttachmentIsPdf;

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

        $mailboxResource = Mailbox::forMailbox($mailbox);
        $matcher = $this->buildMatcher($savedFilters);
        $requiresAttachmentMetadata = $this->requiresAttachmentMetadata($savedFilters);
        $messages = $this->fetchMessages($mailboxResource, $filters)
            ->sortBy(fn (MessageDto $message): int => $message->receivedAt?->getTimestamp() ?? 0)
            ->values();

        $persisted = collect();

        foreach ($messages as $message) {
            $messageResource = null;
            $attachments = collect();

            if ($requiresAttachmentMetadata) {
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
     * @return Collection<int, MessageDto>
     */
    private function fetchMessages(MailboxResource $mailbox, array $filters): Collection
    {
        $folderReference = $this->parseFolderReference($filters['mail_folder_id'] ?? null);

        if ($folderReference !== null) {
            return $this->fetchUsingQuery($mailbox->messages()->inFolder($folderReference), $filters);
        }

        $query = $mailbox->messages();
        if (method_exists($query, 'allFolders')) {
            $query->allFolders();

            return $this->fetchUsingQuery($query, $filters);
        }

        return $this->fetchAcrossFolders($mailbox, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, MessageDto>
     */
    private function fetchAcrossFolders(MailboxResource $mailbox, array $filters): Collection
    {
        $folderIds = $mailbox->folders()
            ->get()
            ->pluck('id')
            ->filter(fn (mixed $folderId): bool => is_string($folderId) && trim($folderId) !== '')
            ->map(fn (string $folderId): string => trim($folderId))
            ->unique()
            ->values();

        if ($folderIds->isEmpty()) {
            return $this->fetchUsingQuery($mailbox->messages(), $filters);
        }

        $messages = collect();
        foreach ($folderIds as $folderId) {
            $messages = $messages->concat($this->fetchUsingQuery($mailbox->messages()->inFolder($folderId), $filters));
        }

        $deduplicated = $messages
            ->unique(fn (MessageDto $message): string => $message->id !== '' ? $message->id : spl_object_hash($message))
            ->values();

        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 0;
        if ($limit > 0) {
            return $deduplicated->take($limit)->values();
        }

        return $deduplicated;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, MessageDto>
     */
    private function fetchUsingQuery(MessageQueryBuilder $query, array $filters): Collection
    {
        $this->applyFilters($query, $filters);

        return $query->get();
    }

    /** @param  array<string, mixed>  $filters */
    private function applyFilters(MessageQueryBuilder $query, array $filters): void
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
            $query->where('internetMessageId', MatchOperator::EQUALS, $messageId);
        }

        $fromAddresses = $this->normalizeStringArray($filters['from_email_addresses'] ?? null);
        if ($fromAddresses !== []) {
            if (count($fromAddresses) === 1) {
                $query->where(FilterableField::FROM_ADDRESS, MatchOperator::EQUALS, $fromAddresses[0]);
            } else {
                $query->whereAny(FilterableField::FROM_ADDRESS, MatchOperator::EQUALS, $fromAddresses);
            }
        }

        $subjectContains = $this->normalizeStringArray($filters['subject_contains'] ?? null);
        if ($subjectContains !== []) {
            if (count($subjectContains) === 1) {
                $query->where(FilterableField::SUBJECT, MatchOperator::CONTAINS, $subjectContains[0]);
            } else {
                $query->whereAny(FilterableField::SUBJECT, MatchOperator::CONTAINS, $subjectContains);
            }
        }

        if (isset($filters['has_attachments'])) {
            $query->where(FilterableField::HAS_ATTACHMENTS, MatchOperator::EQUALS, (bool) $filters['has_attachments']);
        }

        if (! empty($filters['importance'])) {
            $query->where(FilterableField::IMPORTANCE, MatchOperator::EQUALS, (string) $filters['importance']);
        }

        if (isset($filters['is_read'])) {
            $query->where(FilterableField::IS_READ, MatchOperator::EQUALS, (bool) $filters['is_read']);
        }

        $pageSize = isset($filters['page_size']) ? (int) $filters['page_size'] : 0;
        if ($pageSize > 0) {
            $query->pageSize($pageSize);
        }

        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 0;
        if ($limit > 0) {
            $query->take($limit);
        }
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

    /** @param  array<string, mixed>  $filters */
    private function buildMatcher(array $filters): ?MessageMatcher
    {
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

        if (! empty($filters['attachment_is_pdf']) && $filters['attachment_is_pdf'] === true) {
            $conditions[] = [
                'operator' => 'OR',
                'conditions' => [
                    [
                        'field' => FilterableField::ATTACHMENT_CONTENT_TYPE->value,
                        'operator' => MatchOperator::EQUALS->value,
                        'value' => 'application/pdf',
                    ],
                    [
                        'field' => FilterableField::ATTACHMENT_NAME->value,
                        'operator' => MatchOperator::ENDS_WITH->value,
                        'value' => '.pdf',
                    ],
                ],
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

    /** @param  array<string, mixed>  $filters */
    private function requiresAttachmentMetadata(array $filters): bool
    {
        return trim((string) ($filters['attachment_name_prefix'] ?? '')) !== ''
            || (! empty($filters['attachment_is_pdf']) && $filters['attachment_is_pdf'] === true);
    }

    /**
     * @param  Collection<int, AttachmentDto>|null  $prefetchedAttachments
     */
    private function upsertMessage(
        MailboxResource $mailboxResource,
        int $monitoredMailboxId,
        MessageDto $message,
        ?MessageResource $resource = null,
        ?Collection $prefetchedAttachments = null,
    ): MailboxMessage
    {
        $mailboxMessage = MailboxMessage::query()->updateOrCreate(
            [
                'monitored_mailbox_id' => $monitoredMailboxId,
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

        $resource ??= $mailboxResource->message($message->id);
        $attachments = $prefetchedAttachments ?? $resource->attachments();

        foreach ($attachments as $attachment) {
            if (! $attachment instanceof AttachmentDto || $attachment->id === '') {
                continue;
            }

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
        if ($normalized === 'inbox') {
            return 'wk:inbox';
        }

        if (str_starts_with($normalized, 'wk:')) {
            return 'wk:'.substr($normalized, 3);
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
        if ($normalized === 'inbox') {
            return WellKnownFolder::INBOX;
        }

        if (! str_starts_with($normalized, 'wk:')) {
            return $trimmed;
        }

        $wellKnown = WellKnownFolder::tryFrom(substr($normalized, 3));

        return $wellKnown ?? $trimmed;
    }
}

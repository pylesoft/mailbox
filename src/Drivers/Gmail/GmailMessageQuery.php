<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\WellKnownFolder;

class GmailMessageQuery implements MessageQueryBuilder
{
    private const MAX_PAGES = 100;

    private string $folderId = 'INBOX';

    private bool $queryAllFolders = false;

    private ?string $searchQuery = null;

    private string $orderByField = 'receivedAt';

    private string $orderDirection = 'desc';

    private ?int $limit = null;

    private ?int $pageSizeOverride = null;

    /**
     * @var array<int, array{type:'single', field:string, operator:mixed, value:mixed}|array{type:'any', field:string, operator:mixed, values:array<int, mixed>}>
     */
    private array $filters = [];

    private GmailQueryCompiler $compiler;

    private int $maxPages;

    public function __construct(
        private readonly GmailClient $client,
        private readonly string $mailbox,
    ) {
        $this->compiler = new GmailQueryCompiler;
        $this->maxPages = max(1, (int) config('mailbox.max_query_pages', self::MAX_PAGES));
    }

    public function inFolder(string|WellKnownFolder $folder): static
    {
        $this->folderId = GmailLabelResolver::resolve($folder);
        $this->queryAllFolders = false;

        return $this;
    }

    public function allFolders(): static
    {
        $this->queryAllFolders = true;

        return $this;
    }

    public function where(FilterableField|string $field, mixed $operator, mixed $value = null): static
    {
        $normalizedField = $field instanceof FilterableField ? $field->value : (string) $field;

        $this->filters[] = [
            'type' => 'single',
            'field' => $normalizedField,
            'operator' => $operator,
            'value' => $value,
        ];

        $this->compiler->where($field, $operator, $value);

        return $this;
    }

    /** @param array<int, mixed> $values */
    public function whereAny(FilterableField|string $field, mixed $operator, array $values): static
    {
        if ($values === []) {
            return $this;
        }

        $normalizedField = $field instanceof FilterableField ? $field->value : (string) $field;
        $normalizedValues = array_values($values);

        $this->filters[] = [
            'type' => 'any',
            'field' => $normalizedField,
            'operator' => $operator,
            'values' => $normalizedValues,
        ];

        $this->compiler->whereAny($field, $operator, $normalizedValues);

        return $this;
    }

    public function search(string $query): static
    {
        $this->searchQuery = trim($query);

        return $this;
    }

    /** @param array<string> $fields */
    public function select(array $fields): static
    {
        return $this;
    }

    public function orderBy(string $field, string $direction = 'desc'): static
    {
        $this->orderByField = $field;
        $this->orderDirection = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        return $this;
    }

    public function take(int $limit): static
    {
        $this->limit = max(1, $limit);

        return $this;
    }

    public function pageSize(int $size): static
    {
        $this->pageSizeOverride = max(1, $size);

        return $this;
    }

    /** @return Collection<int, MessageDto> */
    public function get(): Collection
    {
        $pageSize = $this->pageSizeOverride ?? (int) config('mailbox.default_page_size', 50);
        $serverFilter = $this->compiler->compile();
        $search = $this->searchQuery !== null && $this->searchQuery !== '' ? $this->searchQuery : null;

        $query = [
            'maxResults' => $this->limit !== null ? min($this->limit, $pageSize) : $pageSize,
        ];

        if (! $this->queryAllFolders) {
            $query['labelIds'] = [$this->folderId];
        }

        $q = trim(implode(' ', array_filter([$search, $serverFilter], static fn (?string $part): bool => is_string($part) && $part !== '')));

        if ($q !== '') {
            $query['q'] = $q;
        }

        $endpoint = sprintf('users/%s/messages', rawurlencode($this->mailbox));
        $collected = collect();
        $nextPageToken = null;
        $seenPageTokens = [];
        $seenMessageIds = [];
        $pageCount = 0;

        do {
            $tokenSignature = $nextPageToken ?? '__first__';
            if (isset($seenPageTokens[$tokenSignature])) {
                break;
            }

            if ($pageCount >= $this->maxPages) {
                break;
            }

            $seenPageTokens[$tokenSignature] = true;
            $pageCount++;

            if ($nextPageToken !== null) {
                $query['pageToken'] = $nextPageToken;
            } else {
                unset($query['pageToken']);
            }

            $response = $this->client->get($endpoint, $query, $this->mailbox);
            $summaries = (array) ($response['messages'] ?? []);

            $messages = collect($summaries)
                ->map(function (mixed $summary) use (&$seenMessageIds): ?MessageDto {
                    if (! is_array($summary)) {
                        return null;
                    }

                    $id = (string) ($summary['id'] ?? '');

                    if ($id === '') {
                        return null;
                    }

                    if (isset($seenMessageIds[$id])) {
                        return null;
                    }

                    $seenMessageIds[$id] = true;

                    $payload = $this->client->get(
                        sprintf('users/%s/messages/%s', rawurlencode($this->mailbox), rawurlencode($id)),
                        ['format' => 'full'],
                        $this->mailbox,
                    );

                    return MessageDto::fromGmail($payload);
                })
                ->filter(fn (?MessageDto $message): bool => $message instanceof MessageDto)
                ->values();

            if ($this->filters !== []) {
                $messages = $this->applyClientFilters($messages);
            }

            $collected = $collected->concat($messages);

            if ($this->limit !== null && $collected->count() >= $this->limit) {
                $collected = $collected->take($this->limit);
                break;
            }

            $nextPageToken = isset($response['nextPageToken']) && (string) $response['nextPageToken'] !== ''
                ? (string) $response['nextPageToken']
                : null;
        } while ($nextPageToken !== null);

        return $this->sortMessages($collected->values());
    }

    public function count(): int
    {
        return $this->get()->count();
    }

    public function first(): ?MessageDto
    {
        return $this->take(1)->get()->first();
    }

    /** @param array<string> $messageIds */
    public function markAsRead(array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        $this->batchModify(array_values($messageIds), [], ['UNREAD']);
    }

    /** @param array<string> $messageIds */
    public function markAsUnread(array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        $this->batchModify(array_values($messageIds), ['UNREAD'], []);
    }

    /** @param array<string> $messageIds */
    public function moveTo(string|WellKnownFolder $folder, array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        $destination = GmailLabelResolver::resolve($folder);

        $this->batchModify(
            array_values($messageIds),
            [$destination],
            $this->defaultMoveRemovals($destination, $this->folderId),
        );
    }

    /**
     * @param  Collection<int, MessageDto>  $messages
     * @return Collection<int, MessageDto>
     */
    private function applyClientFilters(Collection $messages): Collection
    {
        return $messages->filter(function (MessageDto $message): bool {
            foreach ($this->filters as $filter) {
                if ($filter['type'] === 'any') {
                    $matchesAny = false;
                    foreach ($filter['values'] as $expectedValue) {
                        if ($this->compare((string) $filter['operator'], $this->resolveActualValue($message, $filter['field']), $expectedValue)) {
                            $matchesAny = true;
                            break;
                        }
                    }

                    if (! $matchesAny) {
                        return false;
                    }

                    continue;
                }

                $operator = $filter['operator'];
                $value = $filter['value'];

                if ($value === null) {
                    $value = $operator;
                    $operator = '=';
                }

                if (! $this->compare((string) $operator, $this->resolveActualValue($message, $filter['field']), $value)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    private function resolveActualValue(MessageDto $message, string $field): mixed
    {
        return match ($field) {
            'subject' => $message->subject,
            'from.address' => $message->from?->address,
            'from.name' => $message->from?->name,
            'sender.address' => $message->sender?->address,
            'toRecipients.address' => collect($message->toRecipients)->pluck('address')->implode(','),
            'ccRecipients.address' => collect($message->ccRecipients)->pluck('address')->implode(','),
            'receivedAt' => $message->receivedAt?->toIso8601String(),
            'isRead' => $message->isRead,
            'isDraft' => $message->isDraft,
            'hasAttachments' => $message->hasAttachments,
            'importance' => $message->importance->value,
            default => $message->raw[$field] ?? null,
        };
    }

    private function compare(string $operator, mixed $actual, mixed $expected): bool
    {
        return match (strtolower($operator)) {
            '=', 'eq' => $actual == $expected,
            '!=', 'ne' => $actual != $expected,
            'contains' => str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'starts_with', 'startswith' => str_starts_with(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'ends_with', 'endswith' => str_ends_with(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            '>', 'gt', 'greater_than' => $actual > $expected,
            '<', 'lt', 'less_than' => $actual < $expected,
            '>=', 'ge' => $actual >= $expected,
            '<=', 'le' => $actual <= $expected,
            default => false,
        };
    }

    /** @param array<string> $messageIds
     * @param  array<string>  $addLabels
     * @param  array<string>  $removeLabels
     */
    private function batchModify(array $messageIds, array $addLabels, array $removeLabels): void
    {
        foreach (array_chunk($messageIds, 1000) as $chunk) {
            $this->client->post(sprintf('users/%s/messages/batchModify', rawurlencode($this->mailbox)), [
                'ids' => $chunk,
                'addLabelIds' => $addLabels,
                'removeLabelIds' => $removeLabels,
            ], $this->mailbox);
        }
    }

    /** @return array<string> */
    private function defaultMoveRemovals(string $destination, ?string $sourceLabel = null): array
    {
        $candidateRemovals = ['INBOX', 'SPAM', 'TRASH', 'SENT', 'DRAFT'];

        if (is_string($sourceLabel) && trim($sourceLabel) !== '') {
            $candidateRemovals[] = trim($sourceLabel);
        }

        return array_values(array_unique(array_filter($candidateRemovals, static fn (string $label): bool => $label !== $destination)));
    }

    /** @param Collection<int, MessageDto> $messages
     * @return Collection<int, MessageDto>
     */
    private function sortMessages(Collection $messages): Collection
    {
        $field = $this->orderByField;
        $direction = $this->orderDirection;

        $sorted = $messages->sortBy(function (MessageDto $message) use ($field): mixed {
            return match ($field) {
                'receivedAt' => $message->receivedAt?->getTimestamp() ?? 0,
                'sentAt' => $message->sentAt?->getTimestamp() ?? 0,
                'subject' => mb_strtolower($message->subject),
                default => $message->raw[$field] ?? null,
            };
        });

        if ($direction === 'desc') {
            $sorted = $sorted->reverse();
        }

        return $sorted->values();
    }
}

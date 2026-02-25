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
    private string $folderId = 'INBOX';

    private ?string $searchQuery = null;

    private string $orderByField = 'receivedAt';

    private string $orderDirection = 'desc';

    private ?int $limit = null;

    private ?int $pageSizeOverride = null;

    /** @var array<int, array{field:string, operator:mixed, value:mixed}> */
    private array $filters = [];

    private GmailQueryCompiler $compiler;

    public function __construct(
        private readonly GmailClient $client,
        private readonly string $mailbox,
    ) {
        $this->compiler = new GmailQueryCompiler;
    }

    public function inFolder(string|WellKnownFolder $folder): static
    {
        $this->folderId = GmailLabelResolver::resolve($folder);

        return $this;
    }

    public function where(FilterableField|string $field, mixed $operator, mixed $value = null): static
    {
        $normalizedField = $field instanceof FilterableField ? $field->value : (string) $field;

        $this->filters[] = [
            'field' => $normalizedField,
            'operator' => $operator,
            'value' => $value,
        ];

        $this->compiler->where($field, $operator, $value);

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
            'labelIds' => [$this->folderId],
        ];

        $q = trim(implode(' ', array_filter([$search, $serverFilter], static fn (?string $part): bool => is_string($part) && $part !== '')));

        if ($q !== '') {
            $query['q'] = $q;
        }

        $endpoint = sprintf('users/%s/messages', rawurlencode($this->mailbox));
        $collected = collect();
        $nextPageToken = null;

        do {
            if ($nextPageToken !== null) {
                $query['pageToken'] = $nextPageToken;
            }

            $response = $this->client->get($endpoint, $query, $this->mailbox);
            $summaries = (array) ($response['messages'] ?? []);

            $messages = collect($summaries)
                ->map(function (mixed $summary): ?MessageDto {
                    if (! is_array($summary)) {
                        return null;
                    }

                    $id = (string) ($summary['id'] ?? '');

                    if ($id === '') {
                        return null;
                    }

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

        $this->batchModify(array_values($messageIds), ['UNREAD'], []);
    }

    /** @param array<string> $messageIds */
    public function markAsUnread(array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        $this->batchModify(array_values($messageIds), [], ['UNREAD']);
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
            $this->defaultMoveRemovals($destination),
        );
    }

    /** @param Collection<int, MessageDto> $messages
     * @return Collection<int, MessageDto>
     */
    private function applyClientFilters(Collection $messages): Collection
    {
        return $messages->filter(function (MessageDto $message): bool {
            foreach ($this->filters as $filter) {
                $field = $filter['field'];
                $operator = $filter['operator'];
                $value = $filter['value'];

                if ($value === null) {
                    $value = $operator;
                    $operator = '=';
                }

                $actual = match ($field) {
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

                if (! $this->compare((string) $operator, $actual, $value)) {
                    return false;
                }
            }

            return true;
        })->values();
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
    private function defaultMoveRemovals(string $destination): array
    {
        $candidateRemovals = ['INBOX', 'SPAM', 'TRASH', 'SENT', 'DRAFT'];

        return array_values(array_filter($candidateRemovals, static fn (string $label): bool => $label !== $destination));
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

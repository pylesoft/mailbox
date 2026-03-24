<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Drivers\Gmail\GmailLabelResolver;
use Pyle\Mailbox\Drivers\Gmail\GmailMessageFilterer;
use Pyle\Mailbox\Drivers\Gmail\GmailMessagePageCollector;

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

    private GmailMessagePageCollector $pageCollector;

    private int $maxPages;

    public function __construct(
        private readonly GmailClient $client,
        private readonly string $mailbox,
    ) {
        $this->compiler = new GmailQueryCompiler;
        $this->pageCollector = new GmailMessagePageCollector($client, $mailbox);
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
        $messages = $this->pageCollector->collect(
            query: $this->buildListQuery(),
            maxPages: $this->maxPages,
            limit: $this->limit,
            filterer: $this->filterer(),
        );

        return $this->sortMessages($messages);
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

    /**
     * @return array<string, mixed>
     */
    private function buildListQuery(): array
    {
        $pageSize = $this->pageSizeOverride ?? (int) config('mailbox.default_page_size', 50);
        $query = [
            'maxResults' => $this->limit !== null ? min($this->limit, $pageSize) : $pageSize,
        ];

        if (! $this->queryAllFolders) {
            $query['labelIds'] = [$this->folderId];
        }

        $compiledQuery = $this->compiledQueryString();

        if ($compiledQuery !== null) {
            $query['q'] = $compiledQuery;
        }

        return $query;
    }

    private function compiledQueryString(): ?string
    {
        $search = $this->searchQuery !== null && $this->searchQuery !== '' ? $this->searchQuery : null;
        $serverFilter = $this->compiler->compile();
        $query = trim(implode(' ', array_filter(
            [$search, $serverFilter],
            static fn (?string $part): bool => is_string($part) && $part !== '',
        )));

        return $query !== '' ? $query : null;
    }

    private function filterer(): ?GmailMessageFilterer
    {
        return $this->filters === []
            ? null
            : new GmailMessageFilterer($this->filters);
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

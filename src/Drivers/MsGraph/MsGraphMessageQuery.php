<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\WellKnownFolder;

class MsGraphMessageQuery implements MessageQueryBuilder
{
    private string $folderId = 'inbox';

    private ?string $searchQuery = null;

    /** @var array<string> */
    private array $selectFields;

    private string $orderByField = 'receivedDateTime';

    private string $orderDirection = 'desc';

    private ?int $limit = null;

    private ?int $pageSizeOverride = null;

    /** @var array<int, array{field:string, operator:mixed, value:mixed}> */
    private array $filters = [];

    private ODataFilterCompiler $compiler;

    public function __construct(
        private readonly GraphClient $client,
        private readonly BatchRequest $batch,
        private readonly string $mailbox,
    ) {
        /** @var array<string> $defaultSelect */
        $defaultSelect = (array) config('mailbox.default_select', ['id', 'subject']);
        $this->selectFields = $defaultSelect;
        $this->compiler = new ODataFilterCompiler;
    }

    public function inFolder(string|WellKnownFolder $folder): static
    {
        $this->folderId = FolderIdResolver::resolve($folder);

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
        $this->selectFields = array_values(array_unique($fields));

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

        $query = [
            '$select' => implode(',', $this->selectFields),
            '$orderby' => sprintf('%s %s', $this->toProviderField($this->orderByField), $this->orderDirection),
            '$top' => $this->limit !== null ? min($this->limit, $pageSize) : $pageSize,
        ];

        if ($this->searchQuery !== null && $this->searchQuery !== '') {
            $query['$search'] = sprintf('"%s"', str_replace('"', '\\"', $this->searchQuery));
        } else {
            $filter = $this->compiler->compile();
            if ($filter !== '') {
                $query['$filter'] = $filter;
            }
        }

        $endpoint = sprintf('users/%s/mailFolders/%s/messages', rawurlencode($this->mailbox), rawurlencode($this->folderId));
        $collected = collect();

        do {
            $response = $this->client->get($endpoint, $query, $this->mailbox);

            $messages = collect((array) ($response['value'] ?? []))
                ->map(fn (mixed $item): MessageDto => MessageDto::fromMsGraph(is_array($item) ? $item : []));

            $collected = $collected->concat($messages);

            if ($this->limit !== null && $collected->count() >= $this->limit) {
                $collected = $collected->take($this->limit);
                break;
            }

            $endpoint = (string) ($response['@odata.nextLink'] ?? '');
            $query = [];
        } while ($endpoint !== '');

        if ($this->searchQuery !== null && $this->filters !== []) {
            $collected = $this->applyClientFilters($collected);
        }

        return $collected->values();
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

        $this->batch->send($this->buildPatchRequests(array_values($messageIds), ['isRead' => true]));
    }

    /** @param array<string> $messageIds */
    public function markAsUnread(array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        $this->batch->send($this->buildPatchRequests(array_values($messageIds), ['isRead' => false]));
    }

    /** @param array<string> $messageIds */
    public function moveTo(string|WellKnownFolder $folder, array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        $destination = FolderIdResolver::resolve($folder);

        $requests = [];
        foreach (array_values($messageIds) as $index => $id) {
            $requests[] = [
                'id' => (string) ($index + 1),
                'method' => 'POST',
                'url' => sprintf('/users/%s/messages/%s/move', rawurlencode($this->mailbox), rawurlencode($id)),
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['destinationId' => $destination],
            ];
        }

        $this->batch->send($requests);
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
                    'isRead' => $message->isRead,
                    'isDraft' => $message->isDraft,
                    'hasAttachments' => $message->hasAttachments,
                    'importance' => $message->importance->value,
                    'receivedAt' => $message->receivedAt?->toIso8601String(),
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

    private function toProviderField(string $field): string
    {
        return match ($field) {
            'receivedAt' => 'receivedDateTime',
            default => $field,
        };
    }

    /**
     * @param  array<string>  $messageIds
     * @param  array<string, mixed>  $body
     * @return array<int, array<string, mixed>>
     */
    private function buildPatchRequests(array $messageIds, array $body): array
    {
        $requests = [];

        foreach ($messageIds as $index => $id) {
            $requests[] = [
                'id' => (string) ($index + 1),
                'method' => 'PATCH',
                'url' => sprintf('/users/%s/messages/%s', rawurlencode($this->mailbox), rawurlencode($id)),
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $body,
            ];
        }

        return $requests;
    }
}

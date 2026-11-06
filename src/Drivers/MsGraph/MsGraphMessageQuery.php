<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\SupportsMessageQueryResults;
use Pyle\Mailbox\Contracts\SupportsMessageSyncRulePushdown;
use Pyle\Mailbox\DTOs\BodyReadLimits;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\DTOs\MessageQueryRejection;
use Pyle\Mailbox\DTOs\MessageQueryResult;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\MatchOperator;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\InvalidReceivedHeaderException;
use Pyle\Mailbox\Support\ConversationPayload;
use Pyle\Mailbox\Support\ProviderMessageId;
use Pyle\Mailbox\Support\ResponseBudget;

class MsGraphMessageQuery implements MessageQueryBuilder, SupportsMessageQueryResults, SupportsMessageSyncRulePushdown
{
    private const MAX_PAGES = 100;

    private string $folderId = 'inbox';

    private bool $queryAllFolders = false;

    private ?string $searchQuery = null;

    /** @var array<string> */
    private array $selectFields;

    private string $orderByField = 'receivedDateTime';

    private string $orderDirection = 'desc';

    private ?int $limit = null;

    private ?int $pageSizeOverride = null;

    /**
     * @var array<int, array{type:'single', field:string, operator:mixed, value:mixed}|array{type:'any', field:string, operator:mixed, values:array<int, mixed>}>
     */
    private array $filters = [];

    private ODataFilterCompiler $compiler;

    private int $maxPages;

    public function __construct(
        private readonly GraphClient $client,
        private readonly BatchRequest $batch,
        private readonly string $mailbox,
    ) {
        /** @var array<string> $defaultSelect */
        $defaultSelect = (array) config('mailbox.default_select', ['id', 'subject']);
        $this->selectFields = $defaultSelect;
        $this->compiler = new ODataFilterCompiler;
        $this->maxPages = max(1, (int) config('mailbox.max_query_pages', self::MAX_PAGES));
    }

    public function inFolder(string|WellKnownFolder $folder): static
    {
        $this->folderId = FolderIdResolver::resolve($folder);
        $this->queryAllFolders = false;

        return $this;
    }

    public function allFolders(): static
    {
        $this->queryAllFolders = true;

        return $this;
    }

    public function supportsRulePushdown(FilterableField $field, MatchOperator $operator): bool
    {
        if (! in_array($operator, $field->operators(), true)) {
            return false;
        }

        return match ($field) {
            FilterableField::SUBJECT => in_array($operator, [
                MatchOperator::EQUALS,
                MatchOperator::CONTAINS,
                MatchOperator::STARTS_WITH,
            ], true),
            FilterableField::RECEIVED_AT => in_array($operator, [
                MatchOperator::BEFORE,
                MatchOperator::AFTER,
                MatchOperator::BETWEEN,
            ], true),
            FilterableField::IS_READ,
            FilterableField::IS_DRAFT,
            FilterableField::HAS_ATTACHMENTS,
            FilterableField::IMPORTANCE,
            FilterableField::FROM_ADDRESS => $operator === MatchOperator::EQUALS,
            default => false,
        };
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
        return $this->execute(false)->messages;
    }

    public function getResult(): MessageQueryResult
    {
        return $this->execute(true);
    }

    private function execute(bool $isolateHeaders): MessageQueryResult
    {
        $pageSize = $this->pageSizeOverride ?? (int) config('mailbox.default_page_size', 50);
        $applyClientFiltersPerPage = $this->searchQuery !== null
            && $this->searchQuery !== ''
            && $this->filters !== [];

        $query = [
            '$select' => implode(',', $isolateHeaders ? array_unique([...$this->selectFields, 'id']) : $this->selectFields),
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

        $endpoint = $this->queryAllFolders
            ? sprintf('users/%s/messages', rawurlencode($this->mailbox))
            : sprintf('users/%s/mailFolders/%s/messages', rawurlencode($this->mailbox), rawurlencode($this->folderId));
        $scope = $endpoint;

        $collected = collect();
        $budget = new ResponseBudget((new BodyReadLimits)->maxResponseBytes);
        $rejections = collect();
        $seenRejectedIds = [];
        $exhausted = true;
        $seenPageSignatures = [];
        $seenMessageIds = [];
        $pageCount = 0;

        do {
            $pageSignature = $endpoint.'|'.http_build_query($query);
            if (isset($seenPageSignatures[$pageSignature])) {
                throw new ApiRequestException('The provider returned a cyclic continuation.');
            }

            if ($pageCount >= $this->maxPages) {
                throw new ApiRequestException('The message query page limit was reached before completion.');
            }

            $seenPageSignatures[$pageSignature] = true;
            $pageCount++;

            if ($isolateHeaders) {
                $payload = $this->client->getBoundedObject($endpoint, $budget, $query, $this->mailbox, retry: true);
                if (! is_array($payload->value ?? null) || ! array_is_list($payload->value)) {
                    throw new ApiRequestException('The provider returned a missing or malformed collection.');
                }
                foreach ($payload->value as $item) {
                    if (! $item instanceof \stdClass) {
                        throw new ApiRequestException('The provider returned a malformed message collection item.');
                    }
                }
                $response = get_object_vars($payload);
            } else {
                $response = $this->client->getMessagePayload($endpoint, $query, $this->mailbox);
            }
            if (! isset($response['value']) || ! is_array($response['value']) || ! array_is_list($response['value'])) {
                throw new ApiRequestException('The provider returned a missing or malformed collection.');
            }

            $messages = collect($response['value'])
                ->map(function (mixed $item) use ($isolateHeaders, $rejections, &$seenRejectedIds): ?MessageDto {
                    if ($isolateHeaders) {
                        $item = get_object_vars($item);
                    }
                    if (! is_array($item) || (($isolateHeaders || in_array('id', $this->selectFields, true) || array_key_exists('id', $item))
                        && (! is_string($item['id'] ?? null) || trim($item['id']) === '' || ($isolateHeaders && ! ProviderMessageId::isValid($item['id']))))) {
                        throw new ApiRequestException('The provider returned a malformed message collection item.');
                    }

                    try {
                        if ($isolateHeaders) {
                            $item = ConversationPayload::receivedMessage((object) $item);
                        }

                        return MessageDto::fromMsGraph($item);
                    } catch (InvalidReceivedHeaderException $exception) {
                        if (! $isolateHeaders) {
                            throw $exception;
                        }
                        if (! isset($seenRejectedIds[$item['id']])) {
                            $rejections->push(new MessageQueryRejection($item['id']));
                            $seenRejectedIds[$item['id']] = true;
                        }

                        return null;
                    }
                })->filter()->values();

            if ($applyClientFiltersPerPage) {
                $messages = $this->applyClientFilters($messages);
            }

            $messages = $messages
                ->filter(function (MessageDto $message) use (&$seenMessageIds): bool {
                    if ($message->id === '') {
                        return true;
                    }

                    if (isset($seenMessageIds[$message->id])) {
                        return false;
                    }

                    $seenMessageIds[$message->id] = true;

                    return true;
                })
                ->values();

            $collected = $collected->concat($messages);

            if ($this->limit !== null && $collected->count() >= $this->limit) {
                $exhausted = $collected->count() === $this->limit && ! array_key_exists('@odata.nextLink', $response);
                if ($isolateHeaders && array_key_exists('@odata.nextLink', $response)) {
                    $next = $this->client->continuationEndpoint($response['@odata.nextLink'], $scope, $this->mailbox);
                    if (isset($seenPageSignatures[$next.'|'])) {
                        throw new ApiRequestException('The provider returned a cyclic continuation.');
                    }
                }
                $collected = $collected->take($this->limit);
                break;
            }

            $endpoint = array_key_exists('@odata.nextLink', $response)
                ? $this->client->continuationEndpoint($response['@odata.nextLink'], $scope, $this->mailbox)
                : '';
            $query = [];
        } while ($endpoint !== '');

        return new MessageQueryResult($collected->values(), $rejections, $exhausted);
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
            'sender.name' => $message->sender?->name,
            'toRecipients.address', 'to.address' => collect($message->toRecipients)->pluck('address')->implode(','),
            'toRecipients.name', 'to.name' => collect($message->toRecipients)->pluck('name')->implode(','),
            'ccRecipients.address', 'cc.address' => collect($message->ccRecipients)->pluck('address')->implode(','),
            'ccRecipients.name', 'cc.name' => collect($message->ccRecipients)->pluck('name')->implode(','),
            'bccRecipients.address', 'bcc.address' => collect($message->bccRecipients)->pluck('address')->implode(','),
            'bccRecipients.name', 'bcc.name' => collect($message->bccRecipients)->pluck('name')->implode(','),
            'replyTo.address' => collect((array) ($message->raw['replyTo'] ?? []))
                ->map(static fn (mixed $recipient): ?string => is_array($recipient) ? ($recipient['emailAddress']['address'] ?? null) : null)
                ->filter()
                ->implode(','),
            'replyTo.name' => collect((array) ($message->raw['replyTo'] ?? []))
                ->map(static fn (mixed $recipient): ?string => is_array($recipient) ? ($recipient['emailAddress']['name'] ?? null) : null)
                ->filter()
                ->implode(','),
            'isRead' => $message->isRead,
            'isDraft' => $message->isDraft,
            'hasAttachments' => $message->hasAttachments,
            'importance' => $message->importance->value,
            'receivedAt' => $message->receivedAt?->toIso8601String(),
            'internetMessageId' => $message->internetMessageId,
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

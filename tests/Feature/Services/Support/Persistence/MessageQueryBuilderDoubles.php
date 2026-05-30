<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\SupportsMessageSyncRulePushdown;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\MatchOperator;
use Pyle\Mailbox\Enums\WellKnownFolder;

final class TestMessageQueryBuilder implements MessageQueryBuilder
{
    /**
     * @param  Collection<int, MessageDto>  $messages
     */
    public function __construct(private readonly Collection $messages) {}

    public function inFolder(string|WellKnownFolder $folder): static
    {
        return $this;
    }

    public function allFolders(): static
    {
        return $this;
    }

    public function where(FilterableField|string $field, mixed $operator, mixed $value = null): static
    {
        return $this;
    }

    public function whereAny(FilterableField|string $field, mixed $operator, array $values): static
    {
        return $this;
    }

    public function search(string $query): static
    {
        return $this;
    }

    public function select(array $fields): static
    {
        return $this;
    }

    public function orderBy(string $field, string $direction = 'desc'): static
    {
        return $this;
    }

    public function take(int $limit): static
    {
        return $this;
    }

    public function pageSize(int $size): static
    {
        return $this;
    }

    public function get(): Collection
    {
        return $this->messages;
    }

    public function count(): int
    {
        return $this->messages->count();
    }

    public function first(): ?MessageDto
    {
        return $this->messages->first();
    }

    public function markAsRead(array $messageIds): void {}

    public function markAsUnread(array $messageIds): void {}

    public function moveTo(string|WellKnownFolder $folder, array $messageIds): void {}
}

final class RecordingMessageQueryBuilder implements MessageQueryBuilder, SupportsMessageSyncRulePushdown
{
    /** @var array<int, array{field:string, operator:mixed, value:mixed}> */
    public array $whereCalls = [];

    /** @var array<int, array{field:string, operator:mixed, values:array<int, mixed>}> */
    public array $whereAnyCalls = [];

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

    public function inFolder(string|WellKnownFolder $folder): static
    {
        return $this;
    }

    public function allFolders(): static
    {
        return $this;
    }

    public function where(FilterableField|string $field, mixed $operator, mixed $value = null): static
    {
        $this->whereCalls[] = [
            'field' => $field instanceof FilterableField ? $field->value : (string) $field,
            'operator' => $operator,
            'value' => $value,
        ];

        return $this;
    }

    public function whereAny(FilterableField|string $field, mixed $operator, array $values): static
    {
        $this->whereAnyCalls[] = [
            'field' => $field instanceof FilterableField ? $field->value : (string) $field,
            'operator' => $operator,
            'values' => $values,
        ];

        return $this;
    }

    public function search(string $query): static
    {
        return $this;
    }

    public function select(array $fields): static
    {
        return $this;
    }

    public function orderBy(string $field, string $direction = 'desc'): static
    {
        return $this;
    }

    public function take(int $limit): static
    {
        return $this;
    }

    public function pageSize(int $size): static
    {
        return $this;
    }

    public function get(): Collection
    {
        return collect();
    }

    public function count(): int
    {
        return 0;
    }

    public function first(): ?MessageDto
    {
        return null;
    }

    public function markAsRead(array $messageIds): void {}

    public function markAsUnread(array $messageIds): void {}

    public function moveTo(string|WellKnownFolder $folder, array $messageIds): void {}
}

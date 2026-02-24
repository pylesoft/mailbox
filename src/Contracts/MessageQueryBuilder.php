<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Illuminate\Support\Collection;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\FilterableField;

interface MessageQueryBuilder
{
    public function inFolder(string|\Pyle\Mailbox\Enums\WellKnownFolder $folder): static;

    public function where(FilterableField|string $field, mixed $operator, mixed $value = null): static;

    public function search(string $query): static;

    /** @param array<string> $fields */
    public function select(array $fields): static;

    public function orderBy(string $field, string $direction = 'desc'): static;

    public function take(int $limit): static;

    public function pageSize(int $size): static;

    /** @return Collection<int, MessageDto> */
    public function get(): Collection;

    public function count(): int;

    public function first(): ?MessageDto;

    /** @param array<string> $messageIds */
    public function markAsRead(array $messageIds): void;

    /** @param array<string> $messageIds */
    public function markAsUnread(array $messageIds): void;

    /** @param array<string> $messageIds */
    public function moveTo(string|\Pyle\Mailbox\Enums\WellKnownFolder $folder, array $messageIds): void;
}

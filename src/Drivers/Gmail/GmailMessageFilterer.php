<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Illuminate\Support\Collection;
use Pyle\Mailbox\DTOs\MessageDto;

final class GmailMessageFilterer
{
    /**
     * @param  array<int, array{type:'single', field:string, operator:mixed, value:mixed}|array{type:'any', field:string, operator:mixed, values:array<int, mixed>}>  $filters
     */
    public function __construct(
        private readonly array $filters,
    ) {}

    /**
     * @param  Collection<int, MessageDto>  $messages
     * @return Collection<int, MessageDto>
     */
    public function apply(Collection $messages): Collection
    {
        return $messages
            ->filter(fn (MessageDto $message): bool => $this->matches($message))
            ->values();
    }

    private function matches(MessageDto $message): bool
    {
        foreach ($this->filters as $filter) {
            if ($filter['type'] === 'any' && ! $this->matchesAnyFilter($message, $filter)) {
                return false;
            }

            if ($filter['type'] === 'single' && ! $this->matchesSingleFilter($message, $filter)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{type:'any', field:string, operator:mixed, values:array<int, mixed>}  $filter
     */
    private function matchesAnyFilter(MessageDto $message, array $filter): bool
    {
        $actual = $this->resolveActualValue($message, $filter['field']);

        foreach ($filter['values'] as $expectedValue) {
            if ($this->compare((string) $filter['operator'], $actual, $expectedValue)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{type:'single', field:string, operator:mixed, value:mixed}  $filter
     */
    private function matchesSingleFilter(MessageDto $message, array $filter): bool
    {
        $comparison = $this->normalizeSingleFilter($filter);

        return $this->compare(
            $comparison['operator'],
            $this->resolveActualValue($message, $filter['field']),
            $comparison['value'],
        );
    }

    /**
     * @param  array{type:'single', field:string, operator:mixed, value:mixed}  $filter
     * @return array{operator:string, value:mixed}
     */
    private function normalizeSingleFilter(array $filter): array
    {
        $operator = $filter['operator'];
        $value = $filter['value'];

        if ($value === null) {
            return [
                'operator' => '=',
                'value' => $operator,
            ];
        }

        return [
            'operator' => (string) $operator,
            'value' => $value,
        ];
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
            'receivedAt' => $message->receivedAt?->toIso8601String(),
            'isRead' => $message->isRead,
            'isDraft' => $message->isDraft,
            'hasAttachments' => $message->hasAttachments,
            'importance' => $message->importance->value,
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
}

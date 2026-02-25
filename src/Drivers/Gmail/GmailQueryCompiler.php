<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use Carbon\CarbonImmutable;
use Pyle\Mailbox\Enums\FilterableField;

class GmailQueryCompiler
{
    /** @var array<int, array{field:string, operator:string, value:mixed}> */
    private array $clauses = [];

    /** @var array<int, int> */
    private array $unsupportedClauseIndexes = [];

    public function where(FilterableField|string $field, mixed $operator, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->clauses[] = [
            'field' => $field instanceof FilterableField ? $field->value : (string) $field,
            'operator' => strtolower((string) $operator),
            'value' => $value,
        ];

        return $this;
    }

    public function compile(): string
    {
        $parts = [];
        $this->unsupportedClauseIndexes = [];

        foreach ($this->clauses as $index => $clause) {
            $compiled = $this->compileClause($clause['field'], $clause['operator'], $clause['value']);

            if ($compiled === null) {
                $this->unsupportedClauseIndexes[] = $index;

                continue;
            }

            if ($compiled !== '') {
                $parts[] = $compiled;
            }
        }

        return implode(' ', $parts);
    }

    public function hasUnsupportedClauses(): bool
    {
        return $this->unsupportedClauseIndexes !== [];
    }

    private function compileClause(string $field, string $operator, mixed $value): ?string
    {
        return match ($field) {
            'subject' => $this->compileTextField('subject', $operator, $value),
            'from.address', 'sender.address' => $this->compileTextField('from', $operator, $value),
            'toRecipients.address' => $this->compileTextField('to', $operator, $value),
            'ccRecipients.address' => $this->compileTextField('cc', $operator, $value),
            'isRead' => $this->compileIsRead($operator, $value),
            'isDraft' => $this->compileIsDraft($operator, $value),
            'hasAttachments' => $this->compileHasAttachments($operator, $value),
            'receivedAt' => $this->compileReceivedAt($operator, $value),
            'importance' => $this->compileImportance($operator, $value),
            default => null,
        };
    }

    private function compileTextField(string $gmailField, string $operator, mixed $value): ?string
    {
        if (! in_array($operator, ['=', 'eq', 'contains', 'starts_with', 'startswith', 'ends_with', 'endswith'], true)) {
            return null;
        }

        $term = trim((string) $value);

        if ($term === '') {
            return '';
        }

        return sprintf('%s:"%s"', $gmailField, str_replace('"', '\\"', $term));
    }

    private function compileIsRead(string $operator, mixed $value): ?string
    {
        if (! in_array($operator, ['=', 'eq', '!=', 'ne'], true)) {
            return null;
        }

        $boolValue = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if (! is_bool($boolValue)) {
            return null;
        }

        if (in_array($operator, ['!=', 'ne'], true)) {
            $boolValue = ! $boolValue;
        }

        return $boolValue ? '-is:unread' : 'is:unread';
    }

    private function compileIsDraft(string $operator, mixed $value): ?string
    {
        if (! in_array($operator, ['=', 'eq', '!=', 'ne'], true)) {
            return null;
        }

        $boolValue = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if (! is_bool($boolValue)) {
            return null;
        }

        if (in_array($operator, ['!=', 'ne'], true)) {
            $boolValue = ! $boolValue;
        }

        return $boolValue ? 'in:drafts' : '-in:drafts';
    }

    private function compileHasAttachments(string $operator, mixed $value): ?string
    {
        if (! in_array($operator, ['=', 'eq', '!=', 'ne'], true)) {
            return null;
        }

        $boolValue = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if (! is_bool($boolValue)) {
            return null;
        }

        if (in_array($operator, ['!=', 'ne'], true)) {
            $boolValue = ! $boolValue;
        }

        return $boolValue ? 'has:attachment' : '-has:attachment';
    }

    private function compileReceivedAt(string $operator, mixed $value): ?string
    {
        $date = $this->normalizeDate($value);

        if (! $date instanceof CarbonImmutable) {
            return null;
        }

        return match ($operator) {
            '>', 'gt', 'after', '>=', 'ge' => sprintf('after:%s', $date->format('Y/m/d')),
            '<', 'lt', 'before', '<=', 'le' => sprintf('before:%s', $date->format('Y/m/d')),
            default => null,
        };
    }

    private function compileImportance(string $operator, mixed $value): ?string
    {
        if (! in_array($operator, ['=', 'eq', '!=', 'ne'], true)) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));

        if (! in_array($normalized, ['low', 'normal', 'high'], true)) {
            return null;
        }

        if ($normalized === 'high') {
            return in_array($operator, ['!=', 'ne'], true) ? '-is:important' : 'is:important';
        }

        if ($normalized === 'normal') {
            return in_array($operator, ['!=', 'ne'], true) ? 'is:important' : '-is:important';
        }

        return null;
    }

    private function normalizeDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}

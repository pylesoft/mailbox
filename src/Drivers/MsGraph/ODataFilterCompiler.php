<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Pyle\Mailbox\Enums\FilterableField;

class ODataFilterCompiler
{
    /** @var array<int, array{field:string, operator:string, value:mixed}> */
    private array $clauses = [];

    public function where(FilterableField|string $field, mixed $operator, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->clauses[] = [
            'field' => $field instanceof FilterableField ? $field->value : (string) $field,
            'operator' => (string) $operator,
            'value' => $value,
        ];

        return $this;
    }

    public function compile(): string
    {
        $parts = [];

        foreach ($this->clauses as $clause) {
            $expression = $this->compileClause($clause['field'], $clause['operator'], $clause['value']);
            if ($expression !== null) {
                $parts[] = $expression;
            }
        }

        return implode(' and ', $parts);
    }

    /** @return array<int, array{field:string, operator:string, value:mixed}> */
    public function clauses(): array
    {
        return $this->clauses;
    }

    private function compileClause(string $field, string $operator, mixed $value): ?string
    {
        $odataField = match ($field) {
            'receivedAt' => 'receivedDateTime',
            'from.address' => 'from/emailAddress/address',
            default => $field,
        };

        return match (strtolower($operator)) {
            '=', 'eq' => sprintf('%s eq %s', $odataField, $this->formatValue($value)),
            '!=', 'ne' => sprintf('%s ne %s', $odataField, $this->formatValue($value)),
            '>', 'gt', 'greater_than', 'after' => sprintf('%s gt %s', $odataField, $this->formatValue($value)),
            '>=', 'ge' => sprintf('%s ge %s', $odataField, $this->formatValue($value)),
            '<', 'lt', 'less_than', 'before' => sprintf('%s lt %s', $odataField, $this->formatValue($value)),
            '<=', 'le' => sprintf('%s le %s', $odataField, $this->formatValue($value)),
            'contains' => sprintf('contains(%s,%s)', $odataField, $this->formatValue($value)),
            'starts_with', 'startswith' => sprintf('startsWith(%s,%s)', $odataField, $this->formatValue($value)),
            'ends_with', 'endswith' => sprintf('endsWith(%s,%s)', $odataField, $this->formatValue($value)),
            default => null,
        };
    }

    private function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        $escaped = str_replace("'", "''", (string) $value);

        return sprintf("'%s'", $escaped);
    }
}

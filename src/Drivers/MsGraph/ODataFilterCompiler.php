<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Pyle\Mailbox\Enums\FilterableField;

class ODataFilterCompiler
{
    /**
     * @var array<int, array{type:'single', field:string, operator:string, value:mixed}|array{type:'any', clauses:array<int, array{field:string, operator:string, value:mixed}>}>
     */
    private array $clauses = [];

    public function where(FilterableField|string $field, mixed $operator, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->clauses[] = [
            'type' => 'single',
            'field' => $field instanceof FilterableField ? $field->value : (string) $field,
            'operator' => (string) $operator,
            'value' => $value,
        ];

        return $this;
    }

    /** @param array<int, mixed> $values */
    public function whereAny(FilterableField|string $field, mixed $operator, array $values): self
    {
        $normalizedField = $field instanceof FilterableField ? $field->value : (string) $field;
        $clauses = [];

        foreach ($values as $value) {
            $clauses[] = [
                'field' => $normalizedField,
                'operator' => (string) $operator,
                'value' => $value,
            ];
        }

        if ($clauses === []) {
            return $this;
        }

        $this->clauses[] = [
            'type' => 'any',
            'clauses' => $clauses,
        ];

        return $this;
    }

    public function compile(): string
    {
        $parts = [];

        foreach ($this->clauses as $clause) {
            if ($clause['type'] === 'single') {
                $expression = $this->compileClause($clause['field'], $clause['operator'], $clause['value']);
            } else {
                $groupParts = [];
                foreach ($clause['clauses'] as $groupClause) {
                    $groupExpression = $this->compileClause(
                        $groupClause['field'],
                        $groupClause['operator'],
                        $groupClause['value'],
                    );

                    if ($groupExpression !== null && $groupExpression !== '') {
                        $groupParts[] = $groupExpression;
                    }
                }

                if ($groupParts === []) {
                    $expression = null;
                } elseif (count($groupParts) === 1) {
                    $expression = $groupParts[0];
                } else {
                    $expression = '('.implode(' or ', $groupParts).')';
                }
            }

            if ($expression !== null && $expression !== '') {
                $parts[] = $expression;
            }
        }

        return implode(' and ', $parts);
    }

    /**
     * @return array<int, array{type:'single', field:string, operator:string, value:mixed}|array{type:'any', clauses:array<int, array{field:string, operator:string, value:mixed}>}>
     */
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

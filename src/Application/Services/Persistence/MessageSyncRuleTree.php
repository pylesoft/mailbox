<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\Persistence;

use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\SupportsMessageSyncRulePushdown;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\MatchOperator;

final class MessageSyncRuleTree
{
    /**
     * @return array<string, mixed>
     */
    public function extract(mixed $runtimeRuleTree, mixed $storedRuleTree): array
    {
        if ($this->isRuleTree($runtimeRuleTree)) {
            return $this->normalizeRuleTree($runtimeRuleTree);
        }

        if ($this->isRuleTree($storedRuleTree)) {
            return $this->normalizeRuleTree($storedRuleTree);
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $ruleTree
     */
    public function requiresAttachmentMetadata(array $ruleTree): bool
    {
        foreach ($this->collectRuleTreeFields($ruleTree) as $field) {
            if (str_starts_with($field, 'attachment')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $ruleTree
     */
    public function requiresHasAttachmentsTrue(array $ruleTree): bool
    {
        if ($ruleTree === []) {
            return false;
        }

        return ! $this->groupMayMatchWithoutAttachments($ruleTree);
    }

    /**
     * @param  array<string, mixed>  $ruleTree
     */
    public function applyPushdown(MessageQueryBuilder $query, array $ruleTree): void
    {
        if (! $query instanceof SupportsMessageSyncRulePushdown) {
            return;
        }

        if ($ruleTree === []) {
            return;
        }

        $conditions = $this->collectAndOnlyConditions($ruleTree);

        if ($conditions === null || $conditions === []) {
            return;
        }

        foreach ($conditions as $condition) {
            $field = trim((string) ($condition['field'] ?? ''));
            $operator = trim((string) ($condition['operator'] ?? ''));
            $value = $condition['value'] ?? null;

            $filterableField = FilterableField::tryFrom($field);
            $matchOperator = MatchOperator::tryFrom($operator);

            if (! $filterableField instanceof FilterableField || ! $matchOperator instanceof MatchOperator) {
                continue;
            }

            if (! $query->supportsRulePushdown($filterableField, $matchOperator)) {
                continue;
            }

            if ($matchOperator === MatchOperator::BETWEEN && is_array($value) && count($value) === 2) {
                [$min, $max] = array_values($value);
                $query->where($field, 'ge', $min);
                $query->where($field, 'le', $max);

                continue;
            }

            $providerOperator = match ($matchOperator) {
                MatchOperator::EQUALS => 'eq',
                MatchOperator::CONTAINS => 'contains',
                MatchOperator::STARTS_WITH => 'starts_with',
                MatchOperator::ENDS_WITH => 'ends_with',
                MatchOperator::GREATER_THAN => 'gt',
                MatchOperator::LESS_THAN => 'lt',
                MatchOperator::BEFORE => 'lt',
                MatchOperator::AFTER => 'gt',
                default => null,
            };

            if (! is_string($providerOperator)) {
                continue;
            }

            $query->where($field, $providerOperator, $value);
        }
    }

    private function isRuleTree(mixed $value): bool
    {
        return is_array($value)
            && isset($value['operator'])
            && is_array($value['conditions'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $ruleTree
     * @return array<string, mixed>
     */
    private function normalizeRuleTree(array $ruleTree): array
    {
        $operator = strtoupper((string) ($ruleTree['operator'] ?? 'AND'));
        $conditions = $ruleTree['conditions'] ?? [];

        if (! is_array($conditions)) {
            $conditions = [];
        }

        $normalizedConditions = [];

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            if (isset($condition['conditions']) && is_array($condition['conditions'])) {
                $normalizedConditions[] = $this->normalizeRuleTree($condition);

                continue;
            }

            $field = trim((string) ($condition['field'] ?? ''));
            $conditionOperator = trim((string) ($condition['operator'] ?? ''));

            if ($field === '' || $conditionOperator === '') {
                continue;
            }

            $normalizedConditions[] = [
                'field' => $field,
                'operator' => $conditionOperator,
                'value' => $condition['value'] ?? null,
            ];
        }

        return [
            'operator' => $operator === 'OR' ? 'OR' : 'AND',
            'conditions' => $normalizedConditions,
        ];
    }

    /**
     * @param  array<string, mixed>  $ruleTree
     * @return array<int, string>
     */
    private function collectRuleTreeFields(array $ruleTree): array
    {
        $conditions = $ruleTree['conditions'] ?? null;

        if (! is_array($conditions)) {
            return [];
        }

        $fields = [];

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            if (isset($condition['conditions']) && is_array($condition['conditions'])) {
                $fields = [...$fields, ...$this->collectRuleTreeFields($condition)];

                continue;
            }

            $field = trim((string) ($condition['field'] ?? ''));

            if ($field !== '') {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function groupMayMatchWithoutAttachments(array $group): bool
    {
        $operator = strtoupper((string) ($group['operator'] ?? 'AND'));
        $conditions = $group['conditions'] ?? [];

        if (! is_array($conditions) || $conditions === []) {
            return true;
        }

        $results = [];

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            if (isset($condition['conditions']) && is_array($condition['conditions'])) {
                $results[] = $this->groupMayMatchWithoutAttachments($condition);

                continue;
            }

            $results[] = $this->conditionMayMatchWithoutAttachments($condition);
        }

        return $operator === 'OR'
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function conditionMayMatchWithoutAttachments(array $condition): bool
    {
        $field = trim((string) ($condition['field'] ?? ''));

        if ($field === '' || ! str_starts_with($field, 'attachment')) {
            return true;
        }

        if ($field !== FilterableField::ATTACHMENT_COUNT->value) {
            return false;
        }

        return $this->attachmentCountConditionMayMatchWithoutAttachments($condition);
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function attachmentCountConditionMayMatchWithoutAttachments(array $condition): bool
    {
        $operator = MatchOperator::tryFrom((string) ($condition['operator'] ?? ''));
        $value = $condition['value'] ?? null;

        if (! $operator instanceof MatchOperator) {
            return true;
        }

        return match ($operator) {
            MatchOperator::EQUALS => ($this->numericValue($value) ?? 0.0) === 0.0,
            MatchOperator::GREATER_THAN => 0.0 > ($this->numericValue($value) ?? INF),
            MatchOperator::LESS_THAN => 0.0 < ($this->numericValue($value) ?? -INF),
            MatchOperator::BETWEEN => $this->zeroWithinRange($value),
            default => true,
        };
    }

    private function zeroWithinRange(mixed $value): bool
    {
        if (! is_array($value) || count($value) !== 2) {
            return true;
        }

        $normalizedRange = array_values($value);
        $minimum = $this->numericValue($normalizedRange[0]);
        $maximum = $this->numericValue($normalizedRange[1]);

        if ($minimum === null || $maximum === null) {
            return true;
        }

        return $minimum <= 0.0 && $maximum >= 0.0;
    }

    private function numericValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || ! is_numeric($trimmed)) {
            return null;
        }

        return (float) $trimmed;
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<int, array<string, mixed>>|null
     */
    private function collectAndOnlyConditions(array $group): ?array
    {
        if (strtoupper((string) ($group['operator'] ?? 'AND')) !== 'AND') {
            return null;
        }

        $conditions = $group['conditions'] ?? [];

        if (! is_array($conditions)) {
            return null;
        }

        $flattened = [];

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                return null;
            }

            if (isset($condition['conditions']) && is_array($condition['conditions'])) {
                $subConditions = $this->collectAndOnlyConditions($condition);

                if ($subConditions === null) {
                    return null;
                }

                $flattened = [...$flattened, ...$subConditions];

                continue;
            }

            $flattened[] = $condition;
        }

        return $flattened;
    }
}

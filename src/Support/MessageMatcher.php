<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\MatchOperator;

class MessageMatcher
{
    /** @param array<string, mixed> $rules */
    public function __construct(
        private readonly array $rules,
    ) {}

    /** @param iterable<AttachmentDto> $attachments */
    public function matches(MessageDto $message, iterable $attachments = []): bool
    {
        if ($attachments instanceof Collection) {
            $attachmentCollection = $attachments;
        } elseif (is_array($attachments)) {
            $attachmentCollection = collect($attachments);
        } else {
            $attachmentCollection = collect(iterator_to_array($attachments, false));
        }

        return $this->evaluateGroup($this->rules, $message, $attachmentCollection);
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  Collection<int, AttachmentDto>  $attachments
     */
    private function evaluateGroup(array $group, MessageDto $message, Collection $attachments): bool
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
                $results[] = $this->evaluateGroup($condition, $message, $attachments);

                continue;
            }

            $results[] = $this->evaluateCondition($condition, $message, $attachments);
        }

        return $operator === 'OR'
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  Collection<int, AttachmentDto>  $attachments
     */
    private function evaluateCondition(array $condition, MessageDto $message, Collection $attachments): bool
    {
        $field = (string) ($condition['field'] ?? '');
        $operator = MatchOperator::tryFrom((string) ($condition['operator'] ?? MatchOperator::EQUALS->value))
            ?? MatchOperator::EQUALS;
        $expected = $condition['value'] ?? null;

        if (str_starts_with($field, 'attachment')) {
            return $this->evaluateAttachmentCondition($field, $operator, $expected, $attachments);
        }

        $actual = $this->resolveMessageField($message, $field);

        return $this->evaluateOperator($operator, $actual, $expected);
    }

    /** @param Collection<int, AttachmentDto> $attachments */
    private function evaluateAttachmentCondition(string $field, MatchOperator $operator, mixed $expected, Collection $attachments): bool
    {
        if ($field === 'attachmentCount') {
            return $this->evaluateOperator($operator, $attachments->count(), $expected);
        }

        return $attachments->contains(function (AttachmentDto $attachment) use ($field, $operator, $expected): bool {
            $actual = match ($field) {
                'attachmentName' => $attachment->name,
                'attachmentContentType' => $attachment->contentType,
                'attachmentSize' => $attachment->size,
                default => null,
            };

            return $this->evaluateOperator($operator, $actual, $expected);
        });
    }

    private function resolveMessageField(MessageDto $message, string $field): mixed
    {
        return match ($field) {
            'subject' => $message->subject,
            'from.address' => $message->from?->address,
            'from.name' => $message->from?->name,
            'sender.address' => $message->sender?->address,
            'toRecipients.address' => collect($message->toRecipients)->pluck('address')->implode(','),
            'ccRecipients.address' => collect($message->ccRecipients)->pluck('address')->implode(','),
            'receivedAt' => $message->receivedAt,
            'isRead' => $message->isRead,
            'isDraft' => $message->isDraft,
            'hasAttachments' => $message->hasAttachments,
            'importance' => $message->importance->value,
            'bodyPreview' => $message->bodyPreview,
            default => Arr::get($message->toArray(), $field),
        };
    }

    private function evaluateOperator(MatchOperator $operator, mixed $actual, mixed $expected): bool
    {
        return match ($operator) {
            MatchOperator::EQUALS => $this->normalize($actual) === $this->normalize($expected),
            MatchOperator::NOT_EQUALS => $this->normalize($actual) !== $this->normalize($expected),
            MatchOperator::CONTAINS => str_contains($this->toLowerString($actual), $this->toLowerString($expected)),
            MatchOperator::NOT_CONTAINS => ! str_contains($this->toLowerString($actual), $this->toLowerString($expected)),
            MatchOperator::STARTS_WITH => str_starts_with($this->toLowerString($actual), $this->toLowerString($expected)),
            MatchOperator::ENDS_WITH => str_ends_with($this->toLowerString($actual), $this->toLowerString($expected)),
            MatchOperator::MATCHES_REGEX => @preg_match((string) $expected, (string) $actual) === 1,
            MatchOperator::GREATER_THAN => $this->compare($actual, $expected) > 0,
            MatchOperator::LESS_THAN => $this->compare($actual, $expected) < 0,
            MatchOperator::BEFORE => $this->compare($actual, $expected) < 0,
            MatchOperator::AFTER => $this->compare($actual, $expected) > 0,
            MatchOperator::BETWEEN => $this->between($actual, $expected),
        };
    }

    private function compare(mixed $actual, mixed $expected): int
    {
        if ($actual instanceof \DateTimeInterface || $expected instanceof \DateTimeInterface) {
            $actualDate = $actual instanceof \DateTimeInterface ? CarbonImmutable::instance($actual) : CarbonImmutable::parse((string) $actual);
            $expectedDate = $expected instanceof \DateTimeInterface ? CarbonImmutable::instance($expected) : CarbonImmutable::parse((string) $expected);

            return $actualDate <=> $expectedDate;
        }

        return (float) $actual <=> (float) $expected;
    }

    private function between(mixed $actual, mixed $range): bool
    {
        if (! is_array($range) || count($range) !== 2) {
            return false;
        }

        [$min, $max] = array_values($range);

        return $this->compare($actual, $min) >= 0 && $this->compare($actual, $max) <= 0;
    }

    private function normalize(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_strtolower(trim($value));
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->toIso8601String();
        }

        return $value;
    }

    private function toLowerString(mixed $value): string
    {
        return mb_strtolower((string) $value);
    }
}

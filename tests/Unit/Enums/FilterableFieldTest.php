<?php

declare(strict_types=1);

use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\MatchOperator;

it('returns expected metadata for filterable fields', function (): void {
    expect(FilterableField::SUBJECT->valueType())->toBe('string');
    expect(FilterableField::RECEIVED_AT->valueType())->toBe('datetime');
    expect(FilterableField::IS_READ->valueType())->toBe('boolean');
    expect(FilterableField::ATTACHMENT_SIZE->valueType())->toBe('integer');

    expect(FilterableField::SUBJECT->operators())->toContain(MatchOperator::CONTAINS, MatchOperator::MATCHES_REGEX);
    expect(FilterableField::ATTACHMENT_NAME->operators())->toContain(MatchOperator::STARTS_WITH, MatchOperator::ENDS_WITH);

    expect(FilterableField::IS_READ->isServerPushable())->toBeTrue();
    expect(FilterableField::ATTACHMENT_SIZE->isServerPushable())->toBeFalse();
});

<?php

declare(strict_types=1);

use Pyle\Mailbox\Contracts\SupportsMessageSyncRulePushdown;
use Pyle\Mailbox\Drivers\Gmail\GmailMessageQuery;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphMessageQuery;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\MatchOperator;

it('defines Microsoft Graph rule pushdown support on the Graph query builder', function (): void {
    $query = (new ReflectionClass(MsGraphMessageQuery::class))->newInstanceWithoutConstructor();

    expect($query)->toBeInstanceOf(SupportsMessageSyncRulePushdown::class)
        ->and($query->supportsRulePushdown(FilterableField::FROM_ADDRESS, MatchOperator::EQUALS))->toBeTrue()
        ->and($query->supportsRulePushdown(FilterableField::FROM_ADDRESS, MatchOperator::ENDS_WITH))->toBeFalse()
        ->and($query->supportsRulePushdown(FilterableField::SUBJECT, MatchOperator::CONTAINS))->toBeTrue()
        ->and($query->supportsRulePushdown(FilterableField::SUBJECT, MatchOperator::MATCHES_REGEX))->toBeFalse();
});

it('defines Gmail rule pushdown support on the Gmail query builder', function (): void {
    $query = (new ReflectionClass(GmailMessageQuery::class))->newInstanceWithoutConstructor();

    expect($query)->toBeInstanceOf(SupportsMessageSyncRulePushdown::class)
        ->and($query->supportsRulePushdown(FilterableField::FROM_ADDRESS, MatchOperator::ENDS_WITH))->toBeTrue()
        ->and($query->supportsRulePushdown(FilterableField::SUBJECT, MatchOperator::GREATER_THAN))->toBeFalse()
        ->and($query->supportsRulePushdown(FilterableField::ATTACHMENT_NAME, MatchOperator::CONTAINS))->toBeFalse();
});

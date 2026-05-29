<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\MatchOperator;

interface SupportsMessageSyncRulePushdown
{
    public function supportsRulePushdown(FilterableField $field, MatchOperator $operator): bool;
}

<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Enums;

enum MatchOperator: string
{
    case EQUALS = 'equals';
    case NOT_EQUALS = 'not_equals';
    case CONTAINS = 'contains';
    case NOT_CONTAINS = 'not_contains';
    case STARTS_WITH = 'starts_with';
    case ENDS_WITH = 'ends_with';
    case MATCHES_REGEX = 'matches_regex';
    case GREATER_THAN = 'greater_than';
    case LESS_THAN = 'less_than';
    case BETWEEN = 'between';
    case BEFORE = 'before';
    case AFTER = 'after';
}

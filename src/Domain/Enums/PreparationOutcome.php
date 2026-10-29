<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Enums;

enum PreparationOutcome: string
{
    case Prepared = 'prepared';
    case Rejected = 'rejected';
    case Uncertain = 'uncertain';
}

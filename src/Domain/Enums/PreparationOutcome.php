<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Enums;

enum PreparationOutcome: string
{
    case Preparing = 'preparing';
    case Prepared = 'prepared';
    case Rejected = 'rejected';
    case Uncertain = 'uncertain';
}

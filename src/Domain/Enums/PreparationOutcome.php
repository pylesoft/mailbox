<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Enums;

enum PreparationOutcome: string
{
    case Preparing = 'preparing';
    case Expired = 'expired';
    case Prepared = 'prepared';
    case Rejected = 'rejected';
    case Uncertain = 'uncertain';
}

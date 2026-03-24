<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Enums;

enum Importance: string
{
    case LOW = 'low';
    case NORMAL = 'normal';
    case HIGH = 'high';
}

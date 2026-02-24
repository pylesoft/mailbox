<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Enums;

enum ConnectionStatus: string
{
    case PENDING = 'pending';
    case CONNECTED = 'connected';
    case ERROR = 'error';
    case DISABLED = 'disabled';
}

<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Enums;

enum SyncStatus: string
{
    case IDLE = 'idle';
    case SYNCING = 'syncing';
    case ERROR = 'error';
}

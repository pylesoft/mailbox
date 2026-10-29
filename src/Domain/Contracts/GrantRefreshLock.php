<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Illuminate\Contracts\Cache\Lock;
use Pyle\Mailbox\DTOs\GrantContext;

interface GrantRefreshLock
{
    /** Acquire one shared lock for the selected connection/grant across targets and lifecycle generations. */
    public function acquire(GrantContext $context): Lock;
}

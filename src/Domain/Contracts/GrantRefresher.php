<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\StoredGrant;

interface GrantRefresher
{
    /** Exchange this verified grant only; return a new revision without changing its identity or lifecycle. */
    public function refresh(GrantContext $context, StoredGrant $grant): StoredGrant;
}

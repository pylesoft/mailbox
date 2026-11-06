<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\StoredGrant;

interface GrantStore
{
    /** Load only this selected grant after checking its connection and lifecycle generation. */
    public function load(GrantContext $context): ?StoredGrant;

    /**
     * Atomically check ownership, lifecycle generation, and the expected identity/revision before replacement.
     * A null expectation requires no existing grant. Revisions must never be reused, including after deletion.
     * Return false on conflict; never select, merge or reassign grants by provider subject or email.
     */
    public function replace(GrantContext $context, ?StoredGrant $expected, StoredGrant $replacement): bool;
}

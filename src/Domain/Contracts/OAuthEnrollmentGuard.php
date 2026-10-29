<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;

interface OAuthEnrollmentGuard
{
    /**
     * Authorize the current initiating/callback principal, selected connection, grant and lifecycle generation.
     * The host context comes from trusted application/session state, never a callback query or user reference.
     * Called at initiation, before exchange and immediately before credential commit.
     *
     * @throws GrantAccessDeniedException
     */
    public function assertAllowed(GrantContext $context, string $hostContext): void;
}

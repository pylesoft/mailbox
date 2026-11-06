<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;

interface GrantAccessGuard
{
    /** @throws GrantAccessDeniedException When ownership, target permission or lifecycle generation is no longer valid. */
    public function assertActive(GrantContext $context): void;
}

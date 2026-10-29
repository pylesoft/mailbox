<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;

interface GrantTokenProvider
{
    /** @throws GrantAccessDeniedException When ownership, target permission or lifecycle generation is no longer valid. */
    public function assertActive(GrantContext $context): void;

    /** Return a currently valid access token for this explicitly selected grant. */
    public function accessToken(GrantContext $context): string;
}

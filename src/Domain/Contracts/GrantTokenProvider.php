<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\DTOs\GrantContext;

interface GrantTokenProvider extends GrantAccessGuard
{
    /** Return a currently valid access token for this explicitly selected grant. */
    public function accessToken(GrantContext $context): string;
}

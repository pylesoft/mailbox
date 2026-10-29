<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph\Contracts;

use Pyle\Mailbox\DTOs\GraphSubscriptionRegistration;

interface SupportsGraphSharedMailboxLive
{
    public function requireSharedMailboxLive(GraphSubscriptionRegistration $registration): void;
}

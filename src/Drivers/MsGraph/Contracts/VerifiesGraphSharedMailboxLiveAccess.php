<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph\Contracts;

use Pyle\Mailbox\Contracts\GrantAccessGuard;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\GraphSubscriptionConfiguration;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;

interface VerifiesGraphSharedMailboxLiveAccess extends GrantAccessGuard
{
    /**
     * Verify trusted host setup evidence for this exact selected application grant,
     * tenant/mailbox mapping, consent, scoped read/write/send/watch authority and
     * supported notification/lifecycle endpoints. Token acquisition is not proof.
     *
     * @throws UnsupportedCapabilityException When any required setup is missing or unverified.
     */
    public function assertSharedMailboxLive(GrantContext $context, GraphSubscriptionConfiguration $configuration): void;
}

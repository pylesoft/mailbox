<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph\Contracts;

use DateTimeImmutable;
use Pyle\Mailbox\DTOs\GraphSubscriptionConfiguration;
use Pyle\Mailbox\DTOs\GraphSubscriptionRegistration;

interface SupportsGraphSubscriptions
{
    public function createSubscription(GraphSubscriptionConfiguration $configuration, DateTimeImmutable $expiresAt): GraphSubscriptionRegistration;

    public function renewSubscription(GraphSubscriptionRegistration $registration, DateTimeImmutable $expiresAt): GraphSubscriptionRegistration;

    public function removeSubscription(GraphSubscriptionRegistration $registration): GraphSubscriptionRegistration;
}

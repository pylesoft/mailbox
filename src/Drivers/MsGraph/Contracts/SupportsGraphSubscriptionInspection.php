<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph\Contracts;

use Pyle\Mailbox\DTOs\GraphSubscriptionConfiguration;
use Pyle\Mailbox\DTOs\GraphSubscriptionLookup;
use Pyle\Mailbox\DTOs\GraphSubscriptionRegistration;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncContinuation;

interface SupportsGraphSubscriptionInspection
{
    public function inspectSubscription(string $subscriptionId, #[\SensitiveParameter] GraphSubscriptionConfiguration $configuration, string $expectedApplicationId): ?GraphSubscriptionRegistration;

    public function lookupSubscriptions(#[\SensitiveParameter] GraphSubscriptionConfiguration $configuration, string $expectedApplicationId, SyncBudget $budget, #[\SensitiveParameter] ?SyncContinuation $continuation = null): GraphSubscriptionLookup;
}

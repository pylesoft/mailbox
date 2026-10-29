<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph\Contracts;

use DateTimeImmutable;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Pyle\Mailbox\DTOs\GraphSubscriptionConfiguration;
use Pyle\Mailbox\DTOs\GraphSubscriptionRegistration;
use Pyle\Mailbox\DTOs\GraphChangeHint;

interface SupportsGraphSubscriptions
{
    public function createSubscription(GraphSubscriptionConfiguration $configuration, DateTimeImmutable $expiresAt): GraphSubscriptionRegistration;

    public function renewSubscription(GraphSubscriptionRegistration $registration, DateTimeImmutable $expiresAt): GraphSubscriptionRegistration;

    public function removeSubscription(GraphSubscriptionRegistration $registration): GraphSubscriptionRegistration;

    public function subscriptionHandshake(#[\SensitiveParameter] RequestInterface $request, GraphSubscriptionConfiguration $configuration): ResponseInterface;

    /**
     * @param list<GraphSubscriptionRegistration> $registrations
     * @return list<GraphChangeHint>
     */
    public function subscriptionNotifications(#[\SensitiveParameter] RequestInterface $request, array $registrations, int $maxRequestBytes = 65_536): array;
}

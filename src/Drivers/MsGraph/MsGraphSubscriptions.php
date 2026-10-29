<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use DateTimeImmutable;
use Pyle\Mailbox\Contracts\GrantAccessGuard;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\GraphSubscriptionConfiguration;
use Pyle\Mailbox\DTOs\GraphSubscriptionRegistration;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\InvalidSyncStateException;
use Pyle\Mailbox\Support\ProviderWriteResponse;

final readonly class MsGraphSubscriptions
{
    public function __construct(private GraphClient $client, private GrantContext $context, private GrantAccessGuard $guard) {}

    public function create(GraphSubscriptionConfiguration $configuration, DateTimeImmutable $expiresAt): GraphSubscriptionRegistration
    {
        $this->guard->assertActive($this->context);
        $response = $this->client->createBasicSubscription($this->context, $configuration, $expiresAt);

        return $this->confirmedRegistration($response, $configuration, 201);
    }

    public function renew(GraphSubscriptionRegistration $registration, DateTimeImmutable $expiresAt): GraphSubscriptionRegistration
    {
        $this->assertRegistration($registration);
        $response = $this->client->renewBasicSubscription($registration, $expiresAt);

        return $this->confirmedRegistration($response, $registration->configuration, 200, $registration->subscriptionId);
    }

    public function remove(GraphSubscriptionRegistration $registration): GraphSubscriptionRegistration
    {
        $this->assertRegistration($registration);
        $response = $this->client->removeBasicSubscription($registration);
        $this->guard->assertActive($this->context);
        if ($response->status !== 204 || $response->body() !== '') {
            throw new ApiRequestException('Graph subscription removal was not confirmed; reconcile the retained registration before retrying.', status: $response->status, headers: $response->headers);
        }

        return new GraphSubscriptionRegistration($this->context, $registration->configuration, $registration->subscriptionId, $registration->expiresAt, 'removed');
    }

    private function confirmedRegistration(ProviderWriteResponse $response, GraphSubscriptionConfiguration $configuration, int $status, ?string $subscriptionId = null): GraphSubscriptionRegistration
    {
        $this->guard->assertActive($this->context);
        $data = json_decode($response->body() ?? '');
        $expiresAt = $data instanceof \stdClass ? self::parseExpiry($data->expirationDateTime ?? null) : null;
        if ($response->status !== $status || ! $data instanceof \stdClass
            || ! is_string($data->id ?? null) || ($subscriptionId !== null && $data->id !== $subscriptionId)
            || ($data->resource ?? null) !== $configuration->resource($this->context)
            || ($data->clientState ?? null) !== $configuration->clientState()
            || $expiresAt === null) {
            throw new ApiRequestException('The Graph subscription acknowledgement could not be verified; retain the original attempt before retrying.', status: $response->status, headers: $response->headers);
        }

        return new GraphSubscriptionRegistration($this->context, $configuration, $data->id, $expiresAt);
    }

    private static function parseExpiry(#[\SensitiveParameter] mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,7})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/', $value) !== 1) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value);

            return DateTimeImmutable::getLastErrors() === false && abs($date->getOffset()) <= 50_400 ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function assertRegistration(GraphSubscriptionRegistration $registration): void
    {
        if (! $registration->context->matches($this->context)) {
            throw new InvalidSyncStateException('The Graph subscription belongs to a different connection, grant, mailbox or lifecycle generation.');
        }
        $this->guard->assertActive($this->context);
    }
}

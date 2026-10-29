<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use DateTimeImmutable;
use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Pyle\Mailbox\Contracts\GrantAccessGuard;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\GraphSubscriptionConfiguration;
use Pyle\Mailbox\DTOs\GraphSubscriptionRegistration;
use Pyle\Mailbox\DTOs\GraphChangeHint;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\InvalidSyncStateException;
use Pyle\Mailbox\Exceptions\InvalidChangeNotificationException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Support\ProviderWriteResponse;
use Pyle\Mailbox\Support\ResponseBudget;

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

    public function handshake(#[\SensitiveParameter] RequestInterface $request, GraphSubscriptionConfiguration $configuration): ResponseInterface
    {
        $this->guard->assertActive($this->context);
        $query = Query::parse($request->getUri()->getQuery());
        $token = $query['validationToken'] ?? null;
        if ($request->getMethod() !== 'POST' || strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0])) !== 'text/plain'
            || ! is_string($token) || $token === '' || strlen($request->getUri()->getQuery()) > 65_536 || preg_match('/[\x00-\x1f\x7f]/', $token)
            || (! self::callbackUrlMatches($request, $configuration->notificationUrl, true) && ! self::callbackUrlMatches($request, $configuration->lifecycleNotificationUrl, true))) {
            throw new InvalidChangeNotificationException('The Graph validation request does not match a configured endpoint or valid opaque token.');
        }
        $this->guard->assertActive($this->context);

        return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], $token);
    }

    private static function callbackUrlMatches(#[\SensitiveParameter] RequestInterface $request, #[\SensitiveParameter] string $url, bool $validation = false): bool
    {
        $expected = new Uri($url);
        $query = Query::parse($request->getUri()->getQuery());
        if ($validation) {
            unset($query['validationToken']);
        }

        return (string) $request->getUri()->withQuery('') === (string) $expected->withQuery('') && $query === Query::parse($expected->getQuery());
    }

    /**
     * @param list<GraphSubscriptionRegistration> $registrations
     * @return list<GraphChangeHint>
     */
    public function notifications(#[\SensitiveParameter] RequestInterface $request, array $registrations, int $maxRequestBytes): array
    {
        $known = [];
        foreach ($registrations as $registration) {
            $this->assertRegistration($registration);
            if (isset($known[$registration->subscriptionId])) {
                throw new InvalidSyncStateException('Graph callback registrations must have distinct subscription identities.');
            }
            $known[$registration->subscriptionId] = $registration;
        }
        if ($known === [] || ! array_is_list($registrations) || $request->getMethod() !== 'POST'
            || strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0])) !== 'application/json'
            || ! in_array(strtolower(trim($request->getHeaderLine('Content-Encoding'))), ['', 'identity'], true)) {
            throw new InvalidChangeNotificationException('Graph notifications require known registrations and an identity-encoded JSON POST.');
        }
        $budget = new ResponseBudget($maxRequestBytes);
        $body = '';
        try {
            $stream = $request->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            while (true) {
                $chunk = $stream->read(min(8192, $budget->remaining()) + 1);
                if ($chunk === '' && $stream->eof()) {
                    break;
                }
                if ($chunk === '') {
                    throw new \RuntimeException;
                }
                $budget->consume(strlen($chunk));
                $body .= $chunk;
            }
            $contentLength = $request->getHeaderLine('Content-Length');
            if ($request->hasHeader('Content-Length') && (! ctype_digit($contentLength)
                || ltrim($contentLength, '0') !== ltrim((string) strlen($body), '0'))) {
                throw new \RuntimeException;
            }
            $envelope = json_decode($body, flags: JSON_THROW_ON_ERROR, depth: 16);
        } catch (\Throwable) {
            throw new InvalidChangeNotificationException('The Graph notification body is unreadable, malformed or exceeds its byte budget.');
        }
        if (! $envelope instanceof \stdClass || ! is_array($envelope->value ?? null) || ! array_is_list($envelope->value) || $envelope->value === []) {
            throw new InvalidChangeNotificationException('Graph notifications require a non-empty notification collection.');
        }
        if (property_exists($envelope, 'validationTokens')) {
            throw new UnsupportedCapabilityException('Rich encrypted Graph notifications and token/decryption management are unsupported.');
        }
        $hints = [];
        foreach ($envelope->value as $event) {
            if (! $event instanceof \stdClass || ! is_string($event->subscriptionId ?? null) || ! isset($known[$event->subscriptionId])) {
                throw new InvalidChangeNotificationException('The Graph notification does not match a known subscription.');
            }
            $registration = $known[$event->subscriptionId];
            $configuration = $registration->configuration;
            $lifecycle = property_exists($event, 'lifecycleEvent');
            $expiry = self::parseExpiry($event->subscriptionExpirationDateTime ?? null);
            if (! is_string($event->clientState ?? null) || ! hash_equals($configuration->clientState(), $event->clientState)
                || ($event->tenantId ?? null) !== $configuration->tenantId || $expiry === null || $expiry > $registration->expiresAt
                || ! self::callbackUrlMatches($request, $lifecycle ? $configuration->lifecycleNotificationUrl : $configuration->notificationUrl)) {
                throw new InvalidChangeNotificationException('The Graph notification secret, tenant, expiry or endpoint does not match its registration.');
            }
            if (property_exists($event, 'encryptedContent')) {
                throw new UnsupportedCapabilityException('Rich encrypted Graph notifications and certificate/decryption management are unsupported.');
            }
            $reason = $registration->lifecycle() === 'active' ? 'mailbox_changed' : 'subscription_'.$registration->lifecycle();
            if ($lifecycle) {
                if (! in_array($event->lifecycleEvent, ['missed', 'subscriptionRemoved', 'reauthorizationRequired'], true)
                    || property_exists($event, 'changeType') || property_exists($event, 'resource') || property_exists($event, 'resourceData')) {
                    throw new InvalidChangeNotificationException('The Graph lifecycle notification does not match a supported subscription event.');
                }
                $reason = $event->lifecycleEvent;
            } else {
                $messageId = $this->notificationMessageId($event->resource ?? null, $configuration);
                if (! in_array($event->changeType ?? null, ['created', 'updated', 'deleted'], true) || $messageId === null) {
                    throw new InvalidChangeNotificationException('The Graph notification does not match the registered message resource.');
                }
                if (property_exists($event, 'resourceData')) {
                    $data = $event->resourceData;
                    if (! $data instanceof \stdClass || ($data->id ?? null) !== $messageId
                        || (property_exists($data, '@odata.type') && (! is_string($data->{'@odata.type'}) || strcasecmp($data->{'@odata.type'}, '#Microsoft.Graph.Message') !== 0))
                        || (property_exists($data, '@odata.etag') && (! is_string($data->{'@odata.etag'}) || preg_match('/[\x00-\x1f\x7f]/', $data->{'@odata.etag'})))
                        || (property_exists($data, '@odata.id') && $this->notificationMessageId($data->{'@odata.id'}, $configuration) !== $messageId)) {
                        throw new InvalidChangeNotificationException('Basic Graph notification identity metadata does not match its resource.');
                    }
                    if (array_diff(array_keys(get_object_vars($data)), ['id', '@odata.id', '@odata.type', '@odata.etag']) !== []) {
                        throw new UnsupportedCapabilityException('Graph notifications support basic identity metadata without resource payload or decryption.');
                    }
                }
            }
            $id = $event->id ?? null;
            if ($id !== null && (! is_string($id) || $id === '' || strlen($id) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $id))) {
                throw new InvalidChangeNotificationException('The Graph notification identity is malformed.');
            }
            $hints[] = new GraphChangeHint($this->context, $registration->subscriptionId, $configuration->resource($this->context), $reason, $id);
        }
        $this->guard->assertActive($this->context);

        return $hints;
    }

    private function notificationMessageId(#[\SensitiveParameter] mixed $resource, GraphSubscriptionConfiguration $configuration): ?string
    {
        return $this->resourceParts($resource, $configuration)['message'] ?? null;
    }

    /** @return array{folder: ?string, message: ?string}|null */
    private function resourceParts(#[\SensitiveParameter] mixed $resource, GraphSubscriptionConfiguration $configuration): ?array
    {
        if (! is_string($resource)
            || (preg_match('~\A/?users/([^/]+)/(?:mailFolders/([^/]+)/)?messages(?:/([^/]+))?\z~i', $resource, $parts, PREG_UNMATCHED_AS_NULL) !== 1
                && preg_match("~\\A/?users\\('([^']+)'\\)/(?:mailFolders\\('([^']+)'\\)/)?messages(?:\\('([^']+)'\\))?\\z~i", $resource, $parts, PREG_UNMATCHED_AS_NULL) !== 1)) {
            return null;
        }
        $mailbox = rawurldecode($parts[1]);
        $folder = $parts[2] === null ? null : rawurldecode($parts[2]);
        $message = $parts[3] === null ? null : rawurldecode($parts[3]);
        $knownFolders = array_map(static fn (WellKnownFolder $value): string => strtolower($value->forDriver('ms-graph')), WellKnownFolder::cases());
        $expectedFolder = $configuration->folderId;
        if (($mailbox !== $configuration->providerMailboxId && $mailbox !== $configuration->providerMailboxId.'@'.$configuration->tenantId && strcasecmp($mailbox, $this->context->mailbox) !== 0)
            || ($folder !== null && $expectedFolder !== null && $folder !== $expectedFolder
                && (! in_array(strtolower($expectedFolder), $knownFolders, true) || strcasecmp($folder, $expectedFolder) !== 0))
            || ($folder !== null && preg_match('/[\x00-\x1f\x7f?#\\\\]/', $folder))
            || ($message !== null && ($message === '' || preg_match('/[\x00-\x20\x7f?#\\\\]/', $message)))) {
            return null;
        }

        return ['folder' => $folder, 'message' => $message];
    }

    private function confirmedRegistration(ProviderWriteResponse $response, GraphSubscriptionConfiguration $configuration, int $status, ?string $subscriptionId = null): GraphSubscriptionRegistration
    {
        $this->guard->assertActive($this->context);
        $data = json_decode($response->body() ?? '');
        $expiresAt = $data instanceof \stdClass ? self::parseExpiry($data->expirationDateTime ?? null) : null;
        $resource = $data instanceof \stdClass ? $this->resourceParts($data->resource ?? null, $configuration) : null;
        $changes = $data instanceof \stdClass && is_string($data->changeType ?? null) ? explode(',', $data->changeType) : [];
        sort($changes);
        if ($response->status !== $status || ! $data instanceof \stdClass
            || ! is_string($data->id ?? null) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._=-]{0,1023}\z/', $data->id) !== 1
            || ($subscriptionId !== null && $data->id !== $subscriptionId)
            || $resource === null || $resource['message'] !== null || (($resource['folder'] === null) !== ($configuration->folderId === null))
            || ($data->clientState ?? null) !== $configuration->clientState()
            || ($data->notificationUrl ?? null) !== $configuration->notificationUrl || ($data->lifecycleNotificationUrl ?? null) !== $configuration->lifecycleNotificationUrl
            || $changes !== ['created', 'deleted', 'updated']
            || (property_exists($data, 'includeResourceData') && $data->includeResourceData !== false)
            || ! in_array($data->encryptionCertificate ?? null, [null, ''], true) || ! in_array($data->encryptionCertificateId ?? null, [null, ''], true)
            || $expiresAt === null || $expiresAt <= new DateTimeImmutable || $expiresAt > new DateTimeImmutable('+10080 minutes')) {
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

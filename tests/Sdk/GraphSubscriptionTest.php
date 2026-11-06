<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Pyle\Mailbox\Drivers\MsGraph\Contracts\SupportsGraphSubscriptions;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\RateLimiter;
use Pyle\Mailbox\Drivers\MsGraph\TokenManager;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\GraphSubscriptionConfiguration;
use Pyle\Mailbox\DTOs\GraphSubscriptionRegistration;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\InvalidChangeNotificationException;
use Pyle\Mailbox\Exceptions\InvalidSyncStateException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Support\GrantAuthorization;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('creates a basic Graph subscription under the selected grant with trusted canonical mailbox binding', function (): void {
    $expiresAt = new DateTimeImmutable('+1 day');
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(201, [], json_encode([
            'id' => 'subscription-1',
            'resource' => 'users/shared%40example.com/mailFolders/inbox/messages',
            'changeType' => 'created,updated,deleted',
            'notificationUrl' => 'https://host.example/graph/changes',
            'lifecycleNotificationUrl' => 'https://host.example/graph/lifecycle',
            'clientState' => 'private-client-state',
            'includeResourceData' => false,
            'expirationDateTime' => $expiresAt->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR)),
    ]));
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'base_uri' => 'https://graph.microsoft.com/v1.0/', 'auth' => ['unrelated-user', 'unrelated-password']]);
    $context = new GrantContext('connection', 'grant', '1', 'ms-graph', 'shared@example.com');
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['shared@example.com'], 'token' => 'selected-token'],
    ]);
    $mailbox = Mailbox::forGrant($context, $tokens, $http);

    expect($mailbox)->toBeInstanceOf(SupportsGraphSubscriptions::class);

    $registration = $mailbox->createSubscription(new GraphSubscriptionConfiguration(
        tenantId: 'tenant-1',
        providerMailboxId: 'canonical-user-1',
        notificationUrl: 'https://host.example/graph/changes',
        lifecycleNotificationUrl: 'https://host.example/graph/lifecycle',
        clientState: 'private-client-state',
        folderId: 'inbox',
    ), $expiresAt);

    expect($registration->subscriptionId)->toBe('subscription-1')
        ->and($registration->context)->toBe($context)
        ->and($registration->expiresAt->getTimestamp())->toBe($expiresAt->getTimestamp())
        ->and($registration->configuration->providerMailboxId)->toBe('canonical-user-1')
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getMethod())->toBe('POST')
        ->and((string) $history[0]['request']->getUri())->toBe('https://graph.microsoft.com/v1.0/subscriptions')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer selected-token')
        ->and(json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR))->toMatchArray([
            'resource' => 'users/shared%40example.com/mailFolders/inbox/messages',
            'includeResourceData' => false,
            'clientState' => 'private-client-state',
        ])
        ->and(config('database.default'))->toBe('sdk-forbidden');

    expect(json_encode($registration, JSON_THROW_ON_ERROR).print_r($registration, true))->not->toContain('private-client-state');
});

it('renews and removes the retained Graph subscription without creating another registration', function (): void {
    $history = [];
    $expiresAt = new DateTimeImmutable('+1 day');
    $mailbox = graphSubscriptionMailbox([
        new Response(200, [], json_encode(graphSubscriptionAcknowledgement($expiresAt), JSON_THROW_ON_ERROR)),
        new Response(204, ['request-id' => 'delete-1']),
    ], $history);
    $registration = graphSubscriptionRegistration();

    $renewed = $mailbox->renewSubscription(unserialize(serialize($registration)), $expiresAt);
    $removed = $mailbox->removeSubscription($renewed);

    expect($renewed->subscriptionId)->toBe('subscription-1')
        ->and($renewed->expiresAt->getTimestamp())->toBe($expiresAt->getTimestamp())
        ->and($removed->status)->toBe('removed')
        ->and($removed->context)->toEqual($registration->context)
        ->and($history)->toHaveCount(2)
        ->and($history[0]['request']->getMethod())->toBe('PATCH')
        ->and((string) $history[0]['request']->getUri())->toBe('https://graph.microsoft.com/v1.0/subscriptions/subscription-1')
        ->and(json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR))->toBe(['expirationDateTime' => $expiresAt->format(DATE_ATOM)])
        ->and($history[1]['request']->getMethod())->toBe('DELETE')
        ->and((string) $history[1]['request']->getBody())->toBe('')
        ->and($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer selected-token');
});

it('rejects another complete context before a typed subscription request can acquire the captured token', function (GrantContext $other): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([]));
    $stack->push(Middleware::history($history));
    $context = new GrantContext('connection', 'grant', '1', 'ms-graph', 'shared@example.com');
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['shared@example.com'], 'token' => 'selected-token'],
    ]);
    $client = new GraphClient([], new TokenManager([]), new RateLimiter([]), new Client(['handler' => $stack]), new GrantAuthorization($context, $tokens));
    $configuration = new GraphSubscriptionConfiguration('tenant-1', 'canonical-user-1', 'https://host.example/graph/changes', 'https://host.example/graph/lifecycle', 'private-client-state');

    expect(fn () => $client->createBasicSubscription($other, $configuration, new DateTimeImmutable('+1 day')))->toThrow(GrantAccessDeniedException::class)
        ->and($tokens->tokenReads)->toBe(0)
        ->and($history)->toBe([]);
})->with([
    'connection' => [new GrantContext('other', 'grant', '1', 'ms-graph', 'shared@example.com')],
    'grant' => [new GrantContext('connection', 'other', '1', 'ms-graph', 'shared@example.com')],
    'generation' => [new GrantContext('connection', 'grant', '2', 'ms-graph', 'shared@example.com')],
    'provider' => [new GrantContext('connection', 'grant', '1', 'gmail', 'shared@example.com')],
    'mailbox' => [new GrantContext('connection', 'grant', '1', 'ms-graph', 'other@example.com')],
]);

it('preserves legacy Graph scope rejection and requires captured authorization for the new capability', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([]));
    $stack->push(Middleware::history($history));
    $client = new GraphClient([], new TokenManager([]), new RateLimiter([]), new Client(['handler' => $stack]));
    $context = new GrantContext('connection', 'grant', '1', 'ms-graph', 'shared@example.com');
    $configuration = new GraphSubscriptionConfiguration('tenant-1', 'canonical-user-1', 'https://host.example/graph/changes', 'https://host.example/graph/lifecycle', 'private-client-state');

    expect(fn () => $client->createBasicSubscription($context, $configuration, new DateTimeImmutable('+1 day')))->toThrow(UnsupportedCapabilityException::class)
        ->and(fn () => $client->requestOnce('POST', 'subscriptions', mailbox: 'shared@example.com'))->toThrow(ApiRequestException::class)
        ->and(fn () => $client->requestOnce('GET', 'users/other%40example.com/messages', mailbox: 'shared@example.com'))->toThrow(ApiRequestException::class)
        ->and(fn () => $client->requestOnce('GET', 'https://evil.example/v1.0/users/shared%40example.com/messages', mailbox: 'shared@example.com'))->toThrow(ApiRequestException::class)
        ->and($history)->toBe([]);
});

it('rejects an invalid subscription expiry before provider IO', function (string $expiry): void {
    $history = [];
    $mailbox = graphSubscriptionMailbox([], $history);

    expect(fn () => $mailbox->createSubscription(graphSubscriptionConfiguration(), new DateTimeImmutable($expiry)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $mailbox->renewSubscription(graphSubscriptionRegistration(), new DateTimeImmutable($expiry)))->toThrow(InvalidArgumentException::class)
        ->and($history)->toBe([]);
})->with(['expired' => '-1 minute', 'beyond provider maximum' => '+8 days']);

it('keeps a malformed subscription acknowledgement private and does not replay the write', function (): void {
    $history = [];
    $acknowledgement = graphSubscriptionAcknowledgement(new DateTimeImmutable('+1 day'));
    $acknowledgement['expirationDateTime'] = 'private-invalid-provider-value';
    $mailbox = graphSubscriptionMailbox([
        new Response(201, ['request-id' => 'create-uncertain'], json_encode($acknowledgement, JSON_THROW_ON_ERROR)),
        new Response(201, [], json_encode(graphSubscriptionAcknowledgement(new DateTimeImmutable('+1 day')), JSON_THROW_ON_ERROR)),
    ], $history);

    try {
        $mailbox->createSubscription(graphSubscriptionConfiguration(), new DateTimeImmutable('+1 day'));
        test()->fail('An invalid provider expiry cannot confirm a subscription.');
    } catch (ApiRequestException $exception) {
        expect($exception->status)->toBe(201)
            ->and($exception->headers)->toBe(['request-id' => ['create-uncertain']])
            ->and((string) $exception)->not->toContain('private-invalid-provider-value')
            ->and((string) $exception)->not->toContain('private-client-state');
    }

    expect($history)->toHaveCount(1);
});

it('returns the URL-decoded opaque Graph validation token as a plain-text handshake response', function (string $endpoint): void {
    $history = [];
    $mailbox = graphSubscriptionMailbox([], $history);
    $request = new Request('POST', $endpoint.'?validationToken=opaque%20%2B%2F%25token', ['Content-Type' => 'text/plain; charset=utf-8']);

    $response = $mailbox->subscriptionHandshake($request, graphSubscriptionConfiguration());

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getHeaderLine('Content-Type'))->toBe('text/plain; charset=utf-8')
        ->and((string) $response->getBody())->toBe('opaque +/%token')
        ->and($history)->toBe([])
        ->and(config('database.default'))->toBe('sdk-forbidden');
})->with(['changes' => 'https://host.example/graph/changes', 'lifecycle' => 'https://host.example/graph/lifecycle']);

it('validates a basic Graph notification batch with canonical identity metadata as scoped reconciliation hints', function (): void {
    $history = [];
    $mailbox = graphSubscriptionMailbox([], $history);
    $registration = graphSubscriptionRegistration();
    $first = graphSubscriptionEvent($registration);
    $second = $first;
    $second['id'] = 'older-notification';
    $second['resource'] = 'Users/canonical-user-1/Messages/message-1=';
    $second['resourceData']['@odata.id'] = 'Users/canonical-user-1/Messages/message-1=';
    Event::fake();
    Queue::fake();

    $hints = $mailbox->subscriptionNotifications(new Request('POST', 'https://host.example/graph/changes', ['Content-Type' => 'application/json'], json_encode(['value' => [$first, $second, $first]], JSON_THROW_ON_ERROR)), [$registration]);

    expect($hints)->toHaveCount(3)
        ->and($hints[0]->context)->toEqual($registration->context)
        ->and($hints[0]->subscriptionId)->toBe('subscription-1')
        ->and($hints[0]->resource)->toBe('users/shared%40example.com/mailFolders/inbox/messages')
        ->and(array_column($hints, 'reason'))->toBe(['mailbox_changed', 'mailbox_changed', 'mailbox_changed'])
        ->and(array_column($hints, 'notificationId'))->toBe(['notification-1', 'older-notification', 'notification-1'])
        ->and($history)->toBe([]);
    $public = json_encode($hints, JSON_THROW_ON_ERROR).print_r($hints, true);
    expect($public)->not->toContain('private-client-state');
    expect($public)->not->toContain('resourceData');
    expect($public)->not->toContain('checkpoint');
    Event::assertNothingDispatched();
    Queue::assertNothingPushed();
});

it('surfaces Graph lifecycle signals using retained subscription binding without a resource in the callback', function (string $reason): void {
    $history = [];
    $mailbox = graphSubscriptionMailbox([], $history);
    $registration = graphSubscriptionRegistration();
    $event = graphSubscriptionEvent($registration);
    unset($event['resource'], $event['resourceData'], $event['changeType']);
    $event['lifecycleEvent'] = $reason;

    $hints = $mailbox->subscriptionNotifications(new Request('POST', 'https://host.example/graph/lifecycle', ['Content-Type' => 'application/json'], json_encode(['value' => [$event, $event]], JSON_THROW_ON_ERROR)), [$registration]);

    expect($hints)->toHaveCount(2)
        ->and($hints[0]->reason)->toBe($reason)
        ->and($hints[0]->resource)->toBe('users/shared%40example.com/mailFolders/inbox/messages')
        ->and($hints[0]->context)->toEqual($registration->context)
        ->and($history)->toBe([]);
})->with(['missed', 'subscriptionRemoved', 'reauthorizationRequired']);

it('explicitly rejects rich Graph subscription setup before provider IO', function (array $unsupported): void {
    $history = [];
    $mailbox = graphSubscriptionMailbox([], $history);

    expect(fn () => $mailbox->createSubscription(new GraphSubscriptionConfiguration(...[
        'tenantId' => 'tenant-1', 'providerMailboxId' => 'canonical-user-1',
        'notificationUrl' => 'https://host.example/graph/changes', 'lifecycleNotificationUrl' => 'https://host.example/graph/lifecycle',
        'clientState' => 'private-client-state', ...$unsupported,
    ]), new DateTimeImmutable('+1 day')))->toThrow(UnsupportedCapabilityException::class)
        ->and($history)->toBe([]);
})->with([
    'rich mode' => [['includeResourceData' => true]],
    'encryption certificate' => [['encryptionCertificate' => 'private-certificate']],
    'certificate management' => [['encryptionCertificateId' => 'certificate-1']],
]);

it('rejects every untrusted basic Graph event before returning any batch hints', function (array $changes): void {
    $history = [];
    $mailbox = graphSubscriptionMailbox([], $history);
    $registration = graphSubscriptionRegistration();
    $valid = graphSubscriptionEvent($registration);
    $invalid = array_replace_recursive($valid, $changes);
    $hints = null;

    expect(function () use ($mailbox, $registration, $valid, $invalid, &$hints): void {
        $hints = $mailbox->subscriptionNotifications(graphSubscriptionRequest([$valid, $invalid]), [$registration]);
    })->toThrow(InvalidChangeNotificationException::class)
        ->and($hints)->toBeNull()
        ->and($history)->toBe([]);
})->with([
    'wrong secret' => [['clientState' => 'wrong-secret']],
    'malformed secret' => [['clientState' => false]],
    'wrong tenant' => [['tenantId' => 'other-tenant']],
    'unknown subscription' => [['subscriptionId' => 'unknown-subscription']],
    'wrong mailbox' => [['resource' => 'users/another-mailbox/messages/message-1=']],
    'wrong resource tenant' => [['resource' => 'users/canonical-user-1@other-tenant/messages/message-1=']],
    'wrong folder' => [['resource' => 'users/canonical-user-1/mailFolders/other/messages/message-1=']],
    'untrusted absolute resource' => [['resource' => 'https://evil.example/users/canonical-user-1/messages/message-1=']],
    'resource query' => [['resource' => 'users/canonical-user-1/messages/message-1=?unexpected=true']],
    'conflicting metadata identity' => [['resourceData' => ['id' => 'different-message']]],
    'conflicting metadata path' => [['resourceData' => ['@odata.id' => 'Users/another-mailbox/Messages/message-1=']]],
    'conflicting metadata type' => [['resourceData' => ['@odata.type' => '#Microsoft.Graph.Event']]],
    'malformed metadata' => [['resourceData' => null]],
    'malformed etag metadata' => [['resourceData' => ['@odata.etag' => []]]],
    'malformed expiry' => [['subscriptionExpirationDateTime' => 'private-invalid-expiry']],
    'forged later expiry' => [['subscriptionExpirationDateTime' => '2099-01-01T00:00:00Z']],
    'unknown change type' => [['changeType' => 'futureChange']],
    'mixed change and lifecycle' => [['lifecycleEvent' => 'missed']],
    'malformed notification identity' => [['id' => []]],
]);

it('rejects malformed Graph notification collections and wrong callback transport', function (string $body, string $method, string $url, array $headers): void {
    $history = [];
    $mailbox = graphSubscriptionMailbox([], $history);

    expect(fn () => $mailbox->subscriptionNotifications(new Request($method, $url, $headers, $body), [graphSubscriptionRegistration()]))->toThrow(InvalidChangeNotificationException::class)
        ->and($history)->toBe([]);
})->with([
    'malformed JSON' => ['{', 'POST', 'https://host.example/graph/changes', ['Content-Type' => 'application/json']],
    'root list' => ['[]', 'POST', 'https://host.example/graph/changes', ['Content-Type' => 'application/json']],
    'missing collection' => ['{}', 'POST', 'https://host.example/graph/changes', ['Content-Type' => 'application/json']],
    'object collection' => ['{"value":{}}', 'POST', 'https://host.example/graph/changes', ['Content-Type' => 'application/json']],
    'empty collection' => ['{"value":[]}', 'POST', 'https://host.example/graph/changes', ['Content-Type' => 'application/json']],
    'primitive event' => ['{"value":[false]}', 'POST', 'https://host.example/graph/changes', ['Content-Type' => 'application/json']],
    'wrong method' => ['{}', 'GET', 'https://host.example/graph/changes', ['Content-Type' => 'application/json']],
    'wrong content type' => ['{}', 'POST', 'https://host.example/graph/changes', ['Content-Type' => 'text/plain']],
    'encoded request' => ['{}', 'POST', 'https://host.example/graph/changes', ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip']],
]);

it('bounds actual callback reads without closing the host-owned body', function (): void {
    $history = [];
    $mailbox = graphSubscriptionMailbox([], $history);
    $registration = graphSubscriptionRegistration();
    $body = Utils::streamFor(json_encode(['value' => [graphSubscriptionEvent($registration)]], JSON_THROW_ON_ERROR));
    $read = 0;
    $stream = FnStream::decorate($body, ['read' => function (int $length) use ($body, &$read): string {
        $chunk = $body->read($length);
        $read += strlen($chunk);

        return $chunk;
    }]);

    expect(fn () => $mailbox->subscriptionNotifications(new Request('POST', 'https://host.example/graph/changes', ['Content-Type' => 'application/json'], $stream), [$registration], 64))->toThrow(InvalidChangeNotificationException::class)
        ->and($read)->toBe(65)
        ->and($body->isReadable())->toBeTrue()
        ->and($history)->toBe([]);
});

it('rejects a truncated raw callback even when its received JSON is syntactically valid', function (): void {
    $history = [];
    $mailbox = graphSubscriptionMailbox([], $history);
    $registration = graphSubscriptionRegistration();
    $body = json_encode(['value' => [graphSubscriptionEvent($registration)]], JSON_THROW_ON_ERROR);

    expect(fn () => $mailbox->subscriptionNotifications(new Request('POST', 'https://host.example/graph/changes', [
        'Content-Type' => 'application/json', 'Content-Length' => (string) (strlen($body) + 1),
    ], $body), [$registration]))->toThrow(InvalidChangeNotificationException::class)
        ->and($history)->toBe([]);
});

it('keeps rejected raw callback secrets out of exception traces and unreadable-stream errors', function (): void {
    $history = [];
    $mailbox = graphSubscriptionMailbox([], $history);
    $body = FnStream::decorate(Utils::streamFor('private-body'), ['read' => static function (int $length): string {
        throw new RuntimeException('private-client-state private-provider-body');
    }]);
    $previous = ini_set('zend.exception_ignore_args', '0');
    try {
        $mailbox->subscriptionNotifications(new Request('POST', 'https://host.example/graph/changes', ['Content-Type' => 'application/json'], $body), [graphSubscriptionRegistration()]);
        test()->fail('An unreadable callback must not return hints.');
    } catch (InvalidChangeNotificationException $exception) {
        $public = (string) $exception.json_encode($exception->getTrace(), JSON_THROW_ON_ERROR).print_r($exception->getTrace(), true);
        expect($public)->not->toContain('private-client-state');
        expect($public)->not->toContain('private-provider-body');
        expect($public)->not->toContain('private-body');
        expect($exception->getPrevious())->toBeNull();
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
});

it('accepts documented canonical OData subscription resources and retains the configured folder scope', function (): void {
    $history = [];
    $expiresAt = new DateTimeImmutable('+1 day');
    $acknowledgement = graphSubscriptionAcknowledgement($expiresAt);
    $acknowledgement['resource'] = "Users('canonical-user-1')/mailFolders('Inbox')/messages";
    $mailbox = graphSubscriptionMailbox([new Response(201, [], json_encode($acknowledgement, JSON_THROW_ON_ERROR))], $history);

    $registration = $mailbox->createSubscription(graphSubscriptionConfiguration(), $expiresAt);
    $event = graphSubscriptionEvent($registration);
    $event['resource'] = "Users('canonical-user-1')/messages('message-1=')";
    $event['resourceData']['@odata.id'] = "Users('canonical-user-1')/messages('message-1=')";
    $hints = $mailbox->subscriptionNotifications(graphSubscriptionRequest([$event]), [$registration]);

    expect($hints[0]->resource)->toBe('users/shared%40example.com/mailFolders/inbox/messages')
        ->and($history)->toHaveCount(1);
});

it('refuses contradictory subscription acknowledgements without replay or secret disclosure', function (array $changes): void {
    $history = [];
    $expiresAt = new DateTimeImmutable('+1 day');
    $mailbox = graphSubscriptionMailbox([new Response(201, ['request-id' => 'uncertain-create'], json_encode(array_replace(graphSubscriptionAcknowledgement($expiresAt), $changes), JSON_THROW_ON_ERROR))], $history);

    expect(fn () => $mailbox->createSubscription(graphSubscriptionConfiguration(), $expiresAt))->toThrow(ApiRequestException::class)
        ->and($history)->toHaveCount(1);
})->with([
    'malformed subscription identity' => [['id' => 'https://private.invalid/identity']],
    'another mailbox' => [['resource' => 'users/another-mailbox/mailFolders/inbox/messages']],
    'another folder' => [['resource' => 'users/shared%40example.com/mailFolders/other/messages']],
    'lost folder scope' => [['resource' => 'users/shared%40example.com/messages']],
    'wrong secret' => [['clientState' => 'wrong-secret']],
    'wrong callback' => [['notificationUrl' => 'https://other.example/changes']],
    'wrong lifecycle callback' => [['lifecycleNotificationUrl' => 'https://other.example/lifecycle']],
    'different changes' => [['changeType' => 'created']],
    'unexpected rich mode' => [['includeResourceData' => true]],
    'unexpected certificate' => [['encryptionCertificate' => 'private-certificate']],
    'already expired' => [['expirationDateTime' => '2020-01-01T00:00:00Z']],
    'invalid calendar date' => [['expirationDateTime' => '2099-02-31T00:00:00Z']],
]);

it('rejects rich callback envelopes instead of treating encrypted or full resource data as basic metadata', function (string $mode): void {
    $history = [];
    $mailbox = graphSubscriptionMailbox([], $history);
    $registration = graphSubscriptionRegistration();
    $event = graphSubscriptionEvent($registration);
    if ($mode === 'encrypted') {
        $event['encryptedContent'] = ['data' => 'private-encrypted-content'];
    } elseif ($mode === 'payload') {
        $event['resourceData']['subject'] = 'private-provider-content';
    }
    $body = ['value' => [$event]];
    if ($mode === 'tokens') {
        $body['validationTokens'] = ['private-rich-validation-token'];
    }

    expect(fn () => $mailbox->subscriptionNotifications(new Request('POST', 'https://host.example/graph/changes', ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)), [$registration]))->toThrow(UnsupportedCapabilityException::class)
        ->and($history)->toBe([]);
})->with(['encrypted', 'payload', 'tokens']);

it('retains expired or removed registration hints while refusing to renew inactive state', function (string $status, string $expiry, string $reason): void {
    $history = [];
    $mailbox = graphSubscriptionMailbox([], $history);
    $original = graphSubscriptionRegistration(new DateTimeImmutable($expiry));
    $registration = new GraphSubscriptionRegistration($original->context, $original->configuration, $original->subscriptionId, $original->expiresAt, $status);

    $hints = $mailbox->subscriptionNotifications(graphSubscriptionRequest([graphSubscriptionEvent($registration)]), [$registration]);

    expect($registration->lifecycle())->toBe($status === 'active' ? 'expired' : 'removed')
        ->and($hints[0]->reason)->toBe($reason)
        ->and(fn () => $mailbox->renewSubscription($registration, new DateTimeImmutable('+1 day')))->toThrow(InvalidSyncStateException::class)
        ->and($history)->toBe([]);
})->with([
    'expired' => ['active', '-1 day', 'subscription_expired'],
    'removed' => ['removed', '+1 day', 'subscription_removed'],
]);

it('rejects a registration from another captured context before HTTP or callback parsing', function (GrantContext $context): void {
    $history = [];
    $mailbox = graphSubscriptionMailbox([], $history);
    $registration = new GraphSubscriptionRegistration($context, graphSubscriptionConfiguration(), 'subscription-1', new DateTimeImmutable('+1 day'));

    expect(fn () => $mailbox->renewSubscription($registration, new DateTimeImmutable('+1 day')))->toThrow(InvalidSyncStateException::class)
        ->and(fn () => $mailbox->removeSubscription($registration))->toThrow(InvalidSyncStateException::class)
        ->and(fn () => $mailbox->subscriptionNotifications(graphSubscriptionRequest([graphSubscriptionEvent($registration)]), [$registration]))->toThrow(InvalidSyncStateException::class)
        ->and($history)->toBe([]);
})->with([
    'connection' => [new GrantContext('other', 'grant', '1', 'ms-graph', 'shared@example.com')],
    'grant' => [new GrantContext('connection', 'other', '1', 'ms-graph', 'shared@example.com')],
    'generation' => [new GrantContext('connection', 'grant', '2', 'ms-graph', 'shared@example.com')],
    'mailbox' => [new GrantContext('connection', 'grant', '1', 'ms-graph', 'other@example.com')],
]);

it('rejects grant revocation during subscription HTTP or raw callback reading', function (string $phase): void {
    $history = [];
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['shared@example.com'], 'token' => 'selected-token'],
    ]);
    $mailbox = graphSubscriptionMailbox($phase === 'http' ? [function () use ($tokens): Response {
        $tokens->grants['grant']['generation'] = '2';

        return new Response(201, [], json_encode(graphSubscriptionAcknowledgement(new DateTimeImmutable('+1 day')), JSON_THROW_ON_ERROR));
    }] : [], $history, $tokens);
    if ($phase === 'http') {
        expect(fn () => $mailbox->createSubscription(graphSubscriptionConfiguration(), new DateTimeImmutable('+1 day')))->toThrow(GrantAccessDeniedException::class)
            ->and($history)->toHaveCount(1);
    } else {
        $registration = graphSubscriptionRegistration();
        $body = Utils::streamFor(json_encode(['value' => [graphSubscriptionEvent($registration)]], JSON_THROW_ON_ERROR));
        $stream = FnStream::decorate($body, ['read' => function (int $length) use ($body, $tokens): string {
            $tokens->grants['grant']['generation'] = '2';

            return $body->read($length);
        }]);
        expect(fn () => $mailbox->subscriptionNotifications(new Request('POST', 'https://host.example/graph/changes', ['Content-Type' => 'application/json'], $stream), [$registration]))->toThrow(GrantAccessDeniedException::class)
            ->and($tokens->tokenReads)->toBe(0)
            ->and($history)->toBe([]);
    }
})->with(['http', 'callback']);

it('rejects malformed or unbound raw Graph handshakes', function (string $method, string $url, string $contentType): void {
    $history = [];
    $mailbox = graphSubscriptionMailbox([], $history);

    expect(fn () => $mailbox->subscriptionHandshake(new Request($method, $url, ['Content-Type' => $contentType]), graphSubscriptionConfiguration()))->toThrow(InvalidChangeNotificationException::class)
        ->and($history)->toBe([]);
})->with([
    'wrong method' => ['GET', 'https://host.example/graph/changes?validationToken=token', 'text/plain'],
    'missing token' => ['POST', 'https://host.example/graph/changes', 'text/plain'],
    'duplicate token' => ['POST', 'https://host.example/graph/changes?validationToken=one&validationToken=two', 'text/plain'],
    'unknown endpoint' => ['POST', 'https://other.example/graph/changes?validationToken=token', 'text/plain'],
    'unexpected query' => ['POST', 'https://host.example/graph/changes?validationToken=token&other=true', 'text/plain'],
    'control character' => ['POST', 'https://host.example/graph/changes?validationToken=bad%0Avalue', 'text/plain'],
    'wrong content type' => ['POST', 'https://host.example/graph/changes?validationToken=token', 'application/json'],
]);

it('never replays or releases a host job after an unconfirmed subscription operation', function (string $operation, string $failure): void {
    $history = [];
    $job = Mockery::mock(Job::class);
    $job->shouldNotReceive('release');
    app()->instance('queue.job', $job);
    $response = $failure === 'transport'
        ? new ConnectException('private-transport-secret', new Request('POST', 'https://private.invalid'))
        : new Response(503, ['Retry-After' => '1', 'request-id' => 'attempt-1'], 'private-provider-body');
    $mailbox = graphSubscriptionMailbox([$response, new Response(204)], $history);
    $registration = graphSubscriptionRegistration();

    try {
        match ($operation) {
            'create' => $mailbox->createSubscription(graphSubscriptionConfiguration(), new DateTimeImmutable('+1 day')),
            'renew' => $mailbox->renewSubscription($registration, new DateTimeImmutable('+1 day')),
            'remove' => $mailbox->removeSubscription($registration),
        };
        test()->fail('An unconfirmed provider operation must not look successful.');
    } catch (ApiRequestException $exception) {
        expect($exception->status)->toBe($failure === 'transport' ? null : 503);
        $public = (string) $exception.print_r($exception->getTrace(), true);
        expect($public)->not->toContain('private-transport-secret');
        expect($public)->not->toContain('private-provider-body');
        expect($public)->not->toContain('private-client-state');
    }
    expect($history)->toHaveCount(1);
})->with(['create', 'renew', 'remove'])->with(['transport', 'server']);

it('keeps opaque folder identifiers case-sensitive during subscription acknowledgement validation', function (): void {
    $history = [];
    $expiry = new DateTimeImmutable('+1 day');
    $configuration = new GraphSubscriptionConfiguration('tenant-1', 'canonical-user-1', 'https://host.example/graph/changes', 'https://host.example/graph/lifecycle', 'private-client-state', 'OpaqueFolderAA');
    $acknowledgement = graphSubscriptionAcknowledgement($expiry);
    $acknowledgement['resource'] = 'users/canonical-user-1/mailFolders/opaquefolderaa/messages';
    $mailbox = graphSubscriptionMailbox([new Response(201, [], json_encode($acknowledgement, JSON_THROW_ON_ERROR))], $history);

    expect(fn () => $mailbox->createSubscription($configuration, $expiry))->toThrow(ApiRequestException::class)
        ->and($history)->toHaveCount(1);
});

it('requires a Graph provider context even when another provider context matches captured authorization', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([]));
    $stack->push(Middleware::history($history));
    $context = new GrantContext('connection', 'grant', '1', 'gmail', 'shared@example.com');
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['shared@example.com'], 'token' => 'selected-token'],
    ]);
    $client = new GraphClient([], new TokenManager([]), new RateLimiter([]), new Client(['handler' => $stack]), new GrantAuthorization($context, $tokens));

    expect(fn () => $client->createBasicSubscription($context, graphSubscriptionConfiguration(), new DateTimeImmutable('+1 day')))->toThrow(UnsupportedCapabilityException::class)
        ->and($tokens->tokenReads)->toBe(0)
        ->and($history)->toBe([]);
});

it('maps multiple known subscriptions to their retained scopes without using callback mailbox paths as folder authority', function (): void {
    $history = [];
    $mailbox = graphSubscriptionMailbox([], $history);
    $inbox = graphSubscriptionRegistration();
    $configuration = new GraphSubscriptionConfiguration('tenant-1', 'canonical-user-1', 'https://host.example/graph/changes', 'https://host.example/graph/lifecycle', 'private-second-state', 'drafts');
    $drafts = new GraphSubscriptionRegistration($inbox->context, $configuration, 'subscription-2', $inbox->expiresAt);
    $draftEvent = graphSubscriptionEvent($drafts);
    $draftEvent['clientState'] = 'private-second-state';

    $hints = $mailbox->subscriptionNotifications(graphSubscriptionRequest([graphSubscriptionEvent($inbox), $draftEvent]), [$inbox, $drafts]);

    expect(array_column($hints, 'resource'))->toBe([
        'users/shared%40example.com/mailFolders/inbox/messages',
        'users/shared%40example.com/mailFolders/drafts/messages',
    ])->and(array_column($hints, 'subscriptionId'))->toBe(['subscription-1', 'subscription-2'])
        ->and($history)->toBe([]);
});

it('rejects a valid basic callback delivered to an unbound endpoint', function (string $url): void {
    $history = [];
    $mailbox = graphSubscriptionMailbox([], $history);
    $registration = graphSubscriptionRegistration();
    $request = graphSubscriptionRequest([graphSubscriptionEvent($registration)])->withUri(new Uri($url));

    expect(fn () => $mailbox->subscriptionNotifications($request, [$registration]))->toThrow(InvalidChangeNotificationException::class)
        ->and($history)->toBe([]);
})->with(['https://other.example/graph/changes', 'https://host.example/graph/lifecycle', 'https://host.example/graph/changes?unexpected=true']);

/** @param list<array<string, mixed>> $events */
function graphSubscriptionRequest(array $events): Request
{
    return new Request('POST', 'https://host.example/graph/changes', ['Content-Type' => 'application/json'], json_encode(['value' => $events], JSON_THROW_ON_ERROR));
}

function graphSubscriptionConfiguration(): GraphSubscriptionConfiguration
{
    return new GraphSubscriptionConfiguration('tenant-1', 'canonical-user-1', 'https://host.example/graph/changes', 'https://host.example/graph/lifecycle', 'private-client-state', 'inbox');
}

function graphSubscriptionRegistration(?DateTimeImmutable $expiresAt = null): GraphSubscriptionRegistration
{
    return new GraphSubscriptionRegistration(new GrantContext('connection', 'grant', '1', 'ms-graph', 'shared@example.com'), graphSubscriptionConfiguration(), 'subscription-1', $expiresAt ?? new DateTimeImmutable('+1 day'));
}

/** @return array<string, mixed> */
function graphSubscriptionAcknowledgement(DateTimeImmutable $expiresAt): array
{
    return [
        'id' => 'subscription-1', 'resource' => 'users/shared%40example.com/mailFolders/inbox/messages',
        'changeType' => 'created,updated,deleted', 'clientState' => 'private-client-state',
        'notificationUrl' => 'https://host.example/graph/changes', 'lifecycleNotificationUrl' => 'https://host.example/graph/lifecycle',
        'includeResourceData' => false, 'expirationDateTime' => $expiresAt->format(DATE_ATOM),
    ];
}

/** @return array<string, mixed> */
function graphSubscriptionEvent(GraphSubscriptionRegistration $registration): array
{
    return [
        'id' => 'notification-1', 'subscriptionId' => $registration->subscriptionId,
        'subscriptionExpirationDateTime' => $registration->expiresAt->format(DATE_ATOM),
        'clientState' => 'private-client-state', 'tenantId' => 'tenant-1', 'changeType' => 'created',
        'resource' => 'users/canonical-user-1@tenant-1/messages/message-1=',
        'resourceData' => [
            '@odata.type' => '#Microsoft.Graph.Message', '@odata.etag' => 'W/"version-1"',
            '@odata.id' => 'Users/canonical-user-1@tenant-1/Messages/message-1=', 'id' => 'message-1=',
        ],
    ];
}

/** @param array<mixed> $responses */
function graphSubscriptionMailbox(array $responses, array &$history, ?TestGrantTokenProvider $tokens = null): SupportsGraphSubscriptions
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'base_uri' => 'https://graph.microsoft.com/v1.0/']);
    $context = new GrantContext('connection', 'grant', '1', 'ms-graph', 'shared@example.com');
    $tokens ??= new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['shared@example.com'], 'token' => 'selected-token'],
    ]);
    $mailbox = Mailbox::forGrant($context, $tokens, $http);
    expect($mailbox)->toBeInstanceOf(SupportsGraphSubscriptions::class);

    return $mailbox;
}

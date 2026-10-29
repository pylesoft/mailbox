<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Pyle\Mailbox\Drivers\MsGraph\Contracts\SupportsGraphSubscriptions;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\RateLimiter;
use Pyle\Mailbox\Drivers\MsGraph\TokenManager;
use Pyle\Mailbox\DTOs\GraphSubscriptionConfiguration;
use Pyle\Mailbox\DTOs\GraphSubscriptionRegistration;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
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
    $http = new Client(['handler' => $stack, 'base_uri' => 'https://graph.microsoft.com/v1.0/']);
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

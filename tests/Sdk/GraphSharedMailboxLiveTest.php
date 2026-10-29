<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Pyle\Mailbox\Drivers\MsGraph\Contracts\SupportsGraphSharedMailboxLive;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\GraphSubscriptionConfiguration;
use Pyle\Mailbox\DTOs\GraphSubscriptionRegistration;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Enums\SubmissionOutcome;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;
use Pyle\Mailbox\Tests\Support\GraphSharedMailboxHostTokens;

it('does not treat a selected token as proof of required shared mailbox live capability', function (): void {
    $history = [];
    $handler = HandlerStack::create(new MockHandler);
    $handler->push(Middleware::history($history));
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['shared@example.com'], 'token' => 'selected-token'],
    ]);
    $mailbox = Mailbox::forGrant(
        new GrantContext('connection', 'grant', '1', 'ms-graph', 'shared@example.com'),
        $tokens,
        new Client(['handler' => $handler]),
    );

    expect(fn () => $mailbox->requireSharedMailboxLive(new GraphSubscriptionRegistration(
        new GrantContext('connection', 'grant', '1', 'ms-graph', 'shared@example.com'),
        new GraphSubscriptionConfiguration(
            'tenant-1', 'canonical-user-1', 'https://host.example/graph/changes',
            'https://host.example/graph/lifecycle', 'private-client-state', 'inbox',
        ),
        'subscription-1', new DateTimeImmutable('+1 day'),
    )))->toThrow(UnsupportedCapabilityException::class, 'host')
        ->and($tokens->tokenReads)->toBe(0)
        ->and($history)->toBe([])
        ->and(config('database.default'))->toBe('sdk-forbidden');
});

it('rejects a foreign or inactive subscription before declaring required live setup', function (string $change): void {
    $context = new GrantContext('connection', 'grant', '1', 'ms-graph', 'shared@example.com');
    $configuration = new GraphSubscriptionConfiguration(
        'tenant-1', 'canonical-user-1', 'https://host.example/graph/changes',
        'https://host.example/graph/lifecycle', 'private-client-state', 'inbox',
    );
    $tokens = new GraphSharedMailboxHostTokens($context, $configuration);
    $mailbox = Mailbox::forGrant($context, $tokens, new Client(['handler' => new MockHandler]));
    $registration = new GraphSubscriptionRegistration(
        new GrantContext(
            $change === 'connection' ? 'foreign' : 'connection',
            $change === 'grant' ? 'foreign' : 'grant',
            $change === 'generation' ? 'foreign' : '1',
            'ms-graph', $change === 'mailbox' ? 'foreign@example.com' : 'shared@example.com',
        ),
        $configuration, 'subscription-1',
        new DateTimeImmutable($change === 'expired' ? '-1 second' : '+1 day'),
        $change === 'removed' ? 'removed' : 'active',
    );

    expect(fn () => $mailbox->requireSharedMailboxLive($registration))->toThrow(UnsupportedCapabilityException::class)
        ->and($tokens->tokens->tokenReads)->toBe(0)
        ->and($tokens->verifiedContexts)->toBe([]);
})->with(['connection', 'grant', 'generation', 'mailbox', 'expired', 'removed']);

it('rejects delegated live authority without elevation and keeps the same authorized grant available for delta only', function (): void {
    $context = new GrantContext('connection', 'grant', '1', 'ms-graph', 'shared@example.com');
    $configuration = new GraphSubscriptionConfiguration(
        'tenant-1', 'canonical-user-1', 'https://host.example/graph/changes',
        'https://host.example/graph/lifecycle', 'private-client-state', 'inbox',
    );
    $tokens = new GraphSharedMailboxHostTokens($context, $configuration);
    $tokens->missingSetup = 'application authority instead of delegated authority';
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode([
            'value' => [['id' => 'changed-1']],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/users/shared%40example.com/mailFolders/inbox/messages/delta?$deltatoken=selected',
        ], JSON_THROW_ON_ERROR)),
    ]));
    $handler->push(Middleware::history($history));
    $mailbox = Mailbox::forGrant($context, $tokens, new Client(['handler' => $handler]));
    $registration = new GraphSubscriptionRegistration($context, $configuration, 'subscription-1', new DateTimeImmutable('+1 day'));

    expect(fn () => $mailbox->requireSharedMailboxLive($registration))->toThrow(UnsupportedCapabilityException::class, 'delegated authority')
        ->and($tokens->tokens->tokenReads)->toBe(0)
        ->and($history)->toBe([]);

    $page = $mailbox->initialSync(new SyncScope('inbox'), new SyncBudget);

    expect($page->upserts)->toBe(['changed-1'])
        ->and($page->checkpoint)->not->toBeNull()
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer selected-application-token');
});

it('checks host setup and explicitly reads submits and subscribes through one selected application grant', function (): void {
    $context = new GrantContext('connection', 'grant', '1', 'ms-graph', 'shared@example.com');
    $configuration = new GraphSubscriptionConfiguration(
        'tenant-1', 'canonical-user-1', 'https://host.example/graph/changes',
        'https://host.example/graph/lifecycle', 'private-client-state', 'inbox',
    );
    $expiresAt = new DateTimeImmutable('+1 day');
    $draft = [
        'id' => 'immutable-draft', 'isDraft' => true, 'attachments' => [], 'hasAttachments' => false,
        'from' => ['emailAddress' => ['name' => 'Shared', 'address' => 'shared@example.com']],
        'toRecipients' => [['emailAddress' => ['name' => 'Recipient', 'address' => 'to@example.com']]],
        'ccRecipients' => [], 'bccRecipients' => [],
        'subject' => 'Chosen subject', 'body' => ['contentType' => 'Text', 'content' => 'Chosen body'],
        'internetMessageHeaders' => [['name' => 'X-Mailbox-Correlation-Id', 'value' => 'operation-1']],
    ];
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
        new Response(201, [], json_encode([
            'id' => 'subscription-1', 'resource' => 'users/shared%40example.com/mailFolders/inbox/messages',
            'changeType' => 'created,updated,deleted', 'notificationUrl' => $configuration->notificationUrl,
            'lifecycleNotificationUrl' => $configuration->lifecycleNotificationUrl,
            'clientState' => 'private-client-state', 'includeResourceData' => false,
            'expirationDateTime' => $expiresAt->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"id":"received-1","subject":"Incoming","body":{"contentType":"text","content":"Read body"}}'),
        new Response(201, [], '{"id":"immutable-draft"}'),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(202),
    ]));
    $handler->push(Middleware::history($history));
    $tokens = new GraphSharedMailboxHostTokens($context, $configuration);
    $mailbox = Mailbox::forGrant($context, $tokens, new Client(['handler' => $handler]));
    expect($mailbox)->toBeInstanceOf(SupportsGraphSharedMailboxLive::class);

    $registration = $mailbox->createSubscription($configuration, $expiresAt);
    $mailbox->requireSharedMailboxLive(unserialize(serialize($registration)));
    expect($history)->toHaveCount(1)
        ->and($tokens->verifiedContexts)->toBe([$context]);

    $received = $mailbox->message('received-1')->get();
    $message = new PreparedMessage(
        new EmailAddressDto('Shared', 'shared@example.com'),
        [new EmailAddressDto('Recipient', 'to@example.com')],
        'Chosen subject', 'operation-1', textBody: 'Chosen body',
    );
    $preparation = $mailbox->prepareSubmission($message);
    $result = $mailbox->submitPrepared($preparation, $message);

    expect($received->id)->toBe('received-1')
        ->and($received->body->content)->toBe('Read body')
        ->and($preparation->context)->toBe($context)
        ->and($result->context)->toBe($context)
        ->and($result->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($registration->context)->toBe($context)
        ->and($history)->toHaveCount(5)
        ->and($history[0]['request']->getUri()->getPath())->toBe('/v1.0/subscriptions')
        ->and($history[1]['request']->getUri()->getPath())->toBe('/v1.0/users/shared%40example.com/messages/received-1')
        ->and($history[2]['request']->getUri()->getPath())->toBe('/v1.0/users/shared%40example.com/messages')
        ->and($history[4]['request']->getUri()->getPath())->toBe('/v1.0/users/shared%40example.com/messages/immutable-draft/send');
    foreach ($history as $entry) {
        expect($entry['request']->getHeaderLine('Authorization'))->toBe('Bearer selected-application-token');
    }
});

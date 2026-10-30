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
use GuzzleHttp\Psr7\Utils;
use Pyle\Mailbox\Contracts\GrantTokenProvider;
use Pyle\Mailbox\Drivers\MsGraph\Contracts\SupportsGraphSubscriptionInspection;
use Pyle\Mailbox\Drivers\MsGraph\Contracts\SupportsGraphSubscriptions;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\GraphSubscriptionConfiguration;
use Pyle\Mailbox\DTOs\GraphSubscriptionRegistration;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\InvalidChangeNotificationException;
use Pyle\Mailbox\Exceptions\InvalidSyncStateException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('inspects an existing subscription through the independently selected grant before retaining its registration', function (): void {
    $history = [];
    $context = new GrantContext('second-connection', 'second-grant', '2', 'ms-graph', 'shared@example.com');
    $tokens = new TestGrantTokenProvider([
        'second-grant' => ['connection' => 'second-connection', 'generation' => '2', 'mailboxes' => ['shared@example.com'], 'token' => 'second-selected-token'],
    ]);
    $expiresAt = new DateTimeImmutable('+1 day');
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode(inspectionAcknowledgement($expiresAt), JSON_THROW_ON_ERROR)),
    ]));
    $stack->push(Middleware::history($history));
    $mailbox = Mailbox::forGrant($context, $tokens, new Client(['handler' => $stack]));

    $registration = $mailbox->inspectSubscription('subscription-1', inspectionConfiguration(), '11111111-1111-4111-8111-111111111111');

    expect($registration->context)->toBe($context)
        ->and($registration->subscriptionId)->toBe('subscription-1')
        ->and($registration->expiresAt->getTimestamp())->toBe($expiresAt->getTimestamp())
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getMethod())->toBe('GET')
        ->and((string) $history[0]['request']->getUri())->toBe('https://graph.microsoft.com/v1.0/subscriptions/subscription-1')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer second-selected-token')
        ->and(config('database.default'))->toBe('sdk-forbidden');
});

it('rejects a case-folded opaque mailbox in subscription operations and callbacks', function (string $operation): void {
    $history = [];
    $context = new GrantContext('connection', 'grant', '1', 'ms-graph', 'OpaqueMailboxAA');
    $expiry = new DateTimeImmutable('+1 day');
    $acknowledgement = inspectionAcknowledgement($expiry);
    $acknowledgement['resource'] = 'users/opaquemailboxaa/mailFolders/inbox/messages';
    $mailbox = inspectionMailbox($operation === 'callback' ? [] : [new Response($operation === 'create' ? 201 : 200, [], json_encode($acknowledgement, JSON_THROW_ON_ERROR))], $history, $context);

    if ($operation === 'create') {
        expect(fn () => $mailbox->createSubscription(inspectionConfiguration(), $expiry))->toThrow(ApiRequestException::class)
            ->and($history)->toHaveCount(1);
    } elseif ($operation === 'inspect') {
        expect(fn () => $mailbox->inspectSubscription('subscription-1', inspectionConfiguration(), '11111111-1111-4111-8111-111111111111'))->toThrow(ApiRequestException::class)
            ->and($history)->toHaveCount(1);
    } else {
        $registration = new GraphSubscriptionRegistration($context, inspectionConfiguration(), 'subscription-1', $expiry);
        expect(fn () => $mailbox->subscriptionNotifications(inspectionNotification($expiry, 'opaquemailboxaa'), [$registration]))->toThrow(InvalidChangeNotificationException::class)
            ->and($history)->toBe([]);
    }
})->with(['create', 'inspect', 'callback']);

it('preserves typed email and GUID aliases and exact opaque mailbox identities', function (string $selected, string $returned): void {
    $history = [];
    $expiry = new DateTimeImmutable('+1 day');
    $context = new GrantContext('connection', 'grant', '1', 'ms-graph', $selected);
    $acknowledgement = inspectionAcknowledgement($expiry);
    $acknowledgement['resource'] = 'users/'.rawurlencode($returned).'/mailFolders/inbox/messages';
    $mailbox = inspectionMailbox([new Response(201, [], json_encode($acknowledgement, JSON_THROW_ON_ERROR))], $history, $context);

    $registration = $mailbox->createSubscription(inspectionConfiguration(), $expiry);
    $hints = $mailbox->subscriptionNotifications(inspectionNotification($expiry, $returned), [$registration]);

    expect($registration->context)->toBe($context)
        ->and($hints)->toHaveCount(1)
        ->and($hints[0]->reason)->toBe('mailbox_changed')
        ->and($history)->toHaveCount(1);
})->with([
    'email alias' => ['Shared@Example.COM', 'shared@example.com'],
    'GUID identity' => ['AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'],
    'exact opaque identity' => ['OpaqueMailboxAA', 'OpaqueMailboxAA'],
]);

it('returns unavailable only for an actual subscription GET 404 and never for a token-provider 404', function (): void {
    $history = [];
    $mailbox = inspectionMailbox([new Response(404, [], '{"error":{"code":"ResourceNotFound"}}')], $history);

    expect($mailbox->inspectSubscription('subscription-1', inspectionConfiguration(), '11111111-1111-4111-8111-111111111111'))->toBeNull()
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getMethod())->toBe('GET');

    $history = [];
    $tokens = new class implements GrantTokenProvider
    {
        public function assertActive(GrantContext $context): void {}

        public function accessToken(GrantContext $context): string
        {
            throw new ApiRequestException('private-token-provider-404', status: 404);
        }
    };
    $mailbox = inspectionMailbox([], $history, tokens: $tokens);
    expect(fn () => $mailbox->inspectSubscription('subscription-1', inspectionConfiguration(), '11111111-1111-4111-8111-111111111111'))->toThrow(ApiRequestException::class)
        ->and($history)->toBe([]);
});

it('rejects subscription inspection when the captured grant changes before or during GET', function (bool $before): void {
    $history = [];
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['shared@example.com'], 'token' => 'selected-token'],
    ]);
    $mailbox = inspectionMailbox([function () use ($tokens): Response {
        $tokens->grants['grant']['generation'] = '2';

        return new Response(200, [], json_encode(inspectionAcknowledgement(new DateTimeImmutable('+1 day')), JSON_THROW_ON_ERROR));
    }], $history, tokens: $tokens);
    if ($before) {
        $tokens->grants['grant']['generation'] = '2';
    }

    expect(fn () => $mailbox->inspectSubscription('subscription-1', inspectionConfiguration(), '11111111-1111-4111-8111-111111111111'))->toThrow(GrantAccessDeniedException::class)
        ->and($history)->toHaveCount($before ? 0 : 1);
})->with([true, false]);

it('rejects contradictory subscription inspection evidence', function (array $changes): void {
    $history = [];
    $acknowledgement = array_replace(inspectionAcknowledgement(new DateTimeImmutable('+1 day')), $changes);
    $mailbox = inspectionMailbox([new Response(200, [], json_encode($acknowledgement, JSON_THROW_ON_ERROR))], $history);

    expect(fn () => $mailbox->inspectSubscription('subscription-1', inspectionConfiguration(), '11111111-1111-4111-8111-111111111111'))->toThrow(ApiRequestException::class)
        ->and($history)->toHaveCount(1);
})->with([
    'other application' => [['applicationId' => '22222222-2222-4222-8222-222222222222']],
    'missing application' => [['applicationId' => null]],
    'other subscription' => [['id' => 'subscription-other']],
    'other mailbox' => [['resource' => 'users/other-mailbox/mailFolders/inbox/messages']],
    'other folder' => [['resource' => 'users/canonical-user-1/mailFolders/other/messages']],
    'mailbox scope instead of folder' => [['resource' => 'users/canonical-user-1/messages']],
    'message instead of subscription scope' => [['resource' => 'users/canonical-user-1/mailFolders/inbox/messages/message-1']],
    'other secret' => [['clientState' => 'foreign-private-state']],
    'other notification endpoint' => [['notificationUrl' => 'https://other.example/changes']],
    'other lifecycle endpoint' => [['lifecycleNotificationUrl' => 'https://other.example/lifecycle']],
    'other changes' => [['changeType' => 'updated']],
    'rich mode' => [['includeResourceData' => true]],
    'certificate' => [['encryptionCertificateId' => 'private-certificate']],
    'expired' => [['expirationDateTime' => '2020-01-01T00:00:00Z']],
    'unbounded expiry' => [['expirationDateTime' => '2099-01-01T00:00:00Z']],
    'malformed expiry' => [['expirationDateTime' => 'private-invalid-date']],
]);

it('keeps private subscription configuration and provider content out of inspection exception representations', function (): void {
    $previous = ini_set('zend.exception_ignore_args', '0');
    try {
        expect(ini_get('zend.exception_ignore_args'))->toBe('0');
        $history = [];
        $configuration = new GraphSubscriptionConfiguration('tenant-1', 'canonical-user-1', 'https://host.example/changes?handle=private-callback-handle', 'https://host.example/lifecycle?handle=private-lifecycle-handle', 'private-client-state', 'inbox');
        $acknowledgement = array_replace(inspectionAcknowledgement(new DateTimeImmutable('+1 day')), [
            'id' => 'wrong-subscription', 'notificationUrl' => $configuration->notificationUrl, 'lifecycleNotificationUrl' => $configuration->lifecycleNotificationUrl,
            'privateExtra' => 'private-provider-body',
        ]);
        $mailbox = inspectionMailbox([new Response(200, [], json_encode($acknowledgement, JSON_THROW_ON_ERROR))], $history);
        try {
            $mailbox->inspectSubscription('subscription-1', $configuration, '11111111-1111-4111-8111-111111111111');
            test()->fail('Contradictory inspection evidence cannot be retained.');
        } catch (ApiRequestException $exception) {
            expect($exception->getTrace()[0])->toHaveKey('args');
            $public = (string) $exception.print_r($exception->getTrace(), true).json_encode($exception->getTrace(), JSON_THROW_ON_ERROR);
            foreach (['private-callback-handle', 'private-lifecycle-handle', 'private-client-state', 'private-provider-body'] as $secret) {
                expect($public)->not->toContain($secret);
            }
        }
        expect($history)->toHaveCount(1);
    } finally {
        ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
    }
});

it('looks up only the exact private subscription configuration through the selected grant', function (): void {
    $history = [];
    $expiry = new DateTimeImmutable('+1 day');
    $foreign = array_replace(inspectionAcknowledgement($expiry), [
        'id' => 'foreign-subscription', 'resource' => 'users/foreign-mailbox/messages', 'clientState' => 'foreign-private-client-state',
        'notificationUrl' => 'https://foreign.example/changes?handle=foreign-private-handle',
    ]);
    $mailbox = inspectionMailbox([new Response(200, [], json_encode(['value' => [$foreign, inspectionAcknowledgement($expiry)]], JSON_THROW_ON_ERROR))], $history);

    $lookup = $mailbox->lookupSubscriptions(inspectionConfiguration(), '11111111-1111-4111-8111-111111111111', new SyncBudget);

    expect($lookup->registrations)->toHaveCount(1)
        ->and($lookup->registrations[0]->subscriptionId)->toBe('subscription-1')
        ->and($lookup->registrations[0]->context->grantId)->toBe('grant')
        ->and($lookup->complete)->toBeTrue()
        ->and($lookup->conflict)->toBeFalse()
        ->and($lookup->continuation)->toBeNull()
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getMethod())->toBe('GET')
        ->and((string) $history[0]['request']->getUri())->toBe('https://graph.microsoft.com/v1.0/subscriptions');
    $public = json_encode($lookup, JSON_THROW_ON_ERROR).print_r($lookup, true);
    foreach (['foreign-subscription', 'foreign-mailbox', 'foreign-private-client-state', 'foreign-private-handle'] as $secret) {
        expect($public)->not->toContain($secret);
    }
});

it('returns one bounded lookup page and preserves the opaque continuation query on resume', function (): void {
    $history = [];
    $next = 'https://graph.microsoft.com/v1.0/subscriptions?$skiptoken=private-opaque%2B%2F+%3D%25';
    $mailbox = inspectionMailbox([
        new Response(200, [], json_encode(['value' => [], '@odata.nextLink' => $next], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['value' => [inspectionAcknowledgement(new DateTimeImmutable('+1 day'))]], JSON_THROW_ON_ERROR)),
    ], $history);

    $first = $mailbox->lookupSubscriptions(inspectionConfiguration(), '11111111-1111-4111-8111-111111111111', new SyncBudget(maxPages: 8, maxRequests: 8));
    expect($first->complete)->toBeFalse()->and($first->reason)->toBe('more_pages')->and($history)->toHaveCount(1);
    foreach (['private-opaque', 'https://', 'private-client-state'] as $secret) {
        expect(json_encode($first, JSON_THROW_ON_ERROR).print_r($first, true))->not->toContain($secret);
    }

    $second = $mailbox->lookupSubscriptions(inspectionConfiguration(), '11111111-1111-4111-8111-111111111111', new SyncBudget, unserialize(serialize($first->continuation)));
    expect($second->complete)->toBeTrue()->and($second->registrations)->toHaveCount(1)
        ->and($history)->toHaveCount(2)->and((string) $history[1]['request']->getUri())->toBe($next);
});

it('keeps an oversized lookup page unfinished and retries that same page only with a sufficient item budget', function (): void {
    $history = [];
    $foreign = array_replace(inspectionAcknowledgement(new DateTimeImmutable('+1 day')), ['id' => 'private-foreign-id', 'resource' => 'users/private-foreign-mailbox/messages', 'clientState' => 'private-foreign-state']);
    $body = json_encode(['value' => [$foreign, inspectionAcknowledgement(new DateTimeImmutable('+1 day'))]], JSON_THROW_ON_ERROR);
    $mailbox = inspectionMailbox([new Response(200, [], $body), new Response(200, [], $body)], $history);

    $first = $mailbox->lookupSubscriptions(inspectionConfiguration(), '11111111-1111-4111-8111-111111111111', new SyncBudget(maxItems: 1));
    expect($first->complete)->toBeFalse()->and($first->reason)->toBe('provider_item_limit')->and($first->registrations)->toBe([]);
    foreach (['private-foreign-id', 'private-foreign-mailbox', 'private-foreign-state'] as $secret) {
        expect(serialize($first->continuation))->not->toContain($secret);
    }
    $second = $mailbox->lookupSubscriptions(inspectionConfiguration(), '11111111-1111-4111-8111-111111111111', new SyncBudget(maxItems: 2), $first->continuation);
    expect($second->complete)->toBeTrue()->and($second->registrations)->toHaveCount(1)
        ->and((string) $history[1]['request']->getUri())->toBe('https://graph.microsoft.com/v1.0/subscriptions');
});

it('enforces actual lookup response bytes before decoding and closes the bounded transport stream', function (): void {
    $history = [];
    $body = Utils::streamFor(json_encode(['value' => [], 'privatePadding' => str_repeat('x', 5000)], JSON_THROW_ON_ERROR));
    $read = 0;
    $closed = false;
    $stream = FnStream::decorate($body, [
        'read' => function (int $length) use ($body, &$read): string {
            $chunk = $body->read($length);
            $read += strlen($chunk);

            return $chunk;
        },
        'close' => function () use ($body, &$closed): void {
            $closed = true;
            $body->close();
        },
    ]);
    $mailbox = inspectionMailbox([new Response(200, [], $stream)], $history);

    expect(fn () => $mailbox->lookupSubscriptions(inspectionConfiguration(), '11111111-1111-4111-8111-111111111111', new SyncBudget(maxResponseBytes: 64)))->toThrow(ApiRequestException::class)
        ->and($read)->toBeLessThanOrEqual(65)->and($closed)->toBeTrue()->and($history)->toHaveCount(1);
});

it('rejects lookup continuations after their trusted binding changes before another request', function (string $change): void {
    $history = [];
    $mailbox = inspectionMailbox([new Response(200, [], '{"value":[],"@odata.nextLink":"https://graph.microsoft.com/v1.0/subscriptions?$skiptoken=private-next"}')], $history);
    $first = $mailbox->lookupSubscriptions(inspectionConfiguration(), '11111111-1111-4111-8111-111111111111', new SyncBudget);
    $fields = ['tenantId' => 'tenant-1', 'providerMailboxId' => 'canonical-user-1', 'notificationUrl' => 'https://host.example/graph/changes', 'lifecycleNotificationUrl' => 'https://host.example/graph/lifecycle', 'clientState' => 'private-client-state', 'folderId' => 'inbox'];
    $application = '11111111-1111-4111-8111-111111111111';
    $context = new GrantContext('connection', 'grant', '1', 'ms-graph', 'shared@example.com');
    if (array_key_exists($change, $fields)) {
        $fields[$change] = match ($change) {
            'notificationUrl', 'lifecycleNotificationUrl' => 'https://other.example/endpoint',
            default => 'different-trusted-value',
        };
    } elseif ($change === 'application') {
        $application = '22222222-2222-4222-8222-222222222222';
    } else {
        $context = new GrantContext($change === 'connection' ? 'other' : 'connection', $change === 'grant' ? 'other' : 'grant', $change === 'generation' ? '2' : '1', 'ms-graph', $change === 'mailbox' ? 'other@example.com' : 'shared@example.com');
    }
    $history = [];
    $mailbox = inspectionMailbox([], $history, $context);

    expect(fn () => $mailbox->lookupSubscriptions(new GraphSubscriptionConfiguration(...$fields), $application, new SyncBudget, $first->continuation))->toThrow(InvalidSyncStateException::class)
        ->and($history)->toBe([]);
})->with(['tenantId', 'providerMailboxId', 'notificationUrl', 'lifecycleNotificationUrl', 'clientState', 'folderId', 'application', 'connection', 'grant', 'generation', 'mailbox']);

it('reports conflicting private configurations and leaves multiple exact matches for the host to resolve', function (): void {
    $history = [];
    $expiry = new DateTimeImmutable('+1 day');
    $conflicting = array_replace(inspectionAcknowledgement($expiry), ['id' => 'private-conflicting-id', 'clientState' => 'private-conflicting-state', 'notificationUrl' => 'https://private-other.example/changes']);
    $otherApplication = array_replace(inspectionAcknowledgement($expiry), ['id' => 'private-other-app-id', 'applicationId' => '22222222-2222-4222-8222-222222222222']);
    $second = array_replace(inspectionAcknowledgement($expiry), ['id' => 'subscription-2']);
    $mailbox = inspectionMailbox([new Response(200, [], json_encode(['value' => [$otherApplication, $conflicting, inspectionAcknowledgement($expiry), $second]], JSON_THROW_ON_ERROR))], $history);

    $lookup = $mailbox->lookupSubscriptions(inspectionConfiguration(), '11111111-1111-4111-8111-111111111111', new SyncBudget);
    expect(array_column($lookup->registrations, 'subscriptionId'))->toBe(['subscription-1', 'subscription-2'])
        ->and($lookup->conflict)->toBeTrue()->and($lookup->complete)->toBeTrue();
    foreach (['private-conflicting-id', 'private-conflicting-state', 'private-other.example', 'private-other-app-id', 'host.example', 'private-client-state'] as $secret) {
        expect(json_encode($lookup, JSON_THROW_ON_ERROR).print_r($lookup, true))->not->toContain($secret);
    }
});

it('never treats malformed or interrupted lookup responses as completed absence', function (string $body, int $status, array $headers): void {
    $history = [];
    $mailbox = inspectionMailbox([new Response($status, $headers, $body)], $history);

    expect(fn () => $mailbox->lookupSubscriptions(inspectionConfiguration(), '11111111-1111-4111-8111-111111111111', new SyncBudget))->toThrow(ApiRequestException::class)
        ->and($history)->toHaveCount(1);
})->with([
    'invalid JSON' => ['{', 200, []],
    'array envelope' => ['[]', 200, []],
    'missing collection' => ['{}', 200, []],
    'object collection' => ['{"value":{}}', 200, []],
    'null collection' => ['{"value":null}', 200, []],
    'scalar row' => ['{"value":[3]}', 200, []],
    'incomplete row' => ['{"value":[{}]}', 200, []],
    'encoded body' => ['{"value":[]}', 200, ['Content-Encoding' => 'gzip']],
    'truncated length' => ['{"value":[]}', 200, ['Content-Length' => '1000']],
    'redirect' => ['{"value":[]}', 302, ['Location' => 'https://private-redirect.example/subscriptions']],
    'denied' => ['{"value":[]}', 403, []],
    'not found' => ['{"value":[]}', 404, []],
    'throttled' => ['{"value":[]}', 429, ['Retry-After' => '1']],
    'server failure' => ['{"value":[]}', 503, []],
]);

it('rejects untrusted lookup pagination without exposing the provider continuation', function (string $case): void {
    $next = match ($case) {
        'foreign origin' => 'https://foreign.example/v1.0/subscriptions?$skiptoken=private-foreign-cursor',
        'foreign version' => 'https://graph.microsoft.com/beta/subscriptions?$skiptoken=private-foreign-cursor',
        'foreign resource' => 'https://graph.microsoft.com/v1.0/users/shared/messages?$skiptoken=private-foreign-cursor',
        'subscription child' => 'https://graph.microsoft.com/v1.0/subscriptions/another?$skiptoken=private-foreign-cursor',
        'credentials' => 'https://private-foreign-cursor@graph.microsoft.com/v1.0/subscriptions?$skiptoken=x',
        'fragment' => 'https://graph.microsoft.com/v1.0/subscriptions?$skiptoken=x#private-foreign-cursor',
        'missing query' => 'https://graph.microsoft.com/v1.0/subscriptions',
        'null link' => null,
        'array link' => ['private-foreign-cursor'],
    };
    $history = [];
    $mailbox = inspectionMailbox([new Response(200, [], json_encode(['value' => [], '@odata.nextLink' => $next], JSON_THROW_ON_ERROR))], $history);
    $previous = ini_set('zend.exception_ignore_args', '0');
    try {
        try {
            $mailbox->lookupSubscriptions(inspectionConfiguration(), '11111111-1111-4111-8111-111111111111', new SyncBudget);
            test()->fail('Untrusted provider progress cannot complete a lookup.');
        } catch (ApiRequestException $exception) {
            expect((string) $exception.json_encode($exception->getTrace(), JSON_THROW_ON_ERROR))->not->toContain('private-foreign-cursor');
        }
        expect($history)->toHaveCount(1);
    } finally {
        ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
    }
})->with(['foreign origin', 'foreign version', 'foreign resource', 'subscription child', 'credentials', 'fragment', 'missing query', 'null link', 'array link']);

it('keeps repeated provider lookup progress unfinished without automatically replaying a page', function (): void {
    $history = [];
    $body = '{"value":[],"@odata.nextLink":"https://graph.microsoft.com/v1.0/subscriptions?$skiptoken=private-next"}';
    $mailbox = inspectionMailbox([new Response(200, [], $body), new Response(200, [], $body)], $history);
    $first = $mailbox->lookupSubscriptions(inspectionConfiguration(), '11111111-1111-4111-8111-111111111111', new SyncBudget);
    $second = $mailbox->lookupSubscriptions(inspectionConfiguration(), '11111111-1111-4111-8111-111111111111', new SyncBudget, $first->continuation);

    expect($second->complete)->toBeFalse()->and($second->reason)->toBe('provider_progress_repeated')->and($second->continuation)->not->toBeNull()
        ->and($history)->toHaveCount(2);
});

it('rejects lookup evidence when authority is invalidated during provider IO', function (): void {
    $history = [];
    $tokens = new TestGrantTokenProvider(['grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['shared@example.com'], 'token' => 'selected-token']]);
    $mailbox = inspectionMailbox([function () use ($tokens): Response {
        $tokens->grants['grant']['generation'] = '2';

        return new Response(200, [], '{"value":[]}');
    }], $history, tokens: $tokens);

    expect(fn () => $mailbox->lookupSubscriptions(inspectionConfiguration(), '11111111-1111-4111-8111-111111111111', new SyncBudget))->toThrow(GrantAccessDeniedException::class)
        ->and($history)->toHaveCount(1);
});

it('recovers an unacknowledged create through exact lookup without another POST', function (): void {
    $history = [];
    $mailbox = inspectionMailbox([
        new ConnectException('private-lost-create-acknowledgement', new Request('POST', 'https://graph.microsoft.com/v1.0/subscriptions')),
        new Response(200, [], json_encode(['value' => [inspectionAcknowledgement(new DateTimeImmutable('+1 day'))]], JSON_THROW_ON_ERROR)),
    ], $history);

    expect(fn () => $mailbox->createSubscription(inspectionConfiguration(), new DateTimeImmutable('+1 day')))->toThrow(ApiRequestException::class);
    $lookup = $mailbox->lookupSubscriptions(inspectionConfiguration(), '11111111-1111-4111-8111-111111111111', new SyncBudget);

    expect($lookup->complete)->toBeTrue()->and($lookup->conflict)->toBeFalse()
        ->and($lookup->registrations[0]->subscriptionId)->toBe('subscription-1')
        ->and($lookup->registrations[0]->context->grantId)->toBe('grant')
        ->and(array_map(static fn (array $entry): string => $entry['request']->getMethod(), $history))->toBe(['POST', 'GET']);
});

it('distinguishes a completed empty visible collection from a conflicting private watch', function (bool $conflict): void {
    $history = [];
    $rows = $conflict ? [array_replace(inspectionAcknowledgement(new DateTimeImmutable('+1 day')), ['clientState' => 'private-other-state'])] : [];
    $mailbox = inspectionMailbox([new Response(200, [], json_encode(['value' => $rows], JSON_THROW_ON_ERROR))], $history);

    $lookup = $mailbox->lookupSubscriptions(inspectionConfiguration(), '11111111-1111-4111-8111-111111111111', new SyncBudget);

    expect($lookup->complete)->toBeTrue()->and($lookup->registrations)->toBe([])
        ->and($lookup->conflict)->toBe($conflict)->and($history)->toHaveCount(1);
})->with([false, true]);

it('rejects malformed subscription and expected application identities before token acquisition', function (string $id, string $application): void {
    $history = [];
    $tokens = new TestGrantTokenProvider(['grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['shared@example.com'], 'token' => 'selected-token']]);
    $mailbox = inspectionMailbox([], $history, tokens: $tokens);

    expect(fn () => $mailbox->inspectSubscription($id, inspectionConfiguration(), $application))->toThrow(InvalidArgumentException::class)
        ->and($tokens->tokenReads)->toBe(0)->and($history)->toBe([]);
})->with([
    ['../subscription', '11111111-1111-4111-8111-111111111111'],
    ['subscription-1?query=x', '11111111-1111-4111-8111-111111111111'],
    ['subscription-1', 'missing-application-identity'],
]);

/** @param array<mixed> $responses */
function inspectionMailbox(array $responses, array &$history, ?GrantContext $context = null, ?GrantTokenProvider $tokens = null): SupportsGraphSubscriptionInspection&SupportsGraphSubscriptions
{
    $context ??= new GrantContext('connection', 'grant', '1', 'ms-graph', 'shared@example.com');
    $tokens ??= new TestGrantTokenProvider([
        $context->grantId => ['connection' => $context->connectionId, 'generation' => $context->lifecycleGeneration, 'mailboxes' => [$context->mailbox], 'token' => 'selected-token'],
    ]);
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return Mailbox::forGrant($context, $tokens, new Client(['handler' => $stack]));
}

function inspectionNotification(DateTimeImmutable $expiry, string $mailbox): Request
{
    return new Request('POST', 'https://host.example/graph/changes', ['Content-Type' => 'application/json'], json_encode(['value' => [[
        'subscriptionId' => 'subscription-1',
        'subscriptionExpirationDateTime' => $expiry->format(DATE_ATOM),
        'tenantId' => 'tenant-1', 'clientState' => 'private-client-state', 'changeType' => 'updated',
        'resource' => 'users/'.rawurlencode($mailbox).'/mailFolders/inbox/messages/message-1',
    ]]], JSON_THROW_ON_ERROR));
}

function inspectionConfiguration(): GraphSubscriptionConfiguration
{
    return new GraphSubscriptionConfiguration('tenant-1', 'canonical-user-1', 'https://host.example/graph/changes', 'https://host.example/graph/lifecycle', 'private-client-state', 'inbox');
}

/** @return array<string, mixed> */
function inspectionAcknowledgement(DateTimeImmutable $expiresAt): array
{
    return [
        'id' => 'subscription-1',
        'applicationId' => '11111111-1111-4111-8111-111111111111',
        'resource' => 'users/canonical-user-1/mailFolders/inbox/messages',
        'changeType' => 'created,updated,deleted',
        'notificationUrl' => 'https://host.example/graph/changes',
        'lifecycleNotificationUrl' => 'https://host.example/graph/lifecycle',
        'clientState' => 'private-client-state',
        'includeResourceData' => false,
        'expirationDateTime' => $expiresAt->format(DATE_ATOM),
    ];
}

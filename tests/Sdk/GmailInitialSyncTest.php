<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Pyle\Mailbox\Contracts\SupportsInitialSync;
use Pyle\Mailbox\Drivers\Gmail\GmailClient;
use Pyle\Mailbox\Drivers\Gmail\GmailDeltaSync;
use Pyle\Mailbox\Drivers\Gmail\GmailTokenManager;
use Pyle\Mailbox\Drivers\MsGraph\RateLimiter;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncContinuation;
use Pyle\Mailbox\DTOs\SyncIdentity;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\InvalidSyncStateException;
use Pyle\Mailbox\Exceptions\ProviderServerException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Support\GrantAuthorization;
use Pyle\Mailbox\Support\ResponseBudget;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('does not present a capped eager Gmail import as a completed checkpoint', function (): void {
    config()->set('mailbox.max_query_pages', 1);
    $history = [];
    $client = initialSyncClient([
        new Response(200, [], '{"historyId":"100"}'),
        new Response(200, [], '{"messages":[{"id":"first"}],"nextPageToken":"second-page"}'),
        initialSyncMessage('first'),
    ], $history);

    expect(fn () => (new GmailDeltaSync($client))->syncFolder('same@example.com', 'INBOX'))
        ->toThrow(ApiRequestException::class, 'page limit')
        ->and($history)->toHaveCount(3);
});

it('captures the legacy initial baseline before a message can arrive during listing', function (): void {
    $history = [];
    $client = initialSyncClient([
        function ($request): Response {
            return str_ends_with($request->getUri()->getPath(), '/profile')
                ? new Response(200, [], '{"historyId":"100"}')
                : new Response(200, [], '{"messages":[{"id":"older"}]}');
        },
        function ($request): Response {
            return str_ends_with($request->getUri()->getPath(), '/messages')
                ? new Response(200, [], '{"messages":[{"id":"older"}]}')
                : initialSyncMessage('older');
        },
        function ($request): Response {
            return str_ends_with($request->getUri()->getPath(), '/profile')
                ? new Response(200, [], '{"historyId":"200"}')
                : initialSyncMessage('older');
        },
    ], $history);

    $result = (new GmailDeltaSync($client))->syncFolder('same@example.com', 'INBOX');

    expect($result->created->pluck('id')->all())->toBe(['older'])
        ->and($result->deltaLink)->toBe('100')
        ->and($history[0]['request']->getUri()->getPath())->toEndWith('/profile');
});

it('retains capped initial work and completes only after resuming the original baseline', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{"historyId":"100"}'),
        new Response(200, [], '{"messages":[{"id":"first"}],"nextPageToken":"second-page"}'),
        new Response(200, [], '{"messages":[{"id":"second"}]}'),
    ]));
    $stack->push(Middleware::history($history));
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'selected-token'],
    ]);
    $mailbox = Mailbox::forGrant(new GrantContext('connection', 'grant', '1', 'gmail', 'same@example.com'), $tokens, new Client(['handler' => $stack]));
    $scope = new SyncScope('INBOX');
    $budget = new SyncBudget(maxPages: 1);

    $first = $mailbox->initialSync($scope, $budget);

    expect($first->upserts)->toBe(['first'])
        ->and($first->checkpoint)->toBeNull()
        ->and($first->continuation)->not->toBeNull()
        ->and($first->reason)->toBe('page_limit');

    $second = $mailbox->initialSync($scope, $budget, $first->continuation);

    expect($second->upserts)->toBe(['second'])
        ->and($second->continuation)->toBeNull()
        ->and($second->checkpoint->position)->toBe('100')
        ->and($history)->toHaveCount(3)
        ->and((string) $history[2]['request']->getUri())->toContain('pageToken=second-page');
});

it('does not spend unbounded requests retrying a bounded read', function (): void {
    $history = [];
    $client = initialSyncClient([
        new Response(503, [], '{"error":"temporary"}'),
        new Response(200, [], '{"historyId":"100"}'),
    ], $history, ['max_retries' => 2, 'retry_backoff_base' => 0]);

    expect(fn () => $client->getBounded('users/same%40example.com/profile', new ResponseBudget(100), mailbox: 'same@example.com'))
        ->toThrow(ProviderServerException::class)
        ->and($history)->toHaveCount(1);
});

it('enforces aggregate response bytes on the real stream and can retry the unretained listing', function (int $status): void {
    $readBytes = 0;
    $closed = false;
    $body = Utils::streamFor('{"messages":[{"id":"'.str_repeat('x', 10000).'"}]}');
    $stream = FnStream::decorate($body, [
        'read' => function (int $length) use ($body, &$readBytes): string {
            $chunk = $body->read($length);
            $readBytes += strlen($chunk);

            return $chunk;
        },
        'close' => function () use ($body, &$closed): void {
            $closed = true;
            $body->close();
        },
    ]);
    $history = [];
    $mailbox = initialSyncResource([
        new Response(200, [], '{"historyId":"100"}'),
        new Response($status, ['Content-Length' => '1'], $stream),
        new Response(200, [], '{"messages":[{"id":"retained"}]}'),
    ], $history);

    $page = $mailbox->initialSync(new SyncScope, new SyncBudget(maxResponseBytes: 24));

    expect($page->checkpoint)->toBeNull()
        ->and($page->upserts)->toBe([])
        ->and($page->reason)->toBe('response_limit')
        ->and($readBytes)->toBe(6)
        ->and($closed)->toBeTrue()
        ->and($history[1]['options']['stream'])->toBeTrue();

    $resumed = $mailbox->initialSync(new SyncScope, new SyncBudget, $page->continuation);

    expect($resumed->upserts)->toBe(['retained'])
        ->and($resumed->checkpoint->position)->toBe('100')
        ->and($history)->toHaveCount(3);
})->with([200, 503]);

it('does not equate numeric-looking opaque lifecycle generations', function (): void {
    $history = [];
    $mailbox = initialSyncResource([], $history);
    $continuation = new SyncContinuation(
        new SyncIdentity('gmail', new GrantContext('connection', 'grant', '01', 'gmail', 'same@example.com'), new SyncScope),
        'gmail.initial',
        ['baseline' => '100', 'pageToken' => null, 'seenTokens' => []],
    );

    expect(fn () => $mailbox->initialSync(new SyncScope, new SyncBudget, $continuation))
        ->toThrow(InvalidSyncStateException::class)
        ->and($history)->toBe([]);
});

it('preserves the exact folder and cutoff across a request-budget interruption', function (): void {
    $history = [];
    $mailbox = initialSyncResource([
        new Response(200, [], '{"historyId":"100"}'),
        new Response(200, [], '{"messages":[{"id":"scoped"}],"nextPageToken":"more"}'),
    ], $history);
    $scope = new SyncScope('custom-label', new DateTimeImmutable('2026-01-01T00:00:00Z'));

    $baseline = $mailbox->initialSync($scope, new SyncBudget(maxRequests: 1));
    expect($baseline->upserts)->toBe([])
        ->and($baseline->checkpoint)->toBeNull()
        ->and($baseline->reason)->toBe('request_limit')
        ->and($history)->toHaveCount(1);

    $page = $mailbox->initialSync($scope, new SyncBudget(maxItems: 1), $baseline->continuation);
    parse_str($history[1]['request']->getUri()->getQuery(), $query);

    expect($page->upserts)->toBe(['scoped'])
        ->and($page->checkpoint)->toBeNull()
        ->and($page->reason)->toBe('item_limit')
        ->and($query)->toBe(['maxResults' => '1', 'includeSpamTrash' => '1', 'labelIds' => ['custom-label'], 'q' => 'after:1767225600']);
});

it('replays unretained page evidence from the last durable continuation', function (): void {
    $history = [];
    $mailbox = initialSyncResource([
        new Response(200, [], '{"historyId":"100"}'),
        new Response(200, [], '{"messages":[{"id":"retained"}],"nextPageToken":"resume"}'),
        new Response(200, [], '{"messages":[{"id":"unretained"}]}'),
        new Response(200, [], '{"messages":[{"id":"unretained"}]}'),
    ], $history);

    $durable = $mailbox->initialSync(new SyncScope, new SyncBudget);
    $lost = $mailbox->initialSync(new SyncScope, new SyncBudget, $durable->continuation);
    $replay = $mailbox->initialSync(new SyncScope, new SyncBudget, $durable->continuation);

    expect($lost->upserts)->toBe(['unretained'])
        ->and($replay->upserts)->toBe(['unretained'])
        ->and($replay->checkpoint->position)->toBe('100')
        ->and((string) $history[2]['request']->getUri())->toBe((string) $history[3]['request']->getUri());
});

it('keeps empty and duplicate pages unfinished when provider tokens cycle', function (): void {
    $history = [];
    $mailbox = initialSyncResource([
        new Response(200, [], '{"historyId":"100"}'),
        new Response(200, [], '{"nextPageToken":"a"}'),
        new Response(200, [], '{"messages":[{"id":"same"},{"id":"same"}],"nextPageToken":"b"}'),
        new Response(200, [], '{"messages":[{"id":"same"}],"nextPageToken":"a"}'),
    ], $history);

    $page = $mailbox->initialSync(new SyncScope, new SyncBudget(maxPages: 3));

    expect($page->upserts)->toBe(['same'])
        ->and($page->checkpoint)->toBeNull()
        ->and($page->reason)->toBe('provider_progress_repeated')
        ->and($page->continuation->position['pageToken'])->toBe('b')
        ->and($history)->toHaveCount(4);
});

it('does not complete a provider response with an unusable continuation', function (mixed $token): void {
    $history = [];
    $mailbox = initialSyncResource([
        new Response(200, [], '{"historyId":"100"}'),
        new Response(200, [], json_encode(['messages' => [], 'nextPageToken' => $token], JSON_THROW_ON_ERROR)),
    ], $history);

    $page = $mailbox->initialSync(new SyncScope, new SyncBudget);

    expect($page->checkpoint)->toBeNull()
        ->and($page->continuation)->not->toBeNull()
        ->and($page->reason)->toBe('provider_progress_missing');
})->with([null, '', ' ', 123]);

it('rejects continuation reuse outside its complete synchronization identity', function (string $difference): void {
    $history = [];
    $mailbox = initialSyncResource([], $history);
    $context = ['connectionId' => 'connection', 'grantId' => 'grant', 'lifecycleGeneration' => '1', 'driver' => 'gmail', 'mailbox' => 'same@example.com'];
    if (array_key_exists($difference, $context)) {
        $context[$difference] = 'different';
    }
    $scope = new SyncScope(
        folderId: $difference === 'folder' ? 'other-label' : null,
        importSince: $difference === 'cutoff' ? new DateTimeImmutable('2026-01-01') : null,
    );
    $identity = new SyncIdentity($difference === 'provider' ? 'ms-graph' : 'gmail', new GrantContext(...$context), $scope, $difference === 'version' ? 2 : 1);
    $continuation = new SyncContinuation($identity, $difference === 'phase' ? 'gmail.history' : 'gmail.initial', ['baseline' => '100', 'pageToken' => null, 'seenTokens' => []]);

    expect(fn () => $mailbox->initialSync(new SyncScope, new SyncBudget, $continuation))
        ->toThrow(InvalidSyncStateException::class)
        ->and($history)->toBe([]);
})->with(['provider', 'connectionId', 'grantId', 'lifecycleGeneration', 'driver', 'mailbox', 'folder', 'cutoff', 'version', 'phase']);

it('reports provider item-limit violations without promoting a checkpoint', function (): void {
    $history = [];
    $mailbox = initialSyncResource([
        new Response(200, [], '{"historyId":"100"}'),
        new Response(200, [], '{"messages":[{"id":"one"},{"id":"two"}]}'),
    ], $history);

    $page = $mailbox->initialSync(new SyncScope, new SyncBudget(maxItems: 1));

    expect($page->reason)->toBe('provider_item_limit')
        ->and($page->upserts)->toBe([])
        ->and($page->checkpoint)->toBeNull();
});

it('rejects a null message list instead of completing an empty import', function (): void {
    $history = [];
    $mailbox = initialSyncResource([
        new Response(200, [], '{"historyId":"100"}'),
        new Response(200, [], '{"messages":null}'),
    ], $history);

    expect(fn () => $mailbox->initialSync(new SyncScope, new SyncBudget))
        ->toThrow(ApiRequestException::class, 'message list');
});

it('refuses legacy initial completion without a history baseline', function (): void {
    $history = [];
    $client = initialSyncClient([new Response(200, [], '{}'), new Response(200, [], '{}')], $history);

    expect(fn () => (new GmailDeltaSync($client))->syncFolder('same@example.com', 'INBOX'))
        ->toThrow(ApiRequestException::class, 'baseline')
        ->and($history)->toHaveCount(1);
});

it('does not turn an unusable legacy listing token into completed initial delta', function (): void {
    $history = [];
    $client = initialSyncClient([
        new Response(200, [], '{"historyId":"100"}'),
        new Response(200, [], '{"messages":[],"nextPageToken":null}'),
    ], $history);

    expect(fn () => (new GmailDeltaSync($client))->syncFolder('same@example.com', 'INBOX'))
        ->toThrow(ApiRequestException::class, 'continuation')
        ->and($history)->toHaveCount(2);
});

it('refuses cutoff precision that the shared synchronization scope cannot retain', function (): void {
    expect(fn () => new SyncScope(importSince: new DateTimeImmutable('2026-01-01T00:00:00.500000Z')))
        ->toThrow(InvalidArgumentException::class, 'whole-second');
});

/** @param array<int, mixed> $responses
 * @param  array<int, mixed>  $history
 */
function initialSyncResource(array $responses, array &$history): SupportsInitialSync
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'selected-token'],
    ]);
    $resource = Mailbox::forGrant(new GrantContext('connection', 'grant', '1', 'gmail', 'same@example.com'), $tokens, new Client(['handler' => $stack]));
    expect($resource)->toBeInstanceOf(SupportsInitialSync::class);

    return $resource;
}

/** @param array<int, mixed> $responses
 * @param  array<int, mixed>  $history
 */
function initialSyncClient(array $responses, array &$history, array $config = []): GmailClient
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack]);
    $context = new GrantContext('connection', 'grant', '1', 'gmail', 'same@example.com');
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'selected-token'],
    ]);
    $config = array_merge(['max_retries' => 0, 'queue_retry_strategy' => 'sleep'], $config);

    return new GmailClient($config, new GmailTokenManager($config), new RateLimiter($config), $http, new GrantAuthorization($context, $tokens));
}

function initialSyncMessage(string $id): Response
{
    return new Response(200, [], json_encode(gmailMessageFixture(['id' => $id]), JSON_THROW_ON_ERROR));
}

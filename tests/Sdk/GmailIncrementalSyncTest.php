<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Pyle\Mailbox\Contracts\SupportsIncrementalSync;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncCheckpoint;
use Pyle\Mailbox\DTOs\SyncContinuation;
use Pyle\Mailbox\DTOs\SyncIdentity;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\InvalidSyncStateException;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('reconciles specific Gmail history evidence without guessing created versus updated or deleting removed labels', function (): void {
    $history = [];
    $mailbox = incrementalSyncResource([
        new Response(200, [], json_encode([
            'history' => [[
                'id' => '101',
                'messages' => [['id' => 'added'], ['id' => 'deleted'], ['id' => 'label-removed'], ['id' => 'generic']],
                'messagesAdded' => [['message' => ['id' => 'added']], ['message' => ['id' => 'added']]],
                'messagesDeleted' => [['message' => ['id' => 'deleted']]],
                'labelsAdded' => [['message' => ['id' => 'added'], 'labelIds' => ['INBOX']]],
                'labelsRemoved' => [['message' => ['id' => 'label-removed'], 'labelIds' => ['INBOX']]],
            ], [
                'id' => '101',
                'messagesAdded' => [['message' => ['id' => 'added']]],
                'messagesDeleted' => [['message' => ['id' => 'deleted']]],
                'labelsRemoved' => [['message' => ['id' => 'label-removed'], 'labelIds' => ['INBOX']]],
            ]],
            'historyId' => '90071992547409931234',
        ], JSON_THROW_ON_ERROR)),
    ], $history);
    $scope = new SyncScope('INBOX', new DateTimeImmutable('2026-01-01T00:00:00Z'));

    $page = $mailbox->incrementalSync($scope, new SyncBudget, incrementalSyncCheckpoint($scope));

    expect($page->upserts)->toBe(['added', 'label-removed', 'generic'])
        ->and($page->removals)->toBe(['deleted'])
        ->and($page->continuation)->toBeNull()
        ->and($page->checkpoint->position)->toBe('90071992547409931234')
        ->and($page->checkpoint->identity->scope)->toBe($scope)
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer selected-token');
    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    expect($history[0]['request']->getUri()->getPath())->toEndWith('/history')
        ->and($query)->toBe(['startHistoryId' => '100', 'maxResults' => '100']);
});

/** @param list<mixed> $responses
 * @param array<int, mixed> $history
 */
function incrementalSyncResource(array $responses, array &$history, ?TestGrantTokenProvider $tokens = null): SupportsIncrementalSync
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $tokens ??= new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'selected-token'],
    ]);
    $resource = Mailbox::forGrant(new GrantContext('connection', 'grant', '1', 'gmail', 'same@example.com'), $tokens, new Client(['handler' => $stack]));
    expect($resource)->toBeInstanceOf(SupportsIncrementalSync::class);

    return $resource;
}

function incrementalSyncCheckpoint(SyncScope $scope, string $position = '100'): SyncCheckpoint
{
    return new SyncCheckpoint(new SyncIdentity('gmail', new GrantContext('connection', 'grant', '1', 'gmail', 'same@example.com'), $scope), $position);
}

it('replays unretained history and resumes empty pages without promoting an intermediate checkpoint', function (): void {
    $history = [];
    $firstBody = '{"history":[{"id":"101","messagesAdded":[{"message":{"id":"first"}}]}],"historyId":"110","nextPageToken":"empty-page"}';
    $mailbox = incrementalSyncResource([
        new Response(200, [], $firstBody),
        new Response(200, [], $firstBody),
        new Response(200, [], '{"history":[],"historyId":"120","nextPageToken":"last-page"}'),
        new Response(200, [], '{"history":[{"id":"125","messagesAdded":[{"message":{"id":"arrived-during-round"}}]}],"historyId":"130"}'),
    ], $history);
    $scope = new SyncScope;
    $checkpoint = incrementalSyncCheckpoint($scope);

    $first = $mailbox->incrementalSync($scope, new SyncBudget, $checkpoint);
    $replay = $mailbox->incrementalSync($scope, new SyncBudget, $checkpoint);
    $empty = $mailbox->incrementalSync($scope, new SyncBudget, $checkpoint, $first->continuation);
    $last = $mailbox->incrementalSync($scope, new SyncBudget, $checkpoint, $empty->continuation);

    expect($first->upserts)->toBe(['first'])
        ->and($first->checkpoint)->toBeNull()
        ->and($first->reason)->toBe('page_limit')
        ->and($replay->upserts)->toBe($first->upserts)
        ->and($replay->continuation)->toEqual($first->continuation)
        ->and($empty->upserts)->toBe([])
        ->and($empty->checkpoint)->toBeNull()
        ->and($empty->continuation)->toBeInstanceOf(SyncContinuation::class)
        ->and($last->upserts)->toBe(['arrived-during-round'])
        ->and($last->checkpoint->position)->toBe('130')
        ->and((string) $history[0]['request']->getUri())->toBe((string) $history[1]['request']->getUri());
    parse_str($history[3]['request']->getUri()->getQuery(), $query);
    expect($query)->toBe(['startHistoryId' => '100', 'maxResults' => '100', 'pageToken' => 'last-page']);
});

it('does not advance past a single history record that expands beyond the retained item budget', function (): void {
    $history = [];
    $body = '{"history":[{"id":"101","messagesAdded":[{"message":{"id":"first"}},{"message":{"id":"overflow"}}]}],"historyId":"110"}';
    $mailbox = incrementalSyncResource([new Response(200, [], $body), new Response(200, [], $body)], $history);
    $scope = new SyncScope;
    $checkpoint = incrementalSyncCheckpoint($scope);

    $limited = $mailbox->incrementalSync($scope, new SyncBudget(maxItems: 1), $checkpoint);

    expect($limited->upserts)->toBe([])
        ->and($limited->checkpoint)->toBeNull()
        ->and($limited->reason)->toBe('provider_item_limit')
        ->and($limited->continuation->position['pageToken'])->toBeNull();

    $retained = $mailbox->incrementalSync($scope, new SyncBudget(maxItems: 2), $checkpoint, $limited->continuation);

    expect($retained->upserts)->toBe(['first', 'overflow'])
        ->and($retained->checkpoint->position)->toBe('110');
    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    expect($query['maxResults'])->toBe('1');
});

it('requires scoped recovery after expired history and catches arrivals from the new pre-scan baseline', function (): void {
    $history = [];
    $mailbox = incrementalSyncResource([
        new Response(404, [], '{"error":{"code":404,"message":"History expired"}}'),
        new Response(200, [], '{"historyId":"200"}'),
        new Response(200, [], '{"messages":[{"id":"retained-scoped-message"}]}'),
        new Response(200, [], '{"history":[{"id":"201","messagesAdded":[{"message":{"id":"arrived-during-recovery"}}]}],"historyId":"210"}'),
    ], $history);
    $scope = new SyncScope('original-label', new DateTimeImmutable('2026-01-01T00:00:00Z'));
    $checkpoint = incrementalSyncCheckpoint($scope);

    $expired = $mailbox->incrementalSync($scope, new SyncBudget, $checkpoint);

    expect($expired->resetRequired)->toBeTrue()
        ->and($expired->reason)->toBe('history_expired')
        ->and($expired->upserts)->toBe([])
        ->and($expired->removals)->toBe([])
        ->and($expired->continuation)->toBeNull()
        ->and($expired->checkpoint)->toBeNull();

    $recovery = $mailbox->initialSync($checkpoint->identity->scope, new SyncBudget);
    $caughtUp = $mailbox->incrementalSync($scope, new SyncBudget, $recovery->checkpoint);

    expect($recovery->upserts)->toBe(['retained-scoped-message'])
        ->and($recovery->checkpoint->position)->toBe('200')
        ->and($caughtUp->upserts)->toBe(['arrived-during-recovery'])
        ->and($caughtUp->checkpoint->position)->toBe('210')
        ->and($history)->toHaveCount(4);
    parse_str($history[2]['request']->getUri()->getQuery(), $query);
    expect($query['labelIds'])->toBe(['original-label'])
        ->and($query['q'])->toBe('after:1767225600')
        ->and((string) $history[3]['request']->getUri())->toContain('startHistoryId=200');
});

it('does not treat a present invalid history token as terminal progress', function (mixed $token): void {
    $history = [];
    $mailbox = incrementalSyncResource([new Response(200, [], json_encode([
        'history' => [['id' => '101', 'messages' => [['id' => 'unretained']]]],
        'historyId' => '110',
        'nextPageToken' => $token,
    ], JSON_THROW_ON_ERROR))], $history);
    $scope = new SyncScope;

    $page = $mailbox->incrementalSync($scope, new SyncBudget, incrementalSyncCheckpoint($scope));

    expect($page->checkpoint)->toBeNull()
        ->and($page->upserts)->toBe([])
        ->and($page->reason)->toBe('provider_progress_missing')
        ->and($page->continuation->position['pageToken'])->toBeNull();
})->with([null, '', 12]);

it('keeps the last retained history position when the provider cycles a continuation', function (bool $immediate): void {
    $history = [];
    $responses = [new Response(200, [], '{"history":[{"id":"101","messages":[{"id":"retained"}]}],"historyId":"110","nextPageToken":"repeat"}')];
    if (! $immediate) {
        $responses[] = new Response(200, [], '{"history":[{"id":"102","messages":[{"id":"retained-second"}]}],"historyId":"120","nextPageToken":"later"}');
    }
    $responses[] = new Response(200, [], '{"history":[{"id":"103","messages":[{"id":"unretained"}]}],"historyId":"130","nextPageToken":"repeat"}');
    $mailbox = incrementalSyncResource($responses, $history);
    $scope = new SyncScope;

    $page = $mailbox->incrementalSync($scope, new SyncBudget(maxPages: 4), incrementalSyncCheckpoint($scope));

    expect($page->upserts)->toBe($immediate ? ['retained'] : ['retained', 'retained-second'])
        ->and($page->checkpoint)->toBeNull()
        ->and($page->reason)->toBe('provider_progress_repeated')
        ->and($page->continuation->position['pageToken'])->toBe($immediate ? 'repeat' : 'later')
        ->and($history)->toHaveCount($immediate ? 2 : 3);
})->with([true, false]);

it('does not complete history without a valid nonregressing mailbox position', function (string $body): void {
    $history = [];
    $mailbox = incrementalSyncResource([new Response(200, [], $body)], $history);
    $scope = new SyncScope;

    $page = $mailbox->incrementalSync($scope, new SyncBudget, incrementalSyncCheckpoint($scope));

    expect($page->checkpoint)->toBeNull()
        ->and($page->upserts)->toBe([])
        ->and($page->reason)->toBe('provider_progress_missing')
        ->and($page->continuation->position['pageToken'])->toBeNull();
})->with([
    '{}',
    '{"historyId":null}',
    '{"historyId":"invalid"}',
    '{"historyId":"90"}',
    '{"history":[{"id":"121","messages":[{"id":"unretained"}]}],"historyId":"120"}',
]);

it('rejects history state from another lifecycle policy or round before provider IO', function (string $difference): void {
    $history = [];
    $mailbox = incrementalSyncResource([], $history);
    $scope = new SyncScope('INBOX', new DateTimeImmutable('2026-01-01T00:00:00Z'));
    $context = new GrantContext('connection', 'grant', $difference === 'generation' ? '01' : '1', 'gmail', 'same@example.com');
    $checkpoint = new SyncCheckpoint(new SyncIdentity('gmail', $context, $difference === 'cutoff' ? new SyncScope('INBOX') : $scope), $difference === 'checkpoint' ? '' : '100');
    $continuation = $difference === 'grant'
        ? new SyncContinuation(new SyncIdentity('gmail', new GrantContext('connection', 'other-grant', '1', 'gmail', 'same@example.com'), $scope), 'gmail.history', ['baseline' => '100', 'pageToken' => 'next', 'seenTokens' => []])
        : new SyncContinuation($checkpoint->identity, $difference === 'phase' ? 'gmail.initial' : 'gmail.history', ['baseline' => $difference === 'baseline' ? '99' : '100', 'pageToken' => 'next', 'seenTokens' => []]);

    expect(fn () => $mailbox->incrementalSync($scope, new SyncBudget, $checkpoint, $continuation))
        ->toThrow(InvalidSyncStateException::class)
        ->and($history)->toBe([]);
})->with(['generation', 'cutoff', 'grant', 'baseline', 'phase', 'checkpoint']);

it('stops subsequent history IO when the host supersedes a retained grant', function (): void {
    $history = [];
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'selected-token'],
    ]);
    $mailbox = incrementalSyncResource([
        function () use ($tokens): Response {
            $tokens->grants['grant']['generation'] = '2';

            return new Response(200, [], '{"historyId":"110","nextPageToken":"next"}');
        },
        new Response(200, [], '{"historyId":"120"}'),
    ], $history, $tokens);
    $scope = new SyncScope;

    expect(fn () => $mailbox->incrementalSync($scope, new SyncBudget(maxPages: 2), incrementalSyncCheckpoint($scope)))
        ->toThrow(GrantAccessDeniedException::class)
        ->and($history)->toHaveCount(1);
});

it('retains only completed history pages when a host budget interrupts the round', function (string $reason): void {
    $history = [];
    $responses = [new Response(200, [], '{"history":[{"id":"101","messages":[{"id":"retained"}]}],"historyId":"110","nextPageToken":"next"}')];
    if ($reason === 'response_limit') {
        $responses[] = new Response(200, ['Content-Length' => '1'], '{"historyId":"120","unused":"'.str_repeat('x', 1000).'"}');
    }
    $responses[] = new Response(200, [], '{"history":[{"id":"111","messages":[{"id":"replayed"}]}],"historyId":"120"}');
    $mailbox = incrementalSyncResource($responses, $history);
    $scope = new SyncScope;
    $checkpoint = incrementalSyncCheckpoint($scope);
    $budget = new SyncBudget(maxPages: 3, maxItems: $reason === 'item_limit' ? 1 : 100, maxRequests: $reason === 'request_limit' ? 1 : 10, maxResponseBytes: $reason === 'response_limit' ? 120 : 8388608);

    $limited = $mailbox->incrementalSync($scope, $budget, $checkpoint);

    expect($limited->upserts)->toBe(['retained'])
        ->and($limited->checkpoint)->toBeNull()
        ->and($limited->reason)->toBe($reason)
        ->and($limited->continuation->position['pageToken'])->toBe('next')
        ->and($history)->toHaveCount($reason === 'response_limit' ? 2 : 1);

    $resumed = $mailbox->incrementalSync($scope, new SyncBudget, $checkpoint, $limited->continuation);

    expect($resumed->upserts)->toBe(['replayed'])
        ->and($resumed->checkpoint->position)->toBe('120');
})->with(['item_limit', 'request_limit', 'response_limit']);

it('rejects malformed history evidence instead of completing an empty round', function (string $body): void {
    $history = [];
    $mailbox = incrementalSyncResource([new Response(200, [], $body)], $history);
    $scope = new SyncScope;

    expect(fn () => $mailbox->incrementalSync($scope, new SyncBudget, incrementalSyncCheckpoint($scope)))
        ->toThrow(ApiRequestException::class)
        ->and($history)->toHaveCount(1);
})->with([
    '{"history":null,"historyId":"110"}',
    '{"history":[{}],"historyId":"110"}',
    '{"history":[{"id":"101","messagesAdded":[{}]}],"historyId":"110"}',
    '{"history":[{"id":"101","labelsRemoved":null}],"historyId":"110"}',
]);

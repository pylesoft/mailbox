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
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\InvalidSyncStateException;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Facades\Mailbox;
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
 * @param  array<int, mixed>  $history
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

it('drains a single expanded history record without another provider read or an early checkpoint', function (): void {
    $history = [];
    $body = '{"history":[{"id":"101","messagesAdded":[{"message":{"id":"first"}},{"message":{"id":"overflow"}}]}],"historyId":"110"}';
    $mailbox = incrementalSyncResource([new Response(200, [], $body)], $history);
    $scope = new SyncScope;
    $checkpoint = incrementalSyncCheckpoint($scope);

    $limited = $mailbox->incrementalSync($scope, new SyncBudget(maxItems: 1), $checkpoint);

    expect($limited->upserts)->toBe(['first'])
        ->and($limited->checkpoint)->toBeNull()
        ->and($limited->reason)->toBe('item_limit')
        ->and($limited->continuation->position['pageToken'])->toBeNull();

    $retained = $mailbox->incrementalSync($scope, new SyncBudget(maxItems: 2), $checkpoint, $limited->continuation);

    expect($retained->upserts)->toBe(['overflow'])
        ->and($retained->checkpoint->position)->toBe('110')
        ->and($history)->toHaveCount(1);
    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    expect($query['maxResults'])->toBe('1');
});

it('retains a bulk history page across fixed budgets and preserves later deletion ordering', function (): void {
    $history = [];
    $ids = array_map(static fn (int $id): string => 'old-'.$id, range(1, 205));
    $mailbox = incrementalSyncResource([
        new Response(200, [], json_encode(['history' => [['id' => '101',
            'labelsAdded' => array_map(static fn (string $id): array => ['message' => ['id' => $id]], $ids),
            'messagesDeleted' => [['message' => ['id' => 'deleted']]],
        ]], 'historyId' => '110', 'nextPageToken' => 'later'], JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"history":[{"id":"111","messagesDeleted":[{"message":{"id":"old-1"}}]}],"historyId":"120"}'),
    ], $history);
    $scope = new SyncScope(null, new DateTimeImmutable('2026-01-01T00:00:00Z'));
    $checkpoint = incrementalSyncCheckpoint($scope);
    $budget = new SyncBudget(maxItems: 100, maxPages: 1, maxRequests: 1);

    $first = $mailbox->incrementalSync($scope, $budget, $checkpoint);
    $second = $mailbox->incrementalSync($scope, $budget, $checkpoint, $first->continuation);

    expect($first->upserts)->toBe(array_slice($ids, 0, 100))
        ->and($first->checkpoint)->toBeNull()
        ->and($second->upserts)->toBe(array_slice($ids, 100, 100))
        ->and($second->checkpoint)->toBeNull()
        ->and($history)->toHaveCount(1);

    $tail = $mailbox->incrementalSync($scope, $budget, $checkpoint, $second->continuation);

    expect($tail->upserts)->toBe(array_slice($ids, 200))
        ->and($tail->removals)->toBe(['deleted'])
        ->and($tail->reason)->toBe('page_limit')
        ->and($tail->checkpoint)->toBeNull()
        ->and($history)->toHaveCount(1);

    $last = $mailbox->incrementalSync($scope, $budget, $checkpoint, $tail->continuation);

    expect($last->upserts)->toBe([])
        ->and($last->removals)->toBe(['old-1'])
        ->and($last->checkpoint->position)->toBe('120')
        ->and($history)->toHaveCount(2);
    parse_str($history[1]['request']->getUri()->getQuery(), $query);
    expect($query['pageToken'])->toBe('later');
});

it('returns retained history tail evidence before inspecting an expired next page', function (): void {
    $history = [];
    $mailbox = incrementalSyncResource([
        new Response(200, [], '{"history":[{"id":"101","labelsAdded":[{"message":{"id":"first"}},{"message":{"id":"older-tail"}}]}],"historyId":"110","nextPageToken":"expired"}'),
        new Response(404, [], '{}'),
    ], $history);
    $scope = new SyncScope(null, new DateTimeImmutable('2026-01-01T00:00:00Z'));
    $checkpoint = incrementalSyncCheckpoint($scope);
    $first = $mailbox->incrementalSync($scope, new SyncBudget(maxItems: 1), $checkpoint);

    $tail = $mailbox->incrementalSync($scope, new SyncBudget(maxItems: 100, maxPages: 1), $checkpoint, $first->continuation);

    expect($tail->upserts)->toBe(['older-tail'])
        ->and($tail->resetRequired)->toBeFalse()
        ->and($tail->reason)->toBe('page_limit')
        ->and($history)->toHaveCount(1);

    $expired = $mailbox->incrementalSync($scope, new SyncBudget, $checkpoint, $tail->continuation);

    expect($expired->upserts)->toBe([])
        ->and($expired->resetRequired)->toBeTrue()
        ->and($history)->toHaveCount(2);
});

it('counts only actual requests when a retained history page and a new page fit the page budget', function (): void {
    $history = [];
    $mailbox = incrementalSyncResource([
        new Response(200, [], '{"history":[{"id":"101","messages":[{"id":"first"},{"id":"tail"}]}],"historyId":"110","nextPageToken":"next"}'),
        new Response(200, [], '{"history":[{"id":"111","messages":[{"id":"new-page"}]}],"historyId":"120"}'),
    ], $history);
    $scope = new SyncScope;
    $checkpoint = incrementalSyncCheckpoint($scope);
    $first = $mailbox->incrementalSync($scope, new SyncBudget(maxItems: 1), $checkpoint);

    $last = $mailbox->incrementalSync($scope, new SyncBudget(maxPages: 2, maxRequests: 1), $checkpoint, $first->continuation);

    expect($last->upserts)->toBe(['tail', 'new-page'])
        ->and($last->checkpoint->position)->toBe('120')
        ->and($history)->toHaveCount(2);
});

it('rejects malformed retained history page evidence before provider IO', function (string $difference): void {
    $history = [];
    $mailbox = incrementalSyncResource([], $history);
    $scope = new SyncScope;
    $checkpoint = incrementalSyncCheckpoint($scope);
    $pending = ['upserts' => ['remaining'], 'removals' => [], 'historyId' => '110', 'nextPageToken' => 'next'];
    match ($difference) {
        'null' => $pending = null,
        'non-list' => $pending['upserts'] = ['key' => 'remaining'],
        'invalid ID' => $pending['upserts'] = [1],
        'duplicates' => $pending['upserts'] = ['remaining', 'remaining'],
        'overlap' => $pending['removals'] = ['remaining'],
        'empty' => $pending['upserts'] = [],
        'position' => $pending['historyId'] = 'invalid',
        'regressed position' => $pending['historyId'] = '99',
        'repeated token' => $pending['nextPageToken'] = 'current',
        'seen token' => $pending['nextPageToken'] = 'old',
        'missing token' => $pending = array_diff_key($pending, ['nextPageToken' => true]),
    };
    $continuation = new SyncContinuation($checkpoint->identity, 'gmail.history', ['baseline' => '100',
        'pageToken' => 'current', 'seenTokens' => [hash('sha256', 'old')], 'pending' => $pending]);

    expect(fn () => $mailbox->incrementalSync($scope, new SyncBudget, $checkpoint, $continuation))
        ->toThrow(InvalidSyncStateException::class)
        ->and($history)->toBe([]);
})->with(['null', 'non-list', 'invalid ID', 'duplicates', 'overlap', 'empty', 'position', 'regressed position', 'repeated token', 'seen token', 'missing token']);

it('does not retain an overrequested history record collection as pending changes', function (): void {
    $history = [];
    $mailbox = incrementalSyncResource([new Response(200, [], '{"history":[{"id":"101","messages":[{"id":"first"}]},{"id":"102","messages":[{"id":"second"}]}],"historyId":"110"}')], $history);
    $scope = new SyncScope;

    $page = $mailbox->incrementalSync($scope, new SyncBudget(maxItems: 1), incrementalSyncCheckpoint($scope));

    expect($page->reason)->toBe('provider_item_limit')
        ->and($page->upserts)->toBe([])
        ->and($page->checkpoint)->toBeNull()
        ->and($page->continuation->position)->not->toHaveKey('pending');
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
        ->toThrow(MailboxException::class)
        ->and($history)->toHaveCount(1);
})->with([
    '{"history":null,"historyId":"110"}',
    '{"history":{},"historyId":"110"}',
    '{"history":{"0":{"id":"101"}},"historyId":"110"}',
    '{"history":[{"id":"101","messages":{}}],"historyId":"110"}',
    '{"history":[{"id":"101","messages":{"0":{"id":"one"}}}],"historyId":"110"}',
    '{"history":[{"id":"101","messagesAdded":{}}],"historyId":"110"}',
    '{"history":[{"id":"101","messagesAdded":{"0":{"message":{"id":"one"}}}}],"historyId":"110"}',
    '{"history":[{"id":"101","messagesDeleted":{}}],"historyId":"110"}',
    '{"history":[{"id":"101","labelsAdded":{}}],"historyId":"110"}',
    '{"history":[{"id":"101","labelsRemoved":{}}],"historyId":"110"}',
    '{"history":[{}],"historyId":"110"}',
    '{"history":[{"id":"101","messagesAdded":[{}]}],"historyId":"110"}',
    '{"history":[{"id":"101","labelsRemoved":null}],"historyId":"110"}',
]);

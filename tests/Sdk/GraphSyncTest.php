<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Pyle\Mailbox\Contracts\SupportsIncrementalSync;
use Pyle\Mailbox\Contracts\SupportsInitialSync;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncCheckpoint;
use Pyle\Mailbox\DTOs\SyncContinuation;
use Pyle\Mailbox\DTOs\SyncIdentity;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\AuthenticationException;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\InvalidSyncStateException;
use Pyle\Mailbox\Exceptions\MailboxAccessDeniedException;
use Pyle\Mailbox\Exceptions\ResourceNotFoundException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('resumes Graph folder deltas through documented opaque next and delta links', function (): void {
    $history = [];
    $next = "https://graph.microsoft.com/v1.0/users/same%40example.com/mailfolders('FolderA')/messages/delta?\$skiptoken=a%2bb%2Fc%3D&repeat=one&repeat=two&literal=a+b";
    $delta = "https://graph.microsoft.com/v1.0/users/same%40example.com/mailfolders('FolderA')/messages/delta?\$deltatoken=opaque%2Fdelta%3D";
    $mailbox = graphSyncResource([
        new Response(200, [], json_encode(['value' => [['id' => 'first', '@odata.etag' => 'same']], '@odata.nextLink' => $next], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['value' => [['id' => 'second'], ['id' => 'moved', '@removed' => ['reason' => 'deleted']]], '@odata.deltaLink' => $delta], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['value' => [], '@odata.deltaLink' => $delta], JSON_THROW_ON_ERROR)),
    ], $history, httpOptions: ['query' => ['unrelated' => 'default']]);
    $scope = new SyncScope('FolderA', new DateTimeImmutable('2026-01-01T00:00:00Z'));

    $first = $mailbox->initialSync($scope, new SyncBudget);
    $last = $mailbox->initialSync($scope, new SyncBudget, $first->continuation);
    $round = $mailbox->incrementalSync($scope, new SyncBudget, $last->checkpoint);

    expect($first->upserts)->toBe(['first'])
        ->and($first->checkpoint)->toBeNull()
        ->and($last->upserts)->toBe(['second'])
        ->and($last->removals)->toBe(['moved'])
        ->and($last->checkpoint)->not->toBeNull()
        ->and($round->checkpoint)->not->toBeNull()
        ->and((string) $history[1]['request']->getUri())->toBe($next)
        ->and((string) $history[2]['request']->getUri())->toBe($delta)
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer selected-token');
    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    expect($query)->toBe(['$select' => 'id', '$top' => '100']);
});

it('enumerates mailbox folders and retains independent progress until every covered folder finishes', function (): void {
    $history = [];
    $a = graphSyncLink('A', 'delta-a');
    $b = graphSyncLink('01', 'delta-b');
    $child = graphSyncLink('nested', 'delta-child');
    $next = graphSyncLink('01', 'next-b', true);
    $foldersNext = 'https://graph.microsoft.com/v1.0/users/same%40example.com/mailfolders?$skiptoken=folders%2Fpage%3D&repeat=one&repeat=two';
    $mailbox = graphSyncResource([
        new Response(200, [], json_encode(['value' => [['id' => 'A']], '@odata.nextLink' => $foldersNext], JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"value":[{"id":"A"},{"id":"01","isHidden":true}]}'),
        new Response(200, [], '{"value":[{"id":"nested"}]}'),
        new Response(200, [], '{"value":[]}'),
        new Response(200, [], '{"value":[]}'),
        new Response(200, [], json_encode(['value' => [['id' => 'moved', '@removed' => ['reason' => 'deleted']]], '@odata.deltaLink' => $a], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['value' => [['id' => 'moved'], ['id' => 'moved']], '@odata.nextLink' => $next], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['value' => [], '@odata.deltaLink' => $b], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['value' => [['id' => 'older-candidate']], '@odata.deltaLink' => $child], JSON_THROW_ON_ERROR)),
    ], $history);
    $scope = new SyncScope(importSince: new DateTimeImmutable('2026-01-01T00:00:00Z'));

    $first = $mailbox->initialSync($scope, new SyncBudget(maxPages: 6));
    $second = $mailbox->initialSync($scope, new SyncBudget, $first->continuation);
    $last = $mailbox->initialSync($scope, new SyncBudget(maxPages: 2), $second->continuation);

    expect($first->coveredFolders)->toBe(['A', '01', 'nested'])
        ->and($first->folderChanges)->toBe([['folderId' => 'A', 'upserts' => [], 'removals' => ['moved']]])
        ->and($first->checkpoint)->toBeNull()
        ->and($first->continuation->position['folders'][0]['deltaLink'])->toBe($a)
        ->and($second->upserts)->toBe(['moved'])
        ->and($second->folderChanges)->toBe([['folderId' => '01', 'upserts' => ['moved'], 'removals' => []]])
        ->and($second->checkpoint)->toBeNull()
        ->and($last->upserts)->toBe(['older-candidate'])
        ->and($last->coveredFolders)->toBe(['A', '01', 'nested'])
        ->and($last->continuation)->toBeNull()
        ->and(json_decode($last->checkpoint->position, true, flags: JSON_THROW_ON_ERROR))->toBe(['folders' => [
            ['folderId' => 'A', 'deltaLink' => $a],
            ['folderId' => '01', 'deltaLink' => $b],
            ['folderId' => 'nested', 'deltaLink' => $child],
        ]])
        ->and($history)->toHaveCount(9);
    foreach ([$history[0], ...array_slice($history, 2, 3)] as $entry) {
        parse_str($entry['request']->getUri()->getQuery(), $query);
        expect($query['includeHiddenFolders'])->toBe('true');
    }
    expect((string) $history[1]['request']->getUri())->toBe($foldersNext)
        ->and($history[2]['request']->getUri()->getPath())->toEndWith('/mailFolders/A/childFolders')
        ->and($history[3]['request']->getUri()->getPath())->toEndWith('/mailFolders/01/childFolders')
        ->and((string) $history[7]['request']->getUri())->toBe($next);
});

function graphSyncLink(string $folder, string $token, bool $next = false): string
{
    return 'https://graph.microsoft.com/v1.0/users/same%40example.com/mailFolders/'.rawurlencode($folder).'/messages/delta?'.($next ? '$skiptoken=' : '$deltatoken=').$token;
}

it('requests an original-scope Graph reset for expired state without retaining provider error secrets', function (int $status, array $error): void {
    $history = [];
    $mailbox = graphSyncResource([
        new Response($status, ['Location' => 'https://evil.example/reset'], json_encode(['error' => $error], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['value' => [['id' => 'retained-history-candidate']], '@odata.deltaLink' => graphSyncLink('FolderA', 'new-baseline')], JSON_THROW_ON_ERROR)),
    ], $history);
    $scope = new SyncScope('FolderA', new DateTimeImmutable('2026-01-01T00:00:00Z'));
    $checkpoint = graphSyncCheckpoint($scope);

    $expired = $mailbox->incrementalSync($scope, new SyncBudget, $checkpoint);

    expect($expired->resetRequired)->toBeTrue()
        ->and($expired->reason)->toBe('delta_expired')
        ->and($expired->checkpoint)->toBeNull()
        ->and($expired->continuation)->toBeNull()
        ->and($expired->upserts)->toBe([])
        ->and($expired->removals)->toBe([])
        ->and($expired->coveredFolders)->toBe(['FolderA'])
        ->and(json_encode($expired, JSON_THROW_ON_ERROR))->not->toContain('private-provider-secret')
        ->and($history)->toHaveCount(1);

    $recovery = $mailbox->initialSync($checkpoint->identity->scope, new SyncBudget);

    expect($recovery->upserts)->toBe(['retained-history-candidate'])
        ->and($recovery->checkpoint->identity->scope)->toBe($scope)
        ->and($history[1]['request']->getUri()->getPath())->toEndWith('/mailFolders/FolderA/messages/delta');
})->with([
    'expired token' => [400, ['code' => 'syncStateNotFound', 'message' => 'private-provider-secret']],
    'nested reset code' => [400, ['code' => 'badRequest', 'innerError' => ['code' => 'syncStateNotFound'], 'message' => 'private-provider-secret']],
    'synchronization reset' => [410, ['code' => 'Gone', 'message' => 'private-provider-secret']],
]);

function graphSyncCheckpoint(SyncScope $scope, ?string $position = null): SyncCheckpoint
{
    return new SyncCheckpoint(
        new SyncIdentity('ms-graph', new GrantContext('connection', 'grant', '1', 'ms-graph', 'same@example.com'), $scope),
        $position ?? json_encode(['folders' => [['folderId' => $scope->folderId, 'deltaLink' => graphSyncLink($scope->folderId, 'old-baseline')]]], JSON_THROW_ON_ERROR),
    );
}

it('keeps unrelated Graph errors explicit and redacted instead of treating them as expired state', function (int $status, string $exception): void {
    $history = [];
    $mailbox = graphSyncResource([new Response($status, [], '{"error":{"code":"private-provider-secret","message":"private-provider-secret"}}')], $history);
    $scope = new SyncScope('FolderA');

    try {
        $mailbox->incrementalSync($scope, new SyncBudget, graphSyncCheckpoint($scope));
        test()->fail('An unrelated Graph error must remain an explicit failure.');
    } catch (Throwable $error) {
        $diagnostics = $error->getMessage().json_encode($error, JSON_THROW_ON_ERROR).($error->getPrevious()?->getMessage() ?? '');
        expect($error)->toBeInstanceOf($exception)
            ->and($diagnostics)->not->toContain('private-provider-secret')
            ->and($diagnostics)->not->toContain('old-baseline');
        if ($error instanceof ApiRequestException) {
            expect($error->providerCode)->toBeNull();
        }
    }
    expect($history)->toHaveCount(1);
})->with([
    'invalid request' => [400, ApiRequestException::class],
    'missing folder' => [404, ResourceNotFoundException::class],
    'permission denied' => [403, MailboxAccessDeniedException::class],
    'authentication denied' => [401, AuthenticationException::class],
]);

it('enforces the actual error response byte budget before interpreting a Graph reset code', function (): void {
    $history = [];
    $body = json_encode(['error' => ['code' => 'syncStateNotFound', 'message' => 'private-provider-secret'.str_repeat('x', 200)]], JSON_THROW_ON_ERROR);
    $mailbox = graphSyncResource([new Response(400, ['Content-Length' => '1'], $body)], $history);
    $scope = new SyncScope('FolderA');
    $checkpoint = graphSyncCheckpoint($scope);

    $page = $mailbox->incrementalSync($scope, new SyncBudget(maxResponseBytes: 64), $checkpoint);

    expect($page->reason)->toBe('response_limit')
        ->and($page->resetRequired)->toBeFalse()
        ->and($page->checkpoint)->toBeNull()
        ->and($page->continuation->position['baseline'])->toBe($checkpoint->position)
        ->and($page->continuation->position['folders'][0]['nextLink'])->toBe(graphSyncLink('FolderA', 'old-baseline'))
        ->and(json_encode($page, JSON_THROW_ON_ERROR))->not->toContain('private-provider-secret')
        ->and($history)->toHaveCount(1);
});

it('rejects malformed or mismatched Graph state before token access or provider IO', function (string $difference): void {
    $history = [];
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'selected-token'],
    ]);
    $mailbox = graphSyncResource([], $history, $tokens);
    $scope = new SyncScope('01', new DateTimeImmutable('2026-01-01T00:00:00Z'));
    $checkpoint = graphSyncCheckpoint($scope);
    $identity = $checkpoint->identity;
    $position = ['baseline' => $checkpoint->position, 'enumeration' => [], 'folders' => [
        ['folderId' => '01', 'nextLink' => graphSyncLink('01', 'next', true), 'deltaLink' => null, 'seenTokens' => []],
    ]];
    $phase = 'ms-graph.delta';
    switch ($difference) {
        case 'checkpoint JSON':
            $checkpoint = graphSyncCheckpoint($scope, 'invalid');
            break;
        case 'checkpoint folder':
            $checkpoint = graphSyncCheckpoint($scope, json_encode(['folders' => [['folderId' => '1', 'deltaLink' => graphSyncLink('1', 'old')]]], JSON_THROW_ON_ERROR));
            break;
        case 'checkpoint target':
            $checkpoint = graphSyncCheckpoint($scope, json_encode(['folders' => [['folderId' => '01', 'deltaLink' => graphSyncLink('1', 'old')]]], JSON_THROW_ON_ERROR));
            break;
        case 'folder list':
            $position['folders'] = null;
            break;
        case 'folder scope':
            $position['folders'][0]['folderId'] = '1';
            break;
        case 'progress target':
            $position['folders'][0]['nextLink'] = graphSyncLink('1', 'next', true);
            break;
        case 'progress evidence':
            $position['folders'][0]['seenTokens'] = ['invalid'];
            break;
        case 'conflicting positions':
            $position['folders'][0]['deltaLink'] = graphSyncLink('01', 'complete');
            break;
        case 'completed continuation':
            $position['folders'][0]['nextLink'] = null;
            $position['folders'][0]['deltaLink'] = graphSyncLink('01', 'complete');
            break;
        case 'enumeration':
            $position['enumeration'] = [['folderId' => '1', 'nextLink' => null, 'seenTokens' => []]];
            break;
        case 'phase':
            $phase = 'gmail.history';
            break;
        case 'baseline':
            $position['baseline'] = 'another-round';
            break;
        case 'format':
            $identity = new SyncIdentity('ms-graph', $identity->context, $scope, 2);
            break;
        case 'generation':
            $identity = new SyncIdentity('ms-graph', new GrantContext('connection', 'grant', '01', 'ms-graph', 'same@example.com'), $scope);
            break;
        case 'cutoff':
            $identity = new SyncIdentity('ms-graph', $identity->context, new SyncScope('01'));
            break;
    }
    if (str_starts_with($difference, 'checkpoint')) {
        $position['baseline'] = $checkpoint->position;
    }
    $continuation = new SyncContinuation($identity, $phase, $position);

    expect(fn () => $mailbox->incrementalSync($scope, new SyncBudget, $checkpoint, $continuation))->toThrow(InvalidSyncStateException::class)
        ->and($tokens->tokenReads)->toBe(0)
        ->and($history)->toBe([]);
})->with(['checkpoint JSON', 'checkpoint folder', 'checkpoint target', 'folder list', 'folder scope', 'progress target', 'progress evidence', 'conflicting positions', 'completed continuation', 'enumeration', 'phase', 'baseline', 'format', 'generation', 'cutoff']);

it('keeps Graph work unfinished when its expected progress token is absent or unusable', function (array $progress): void {
    $history = [];
    $mailbox = graphSyncResource([new Response(200, [], json_encode(['value' => [['id' => 'unretained']], ...$progress], JSON_THROW_ON_ERROR))], $history);

    $page = $mailbox->initialSync(new SyncScope('FolderA'), new SyncBudget);

    expect($page->upserts)->toBe([])
        ->and($page->checkpoint)->toBeNull()
        ->and($page->reason)->toBe('provider_progress_missing')
        ->and($page->continuation->position['folders'][0]['nextLink'])->toBeNull()
        ->and($history)->toHaveCount(1);
})->with([
    'no progress' => [[]],
    'conflicting progress' => [['@odata.nextLink' => graphSyncLink('FolderA', 'next', true), '@odata.deltaLink' => graphSyncLink('FolderA', 'delta')]],
    'null token' => [['@odata.nextLink' => null]],
    'wrong next token' => [['@odata.nextLink' => graphSyncLink('FolderA', 'delta')]],
    'wrong completion token' => [['@odata.deltaLink' => graphSyncLink('FolderA', 'next', true)]],
    'empty token' => [['@odata.deltaLink' => graphSyncLink('FolderA', '')]],
]);

it('retains completed Graph pages and replays the unfinished page after a host budget stops the round', function (string $reason): void {
    $history = [];
    $next = graphSyncLink('FolderA', 'next', true);
    $first = json_encode(['value' => [['id' => 'retained']], '@odata.nextLink' => $next], JSON_THROW_ON_ERROR);
    $responses = [new Response(200, [], $first)];
    if ($reason === 'response_limit') {
        $responses[] = new Response(200, ['Content-Length' => '1'], json_encode(['value' => [], 'unused' => str_repeat('x', 1024), '@odata.deltaLink' => graphSyncLink('FolderA', 'oversized')], JSON_THROW_ON_ERROR));
    }
    $responses[] = new Response(200, [], json_encode(['value' => [['id' => 'replayed']], '@odata.deltaLink' => graphSyncLink('FolderA', 'finished')], JSON_THROW_ON_ERROR));
    $mailbox = graphSyncResource($responses, $history);
    $scope = new SyncScope('FolderA');
    $budget = new SyncBudget(
        maxPages: $reason === 'page_limit' ? 1 : 3,
        maxItems: $reason === 'item_limit' ? 1 : 100,
        maxRequests: $reason === 'request_limit' ? 1 : 10,
        maxResponseBytes: $reason === 'response_limit' ? strlen($first) + 64 : 8388608,
    );

    $limited = $mailbox->initialSync($scope, $budget);
    $resumed = $mailbox->initialSync($scope, new SyncBudget, $limited->continuation);

    expect($limited->upserts)->toBe(['retained'])
        ->and($limited->checkpoint)->toBeNull()
        ->and($limited->reason)->toBe($reason)
        ->and($limited->continuation->position['folders'][0]['nextLink'])->toBe($next)
        ->and($resumed->upserts)->toBe(['replayed'])
        ->and($resumed->checkpoint)->not->toBeNull()
        ->and((string) $history[array_key_last($history)]['request']->getUri())->toBe($next);
})->with(['page_limit', 'request_limit', 'item_limit', 'response_limit']);

it('does not trim a Graph provider page and advance past IDs outside its item budget', function (): void {
    $history = [];
    $body = json_encode(['value' => [['id' => 'first'], ['id' => 'second']], '@odata.deltaLink' => graphSyncLink('FolderA', 'complete')], JSON_THROW_ON_ERROR);
    $mailbox = graphSyncResource([new Response(200, [], $body), new Response(200, [], $body)], $history);
    $scope = new SyncScope('FolderA');

    $limited = $mailbox->initialSync($scope, new SyncBudget(maxItems: 1));
    $replayed = $mailbox->initialSync($scope, new SyncBudget(maxItems: 2), $limited->continuation);

    expect($limited->reason)->toBe('provider_item_limit')
        ->and($limited->upserts)->toBe([])
        ->and($limited->checkpoint)->toBeNull()
        ->and($limited->continuation->position['folders'][0]['nextLink'])->toBeNull()
        ->and($replayed->upserts)->toBe(['first', 'second'])
        ->and($replayed->checkpoint)->not->toBeNull();
});

it('retains the previous Graph position when empty or duplicate pages cycle across resumed calls', function (): void {
    $history = [];
    $a = graphSyncLink('FolderA', 'a', true);
    $b = graphSyncLink('FolderA', 'b', true);
    $mailbox = graphSyncResource([
        new Response(200, [], json_encode(['value' => [['id' => 'duplicate'], ['id' => 'duplicate']], '@odata.nextLink' => $a], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['value' => [], '@odata.nextLink' => $b], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['value' => [['id' => 'unretained']], '@odata.nextLink' => $a], JSON_THROW_ON_ERROR)),
    ], $history);
    $scope = new SyncScope('FolderA');

    $first = $mailbox->initialSync($scope, new SyncBudget);
    $empty = $mailbox->initialSync($scope, new SyncBudget, $first->continuation);
    $cycle = $mailbox->initialSync($scope, new SyncBudget, $empty->continuation);

    expect($first->upserts)->toBe(['duplicate'])
        ->and($empty->upserts)->toBe([])
        ->and($empty->checkpoint)->toBeNull()
        ->and($cycle->upserts)->toBe([])
        ->and($cycle->reason)->toBe('provider_progress_repeated')
        ->and($cycle->continuation->position['folders'][0]['nextLink'])->toBe($b)
        ->and($cycle->checkpoint)->toBeNull()
        ->and($history)->toHaveCount(3);
});

it('rediscovers mailbox coverage and resumes existing folders while initializing new folders', function (): void {
    $history = [];
    $old = graphSyncLink('A', 'original-a');
    $a = graphSyncLink('A', 'finished-a');
    $b = graphSyncLink('B', 'finished-b');
    $mailbox = graphSyncResource([
        new Response(200, [], '{"value":[{"id":"A"},{"id":"B"}]}'),
        new Response(200, [], '{"value":[]}'),
        new Response(200, [], '{"value":[]}'),
        new Response(200, [], json_encode(['value' => [['id' => 'moved', '@removed' => ['reason' => 'deleted']]], '@odata.deltaLink' => $a], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['value' => [['id' => 'moved']], '@odata.deltaLink' => $b], JSON_THROW_ON_ERROR)),
    ], $history);
    $scope = new SyncScope;
    $checkpoint = graphSyncCheckpoint($scope, json_encode(['folders' => [
        ['folderId' => 'A', 'deltaLink' => $old],
        ['folderId' => 'no-longer-observed', 'deltaLink' => graphSyncLink('no-longer-observed', 'old')],
    ]], JSON_THROW_ON_ERROR));

    $page = $mailbox->incrementalSync($scope, new SyncBudget(maxPages: 5), $checkpoint);

    expect($page->coveredFolders)->toBe(['A', 'B'])
        ->and($page->upserts)->toBe(['moved'])
        ->and($page->removals)->toBe(['moved'])
        ->and($page->folderChanges)->toBe([
            ['folderId' => 'A', 'upserts' => [], 'removals' => ['moved']],
            ['folderId' => 'B', 'upserts' => ['moved'], 'removals' => []],
        ])
        ->and($page->checkpoint)->not->toBeNull()
        ->and((string) $history[3]['request']->getUri())->toBe($old)
        ->and($history[4]['request']->getUri()->getPath())->toEndWith('/mailFolders/B/messages/delta');
    foreach ($history as $entry) {
        expect($entry['request']->getMethod())->toBe('GET');
    }
});

it('stops Graph delta IO when the host supersedes the retained grant generation', function (): void {
    $history = [];
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'selected-token'],
    ]);
    $mailbox = graphSyncResource([
        function () use ($tokens): Response {
            $tokens->grants['grant']['generation'] = '2';

            return new Response(200, [], json_encode(['value' => [], '@odata.nextLink' => graphSyncLink('FolderA', 'next', true)], JSON_THROW_ON_ERROR));
        },
        new Response(200, [], json_encode(['value' => [], '@odata.deltaLink' => graphSyncLink('FolderA', 'must-not-read')], JSON_THROW_ON_ERROR)),
    ], $history, $tokens);

    expect(fn () => $mailbox->initialSync(new SyncScope('FolderA'), new SyncBudget(maxPages: 2)))->toThrow(GrantAccessDeniedException::class)
        ->and($history)->toHaveCount(1);
});

it('rejects malformed Graph delta evidence instead of completing or inventing removals', function (mixed $values): void {
    $history = [];
    $mailbox = graphSyncResource([new Response(200, [], json_encode(['value' => $values, '@odata.deltaLink' => graphSyncLink('FolderA', 'complete')], JSON_THROW_ON_ERROR))], $history);

    expect(fn () => $mailbox->initialSync(new SyncScope('FolderA'), new SyncBudget))->toThrow(ApiRequestException::class)
        ->and($history)->toHaveCount(1);
})->with([
    'missing collection' => [null],
    'missing ID' => [[[]]],
    'malformed removal' => [[['id' => 'message', '@removed' => null]]],
]);

it('rejects native object collections in Graph folders and delta pages', function (string $value, ?string $folder): void {
    $history = [];
    $body = '{"value":'.$value.',"@odata.deltaLink":'.json_encode(graphSyncLink('FolderA', 'complete'), JSON_THROW_ON_ERROR).'}';
    $mailbox = graphSyncResource([new Response(200, [], $body)], $history);

    expect(fn () => $mailbox->initialSync(new SyncScope($folder), new SyncBudget))->toThrow(ApiRequestException::class)
        ->and($history)->toHaveCount(1);
})->with(['{}', '{"0":{"id":"one"}}'])->with([null, 'FolderA']);

it('does not complete an empty mailbox when folder enumeration exceeds its item budget', function (): void {
    $history = [];
    $root = '{"value":[{"id":"A"},{"id":"B"}]}';
    $mailbox = graphSyncResource([
        new Response(200, [], $root),
        new Response(200, [], $root),
        new Response(200, [], '{"value":[]}'),
        new Response(200, [], '{"value":[]}'),
        new Response(200, [], json_encode(['value' => [], '@odata.deltaLink' => graphSyncLink('A', 'done')], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['value' => [], '@odata.deltaLink' => graphSyncLink('B', 'done')], JSON_THROW_ON_ERROR)),
    ], $history);
    $scope = new SyncScope;

    $limited = $mailbox->initialSync($scope, new SyncBudget(maxItems: 1));
    $replayed = $mailbox->initialSync($scope, new SyncBudget(maxPages: 5), $limited->continuation);

    expect($limited->reason)->toBe('provider_item_limit')
        ->and($limited->coveredFolders)->toBe([])
        ->and($limited->checkpoint)->toBeNull()
        ->and($limited->continuation->position['enumeration'][0]['nextLink'])->toBeNull()
        ->and($replayed->coveredFolders)->toBe(['A', 'B'])
        ->and($replayed->checkpoint)->not->toBeNull()
        ->and($history[0]['request']->getUri()->getPath())->toBe($history[1]['request']->getUri()->getPath());
});

it('rejects Graph continuation destinations outside the exact mailbox folder and delta operation', function (string $folder, string $link): void {
    $history = [];
    $mailbox = graphSyncResource([new Response(200, [], json_encode(['value' => [], '@odata.nextLink' => $link], JSON_THROW_ON_ERROR))], $history);

    expect(fn () => $mailbox->initialSync(new SyncScope($folder), new SyncBudget(maxPages: 2)))->toThrow(ApiRequestException::class)
        ->and($history)->toHaveCount(1);
})->with([
    'opaque folder casing' => ['FolderA', graphSyncLink('foldera', 'secret', true)],
    'numeric-looking folder' => ['01', graphSyncLink('1', 'secret', true)],
    'unknown mailbox alias' => ['FolderA', "https://graph.microsoft.com/v1.0/users/me/mailfolders('FolderA')/messages/delta?\$skiptoken=secret"],
    'other mailbox' => ['FolderA', 'https://graph.microsoft.com/v1.0/users/other%40example.com/mailFolders/FolderA/messages/delta?$skiptoken=secret'],
    'other operation' => ['FolderA', 'https://graph.microsoft.com/v1.0/users/same%40example.com/mailFolders/FolderA/messages?$skiptoken=secret'],
    'other origin' => ['FolderA', 'https://evil.example/v1.0/users/same%40example.com/mailFolders/FolderA/messages/delta?$skiptoken=secret'],
]);

/**
 * @param  list<mixed>  $responses
 * @param  array<int, mixed>  $history
 * @param  array<string, mixed>  $httpOptions
 */
function graphSyncResource(array $responses, array &$history, ?TestGrantTokenProvider $tokens = null, array $httpOptions = []): SupportsInitialSync&SupportsIncrementalSync
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $tokens ??= new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'selected-token'],
    ]);
    $resource = Mailbox::forGrant(new GrantContext('connection', 'grant', '1', 'ms-graph', 'same@example.com'), $tokens, new Client(['handler' => $stack, ...$httpOptions]));
    expect($resource)->toBeInstanceOf(SupportsInitialSync::class)->toBeInstanceOf(SupportsIncrementalSync::class);

    return $resource;
}

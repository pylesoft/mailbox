<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\SupportsFolderCatalogue;
use Pyle\Mailbox\DTOs\FolderCatalogue;
use Pyle\Mailbox\DTOs\FolderDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncContinuation;
use Pyle\Mailbox\DTOs\SyncIdentity;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Enums\Importance;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\InvalidSyncStateException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('resumes a bounded folder catalogue independently of message delta with canonical aliases and parent metadata', function (): void {
    Http::preventStrayRequests();
    $history = [];
    $aliases = [WellKnownFolder::DRAFTS];
    $mailbox = catalogueMailbox([
        ['id' => 'canonical-drafts'],
        ['value' => [
            catalogueFolder('canonical-drafts', 'Brouillons', 'root', children: 1),
            catalogueFolder('custom', 'Drafts', 'root'),
        ]],
        ['value' => [catalogueFolder('nested', 'Child', 'canonical-drafts', hidden: true)]],
    ], $history);

    $first = $mailbox->folderCatalogue(new SyncBudget(maxRequests: 1), wellKnownFolders: $aliases);

    expect($mailbox)->toBeInstanceOf(SupportsFolderCatalogue::class);
    expect($first->complete)->toBeFalse();
    expect($first->reason)->toBe('request_limit');
    expect($first->folders)->toBe([]);
    expect($first->wellKnownFolderIds)->toBe(['drafts' => 'canonical-drafts']);
    expect($history)->toHaveCount(1);
    expect($history[0]['request']->getUri()->getPath())->toEndWith('/mailFolders/drafts');

    $second = $mailbox->folderCatalogue(new SyncBudget(maxPages: 1), unserialize(serialize($first->continuation)), $aliases);

    expect($second->complete)->toBeFalse();
    expect($second->reason)->toBe('page_limit');
    expect(array_column($second->folders, 'id'))->toBe(['canonical-drafts', 'custom']);
    expect($second->folders[0]->wellKnownName)->toBe(WellKnownFolder::DRAFTS);
    expect($second->folders[1]->wellKnownName)->toBeNull();
    expect($second->folders[1]->isHidden)->toBeFalse();
    expect($second->folders[0]->parentFolderId)->toBe('root');

    $last = $mailbox->folderCatalogue(new SyncBudget, unserialize(serialize($second->continuation)), $aliases);

    expect($last->complete)->toBeTrue();
    expect($last->continuation)->toBeNull();
    expect(array_column($last->folders, 'id'))->toBe(['nested']);
    expect($last->folders[0]->parentFolderId)->toBe('canonical-drafts');
    expect($last->folders[0]->isHidden)->toBeTrue();
    expect($last->wellKnownFolderIds)->toBe(['drafts' => 'canonical-drafts']);
    expect($history)->toHaveCount(3);
    foreach ($history as $entry) {
        expect($entry['request']->getMethod())->toBe('GET');
        expect($entry['request']->getUri()->getPath())->not->toContain('/messages');
    }
});

it('retains explicit root observations across catalogue restarts without guessing an unknown parent', function (): void {
    Http::preventStrayRequests();
    $history = [];
    $first = catalogueMailbox([
        ['value' => [catalogueFolder('A', 'Projects', 'unlisted-mailbox-root', children: 1)]],
    ], $history)->folderCatalogue(new SyncBudget(maxRequests: 1));

    expect($first->complete)->toBeFalse();
    expect($first->rootFolderIds)->toBe(['A']);
    expect($first->folders[0]->parentFolderId)->toBe('unlisted-mailbox-root');

    $resumedHistory = [];
    $last = catalogueMailbox([
        ['value' => [catalogueFolder('B', 'Nested', 'A')]],
    ], $resumedHistory)->folderCatalogue(new SyncBudget, unserialize(serialize($first->continuation)));

    expect($last->complete)->toBeTrue();
    expect($last->rootFolderIds)->toBe(['A']);
    expect($last->folders[0]->parentFolderId)->toBe('A');
    expect($history)->toHaveCount(1);
    expect($resumedHistory)->toHaveCount(1);
});

it('pages roots and children past ten levels while preserving hidden and derived provider facts', function (): void {
    Http::preventStrayRequests();
    $history = [];
    $rootNext = 'https://graph.microsoft.com/v1.0/users/reader%40example.com/mailFolders?$skiptoken=roots%2bb%2Fc%3D&repeat=one&repeat=two';
    $childNext = 'https://graph.microsoft.com/v1.0/users/reader%40example.com/mailFolders/A/childFolders?$skiptoken=children%2Fnext%3D';
    $responses = [
        ['value' => [catalogueFolder('A', 'Top', 'root', children: 1)], '@odata.nextLink' => $rootNext],
        ['value' => [
            [...catalogueFolder('search', 'Search', 'root'), '@odata.type' => '#microsoft.graph.mailSearchFolder'],
            [...catalogueFolder('unknown', 'Unknown', 'root', hidden: true), '@odata.type' => '#vendor.unrecognizedFolder'],
        ]],
        ['value' => [catalogueFolder('depth-2', 'Nested', 'A', children: 1)], '@odata.nextLink' => $childNext],
        ['value' => [catalogueFolder('sibling', 'Sibling', 'A')]],
    ];
    for ($depth = 3; $depth <= 12; $depth++) {
        $responses[] = ['value' => [catalogueFolder('depth-'.$depth, 'Nested', 'depth-'.($depth - 1), children: $depth < 12 ? 1 : 0)]];
    }
    $mailbox = catalogueMailbox($responses, $history);
    $continuation = null;
    $folders = [];

    for ($call = 0; $call < 20; $call++) {
        $read = $mailbox->folderCatalogue(new SyncBudget(maxRequests: 1, maxItems: 3), $continuation);
        array_push($folders, ...$read->folders);
        if ($read->complete) {
            break;
        }
        $continuation = unserialize(serialize($read->continuation));
    }

    expect($read->complete)->toBeTrue();
    expect(array_column($folders, 'id'))->toBe(['A', 'search', 'unknown', 'depth-2', 'sibling', 'depth-3', 'depth-4', 'depth-5', 'depth-6', 'depth-7', 'depth-8', 'depth-9', 'depth-10', 'depth-11', 'depth-12']);
    expect($read->rootFolderIds)->toBe(['A', 'search', 'unknown']);
    expect($folders[0]->providerType)->toBe('#microsoft.graph.mailFolder');
    expect($folders[1]->providerType)->toBe('#microsoft.graph.mailSearchFolder');
    expect($folders[1]->isHidden)->toBeFalse();
    expect($folders[2]->providerType)->toBe('#vendor.unrecognizedFolder');
    expect($folders[2]->isHidden)->toBeTrue();
    expect($folders[14]->parentFolderId)->toBe('depth-11');
    expect($history)->toHaveCount(14);
    expect((string) $history[1]['request']->getUri())->toBe($rootNext);
    expect((string) $history[3]['request']->getUri())->toBe($childNext);
    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    expect($query['includeHiddenFolders'])->toBe('true');
    expect(explode(',', $query['$select']))->toContain('parentFolderId', 'isHidden', 'childFolderCount');
    expect($history[0]['request']->getHeaderLine('Accept'))->toBe('application/json');
});

it('rejects unfinished canonical state with a missing hierarchy queue before provider I/O', function (): void {
    Http::preventStrayRequests();
    $history = [];
    $aliases = [WellKnownFolder::DRAFTS, WellKnownFolder::JUNK];
    $first = catalogueMailbox([['id' => 'draft-id']], $history)
        ->folderCatalogue(new SyncBudget(maxRequests: 1), wellKnownFolders: $aliases);
    $position = $first->continuation->position;
    $position['enumeration'] = [];
    $invalid = new SyncContinuation($first->continuation->identity, $first->continuation->phase, $position);
    $resumedHistory = [];
    $mailbox = catalogueMailbox([['id' => 'junk-id']], $resumedHistory);

    expect(fn () => $mailbox->folderCatalogue(new SyncBudget(maxPages: 3), $invalid, $aliases))
        ->toThrow(InvalidSyncStateException::class);
    expect($resumedHistory)->toBe([]);
});

it('keeps catalogue recovery URLs private while trusted serialization resumes the exact provider link', function (): void {
    Http::preventStrayRequests();
    $history = [];
    $next = 'https://graph.microsoft.com/v1.0/users/reader%40example.com/mailFolders?$skiptoken=private-catalogue-secret%2F%3D&repeat=one&repeat=two';
    $first = catalogueMailbox([['value' => [], '@odata.nextLink' => $next]], $history)
        ->folderCatalogue(new SyncBudget(maxRequests: 1));

    expect($first->complete)->toBeFalse();
    expect(json_encode($first, JSON_THROW_ON_ERROR))->not->toContain('private-catalogue-secret');
    expect(print_r($first, true))->not->toContain('private-catalogue-secret');
    expect(serialize($first->continuation))->toContain('private-catalogue-secret');

    $resumedHistory = [];
    $last = catalogueMailbox([['value' => []]], $resumedHistory)
        ->folderCatalogue(new SyncBudget, unserialize(serialize($first->continuation)));

    expect($last->complete)->toBeTrue();
    expect((string) $resumedHistory[0]['request']->getUri())->toBe($next);
    expect($resumedHistory)->toHaveCount(1);
});

it('charges canonical lookups to the item page and request budgets and resumes the remaining aliases', function (SyncBudget $budget, string $reason): void {
    Http::preventStrayRequests();
    $history = [];
    $aliases = [WellKnownFolder::DRAFTS, WellKnownFolder::DELETED, WellKnownFolder::JUNK];
    $mailbox = catalogueMailbox([
        ['id' => 'draft-id'], ['id' => 'deleted-id'], ['id' => 'junk-id'], ['value' => []],
    ], $history);

    $first = $mailbox->folderCatalogue($budget, wellKnownFolders: $aliases);

    expect($first->complete)->toBeFalse();
    expect($first->reason)->toBe($reason);
    expect($first->wellKnownFolderIds)->toBe(['drafts' => 'draft-id']);
    expect($first->folders)->toBe([]);
    expect($history)->toHaveCount(1);

    $last = $mailbox->folderCatalogue(new SyncBudget(maxPages: 4, maxRequests: 4), unserialize(serialize($first->continuation)), $aliases);

    expect($last->complete)->toBeTrue();
    expect($last->wellKnownFolderIds)->toBe(['drafts' => 'draft-id', 'deleted' => 'deleted-id', 'junk' => 'junk-id']);
    expect($history)->toHaveCount(4);
    expect($history[1]['request']->getUri()->getPath())->toEndWith('/mailFolders/deleteditems');
    expect($history[2]['request']->getUri()->getPath())->toEndWith('/mailFolders/junkemail');
})->with([
    'item cap' => [new SyncBudget(maxPages: 4, maxItems: 1), 'item_limit'],
    'page cap' => [new SyncBudget(maxPages: 1, maxRequests: 4), 'page_limit'],
    'request cap' => [new SyncBudget(maxPages: 4, maxRequests: 1), 'request_limit'],
]);

it('bounds aggregate actual response bytes across canonical lookups and root pages without skipping the pending page', function (): void {
    Http::preventStrayRequests();
    $history = [];
    $alias = ['id' => 'draft-id'];
    $roots = ['value' => []];
    $bytes = strlen(json_encode($alias, JSON_THROW_ON_ERROR)) + strlen(json_encode($roots, JSON_THROW_ON_ERROR)) - 1;
    $budget = new SyncBudget(maxPages: 3, maxResponseBytes: $bytes);
    $aliases = [WellKnownFolder::DRAFTS];
    $mailbox = catalogueMailbox([$alias, $roots, $roots], $history);

    $first = $mailbox->folderCatalogue($budget, wellKnownFolders: $aliases);

    expect($first->complete)->toBeFalse();
    expect($first->reason)->toBe('response_limit');
    expect($first->wellKnownFolderIds)->toBe(['drafts' => 'draft-id']);
    expect($first->folders)->toBe([]);
    expect($history)->toHaveCount(2);

    $last = $mailbox->folderCatalogue($budget, unserialize(serialize($first->continuation)), $aliases);

    expect($last->complete)->toBeTrue();
    expect($history)->toHaveCount(3);
    expect($history[1]['request']->getUri()->getPath())->toBe($history[2]['request']->getUri()->getPath());
    parse_str($history[1]['request']->getUri()->getQuery(), $firstQuery);
    parse_str($history[2]['request']->getUri()->getQuery(), $resumedQuery);
    expect($firstQuery['$top'])->toBe('99');
    expect($resumedQuery['$top'])->toBe('100');
});

it('replays an over-budget folder page in full instead of advancing past silently truncated metadata', function (): void {
    Http::preventStrayRequests();
    $history = [];
    $page = ['value' => [catalogueFolder('one', 'One', 'root'), catalogueFolder('two', 'Two', 'root')]];
    $mailbox = catalogueMailbox([$page, $page], $history);

    $first = $mailbox->folderCatalogue(new SyncBudget(maxItems: 1));

    expect($first->complete)->toBeFalse();
    expect($first->reason)->toBe('provider_item_limit');
    expect($first->folders)->toBe([]);
    expect($first->rootFolderIds)->toBe([]);
    expect($history)->toHaveCount(1);

    $last = $mailbox->folderCatalogue(new SyncBudget(maxItems: 2), unserialize(serialize($first->continuation)));

    expect($last->complete)->toBeTrue();
    expect(array_column($last->folders, 'id'))->toBe(['one', 'two']);
    expect($last->rootFolderIds)->toBe(['one', 'two']);
    expect($history)->toHaveCount(2);
    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    expect($query['$top'])->toBe('1');
});

it('keeps malformed native folder catalogue metadata unfinished', function (array|Response $response, string $reason): void {
    Http::preventStrayRequests();
    $history = [];
    $mailbox = catalogueMailbox([$response, ['value' => []]], $history);

    $first = $mailbox->folderCatalogue(new SyncBudget);

    expect($first->complete)->toBeFalse();
    expect($first->reason)->toBe($reason);
    expect($first->folders)->toBe([]);
    expect($first->continuation)->not->toBeNull();
    expect($history)->toHaveCount(1);

    $last = $mailbox->folderCatalogue(new SyncBudget, unserialize(serialize($first->continuation)));

    expect($last->complete)->toBeTrue();
    expect($history)->toHaveCount(2);
})->with([
    'missing collection' => [['other' => 1], 'content_incomplete'],
    'native collection object' => [['value' => new stdClass], 'content_incomplete'],
    'native entry list' => [['value' => [[]]], 'content_incomplete'],
    'null entry' => [['value' => [null]], 'content_incomplete'],
    'numeric ID' => [['value' => [[...catalogueFolder('A', 'A', 'root'), 'id' => 1]]], 'content_incomplete'],
    'missing display name' => [['value' => [['id' => 'A', 'parentFolderId' => 'root']]], 'content_incomplete'],
    'missing parent' => [['value' => [['id' => 'A', 'displayName' => 'A']]], 'content_incomplete'],
    'unknown parent' => [['value' => [[...catalogueFolder('A', 'A', 'root'), 'parentFolderId' => null]]], 'content_incomplete'],
    'self parent' => [['value' => [catalogueFolder('A', 'A', 'A')]], 'content_incomplete'],
    'string count' => [['value' => [[...catalogueFolder('A', 'A', 'root'), 'childFolderCount' => '1']]], 'content_incomplete'],
    'negative count' => [['value' => [catalogueFolder('A', 'A', 'root', children: -1)]], 'content_incomplete'],
    'unknown hidden fact' => [['value' => [[...catalogueFolder('A', 'A', 'root'), 'isHidden' => null]]], 'content_incomplete'],
    'string hidden fact' => [['value' => [[...catalogueFolder('A', 'A', 'root'), 'isHidden' => 'false']]], 'content_incomplete'],
    'native type object' => [['value' => [[...catalogueFolder('A', 'A', 'root'), '@odata.type' => new stdClass]]], 'content_incomplete'],
    'truncated native JSON' => [new Response(200, [], '{"value":['), 'provider_interrupted'],
    'foreign origin next link' => [['value' => [], '@odata.nextLink' => 'https://evil.example/mailFolders?$skiptoken=private-token'], 'provider_interrupted'],
    'other mailbox next link' => [['value' => [], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/other%40example.com/mailFolders?$skiptoken=private-token'], 'provider_interrupted'],
    'other resource next link' => [['value' => [], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/reader%40example.com/messages?$skiptoken=private-token'], 'provider_interrupted'],
    'missing next link value' => [['value' => [], '@odata.nextLink' => null], 'provider_interrupted'],
    'empty next link value' => [['value' => [], '@odata.nextLink' => ''], 'provider_interrupted'],
    'provider interruption' => [new Response(503, [], '{"error":{"code":"Unavailable","message":"private-provider-secret"}}'), 'provider_interrupted'],
    'unavailable scope' => [new Response(404, [], '{"error":{"code":"NotFound","message":"private-provider-secret"}}'), 'scope_unavailable'],
]);

it('keeps malformed or unavailable canonical folder identity unfinished until the same alias is resolved', function (array|Response $response, string $reason): void {
    Http::preventStrayRequests();
    $history = [];
    $mailbox = catalogueMailbox([$response, ['id' => 'canonical-drafts'], ['value' => []]], $history);
    $aliases = [WellKnownFolder::DRAFTS];

    $first = $mailbox->folderCatalogue(new SyncBudget(maxPages: 3), wellKnownFolders: $aliases);

    expect($first->complete)->toBeFalse();
    expect($first->reason)->toBe($reason);
    expect($first->wellKnownFolderIds)->toBe([]);
    expect($first->folders)->toBe([]);
    expect($history)->toHaveCount(1);

    $last = $mailbox->folderCatalogue(new SyncBudget(maxPages: 3), unserialize(serialize($first->continuation)), $aliases);

    expect($last->complete)->toBeTrue();
    expect($last->wellKnownFolderIds)->toBe(['drafts' => 'canonical-drafts']);
    expect($history)->toHaveCount(3);
    expect($history[1]['request']->getUri()->getPath())->toEndWith('/mailFolders/drafts');
})->with([
    'missing canonical ID' => [['other' => 1], 'content_incomplete'],
    'null canonical ID' => [['id' => null], 'content_incomplete'],
    'blank canonical ID' => [['id' => ' '], 'content_incomplete'],
    'native canonical ID object' => [['id' => new stdClass], 'content_incomplete'],
    'native canonical array response' => [new Response(200, [], '[]'), 'provider_interrupted'],
    'unavailable canonical folder' => [new Response(404, [], '{"error":{"code":"NotFound"}}'), 'scope_unavailable'],
]);

it('does not complete a child catalogue page with unknown or moving ancestry', function (): void {
    Http::preventStrayRequests();
    $history = [];
    $mailbox = catalogueMailbox([
        ['value' => [catalogueFolder('A', 'A', 'root', children: 1)]],
        ['value' => [catalogueFolder('B', 'B', 'different-parent')]],
        ['value' => [catalogueFolder('B', 'B', 'A')]],
    ], $history);
    $first = $mailbox->folderCatalogue(new SyncBudget(maxRequests: 1));

    $interrupted = $mailbox->folderCatalogue(new SyncBudget, unserialize(serialize($first->continuation)));

    expect($interrupted->complete)->toBeFalse();
    expect($interrupted->reason)->toBe('content_incomplete');
    expect($interrupted->folders)->toBe([]);
    expect($interrupted->rootFolderIds)->toBe(['A']);

    $last = $mailbox->folderCatalogue(new SyncBudget, unserialize(serialize($interrupted->continuation)));

    expect($last->complete)->toBeTrue();
    expect(array_column($last->folders, 'id'))->toBe(['B']);
    expect($last->folders[0]->parentFolderId)->toBe('A');
    expect($history)->toHaveCount(3);
});

it('does not mistake empty duplicate or repeated catalogue pages for completion', function (): void {
    Http::preventStrayRequests();
    $history = [];
    $next = 'https://graph.microsoft.com/v1.0/users/reader%40example.com/mailFolders?$skiptoken=repeated';
    $mailbox = catalogueMailbox([
        ['value' => [catalogueFolder('A', 'A', 'root')], '@odata.nextLink' => $next],
        ['value' => [catalogueFolder('A', 'A', 'root')], '@odata.nextLink' => $next],
        ['value' => []],
    ], $history);

    $first = $mailbox->folderCatalogue(new SyncBudget(maxPages: 3));

    expect($first->complete)->toBeFalse();
    expect($first->reason)->toBe('provider_progress_repeated');
    expect(array_column($first->folders, 'id'))->toBe(['A']);
    expect($history)->toHaveCount(2);

    $last = $mailbox->folderCatalogue(new SyncBudget, unserialize(serialize($first->continuation)));

    expect($last->complete)->toBeTrue();
    expect($last->folders)->toBe([]);
    expect($last->rootFolderIds)->toBe(['A']);
    expect((string) $history[2]['request']->getUri())->toBe($next);
});

it('rejects catalogue continuations outside the exact captured identity or alias selection before token access', function (string $mutation): void {
    Http::preventStrayRequests();
    $history = [];
    $context = new GrantContext('connection', 'grant', '1', 'ms-graph', 'reader@example.com');
    $tokens = new TestGrantTokenProvider(['grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['reader@example.com'], 'token' => 'selected-token']]);
    $next = 'https://graph.microsoft.com/v1.0/users/reader%40example.com/mailFolders?$skiptoken=private-state';
    $mailbox = catalogueMailbox([['value' => [], '@odata.nextLink' => $next]], $history, $context, $tokens);
    $first = $mailbox->folderCatalogue(new SyncBudget);
    $identity = new SyncIdentity($mutation === 'provider' ? 'gmail' : 'ms-graph', new GrantContext(
        $mutation === 'connection' ? 'other' : 'connection', $mutation === 'grant' ? 'other' : 'grant',
        $mutation === 'generation' ? '2' : '1', 'ms-graph', $mutation === 'mailbox' ? 'other@example.com' : 'reader@example.com',
    ), new SyncScope($mutation === 'folder scope' ? 'other' : null), $mutation === 'format' ? 2 : 1);
    $position = $first->continuation->position;
    if ($mutation === 'cross-folder link') {
        $position['enumeration'][0]['nextLink'] = 'https://graph.microsoft.com/v1.0/users/reader%40example.com/mailFolders/other/childFolders?$skiptoken=private-state';
    }
    $invalid = new SyncContinuation($identity, $mutation === 'phase' ? 'ms-graph.delta' : $first->continuation->phase, $position);
    $aliases = $mutation === 'aliases' ? [WellKnownFolder::DRAFTS] : [];

    expect(fn () => $mailbox->folderCatalogue(new SyncBudget, $invalid, $aliases))->toThrow(InvalidSyncStateException::class);
    expect($tokens->tokenReads)->toBe(1);
    expect($history)->toHaveCount(1);
})->with(['connection', 'grant', 'generation', 'mailbox', 'provider', 'format', 'folder scope', 'phase', 'aliases', 'cross-folder link']);

it('validates a distinct enum list before catalogue provider I/O', function (array $aliases): void {
    Http::preventStrayRequests();
    $history = [];
    $mailbox = catalogueMailbox([], $history);

    expect(fn () => $mailbox->folderCatalogue(new SyncBudget, wellKnownFolders: $aliases))->toThrow(InvalidArgumentException::class);
    expect($history)->toBe([]);
})->with([
    'string alias' => [['drafts']],
    'duplicate alias' => [[WellKnownFolder::DRAFTS, WellKnownFolder::DRAFTS]],
    'keyed aliases' => [['drafts' => WellKnownFolder::DRAFTS]],
]);

it('refuses stale captured catalogue authority without reading a token or using configured fallback', function (): void {
    Http::preventStrayRequests();
    $history = [];
    $tokens = new TestGrantTokenProvider(['grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['reader@example.com'], 'token' => 'selected-token']]);
    $mailbox = catalogueMailbox([], $history, tokens: $tokens);
    $tokens->grants['grant']['generation'] = '2';

    expect(fn () => $mailbox->folderCatalogue(new SyncBudget))->toThrow(GrantAccessDeniedException::class);
    expect($tokens->tokenReads)->toBe(0);
    expect($history)->toBe([]);
});

it('preserves opaque numeric-looking and long folder identities in metadata and child request paths', function (): void {
    Http::preventStrayRequests();
    $history = [];
    $opaque = '01/A+b=c/'.str_repeat('x', 512);
    $mailbox = catalogueMailbox([
        ['value' => [catalogueFolder($opaque, 'Opaque', 'root', children: 1), catalogueFolder('01', 'Leading zero', 'root'), catalogueFolder('1', 'Numeric', 'root')]],
        ['value' => [catalogueFolder('child', 'Child', $opaque)]],
    ], $history);

    $first = $mailbox->folderCatalogue(new SyncBudget(maxRequests: 1));
    $last = $mailbox->folderCatalogue(new SyncBudget, unserialize(serialize($first->continuation)));

    expect(array_column($first->folders, 'id'))->toBe([$opaque, '01', '1']);
    expect($last->complete)->toBeTrue();
    expect($last->folders[0]->parentFolderId)->toBe($opaque);
    expect($last->rootFolderIds)->toBe([$opaque, '01', '1']);
    expect($history[1]['request']->getUri()->getPath())->toEndWith('/mailFolders/'.rawurlencode($opaque).'/childFolders');
});

it('keeps conflicting root ancestry unfinished and safely resumes the same pending root page', function (): void {
    Http::preventStrayRequests();
    $history = [];
    $next = 'https://graph.microsoft.com/v1.0/users/reader%40example.com/mailFolders?$skiptoken=moving';
    $mailbox = catalogueMailbox([
        ['value' => [catalogueFolder('A', 'A', 'root')], '@odata.nextLink' => $next],
        ['value' => [catalogueFolder('A', 'A', 'different-parent')]],
        ['value' => [catalogueFolder('A', 'Renamed', 'root'), catalogueFolder('B', 'B', 'root')]],
    ], $history);

    $first = $mailbox->folderCatalogue(new SyncBudget(maxPages: 3));

    expect($first->complete)->toBeFalse();
    expect($first->reason)->toBe('content_incomplete');
    expect(array_column($first->folders, 'id'))->toBe(['A']);
    expect($first->rootFolderIds)->toBe(['A']);

    $last = $mailbox->folderCatalogue(new SyncBudget, unserialize(serialize($first->continuation)));

    expect($last->complete)->toBeTrue();
    expect(array_column($last->folders, 'id'))->toBe(['B']);
    expect((string) $history[2]['request']->getUri())->toBe($next);
});

it('redacts catalogue recovery state from rejected operation and result exception traces', function (string $boundary): void {
    Http::preventStrayRequests();
    $history = [];
    $next = 'https://graph.microsoft.com/v1.0/users/reader%40example.com/mailFolders?$skiptoken=private-exception-secret';
    $mailbox = catalogueMailbox([['value' => [], '@odata.nextLink' => $next]], $history);
    $first = $mailbox->folderCatalogue(new SyncBudget);
    $previous = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');

    try {
        try {
            if ($boundary === 'result') {
                new FolderCatalogue($first->identity, complete: true, continuation: $first->continuation);
            } else {
                $position = $first->continuation->position;
                $position['enumeration'][0]['nextLink'] = 'https://evil.example/mailFolders?$skiptoken=private-exception-secret';
                $invalid = new SyncContinuation($first->identity, $first->continuation->phase, $position);
                $mailbox->folderCatalogue(new SyncBudget, $invalid);
            }
            $this->fail('Invalid catalogue recovery state was accepted.');
        } catch (InvalidArgumentException|InvalidSyncStateException $exception) {
            $frames = array_values(array_filter($exception->getTrace(), static fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'Pyle\\Mailbox\\')));
            expect(json_encode($frames, JSON_PARTIAL_OUTPUT_ON_ERROR))->not->toContain('private-exception-secret');
            expect($exception->getMessage())->not->toContain('private-exception-secret');
        }
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
})->with(['operation', 'result']);

it('preserves legacy positional DTO constructors and folder helper metadata with compatible trailing defaults', function (): void {
    $message = new MessageDto('message', 'Subject', null, null, null, null, [], [], [], null, null, false, false, false, Importance::NORMAL, null, null, null);
    $folder = new FolderDto('folder', 'Folder', 'root', 0, 0, 0, null, null);
    $facts = new FolderDto('private', 'Private', 'root', 0, 0, 0, null, null, isHidden: true, providerType: '#vendor.unknown');
    $copied = $facts->withPath('Folder/Private')->withChildren([$folder]);

    expect($message->replyTo)->toBe([]);
    expect($message->headers)->toBe([]);
    expect($message->raw)->toBe([]);
    expect($message->toArray())->toHaveKeys(['id', 'subject', 'from', 'sender', 'toRecipients', 'ccRecipients', 'bccRecipients', 'body']);
    expect($folder->isHidden)->toBeNull();
    expect($folder->providerType)->toBeNull();
    expect($folder->children)->toBe([]);
    expect($copied->isHidden)->toBeTrue();
    expect($copied->providerType)->toBe('#vendor.unknown');
    expect($copied->path)->toBe('Folder/Private');
    expect($copied->children)->toBe([$folder]);
    expect($copied->toArray()['providerType'])->toBe('#vendor.unknown');
});

/** @return array<string, mixed> */
function catalogueFolder(string $id, string $name, string $parent, int $children = 0, bool $hidden = false): array
{
    return ['id' => $id, 'displayName' => $name, 'parentFolderId' => $parent, 'childFolderCount' => $children,
        'totalItemCount' => 0, 'unreadItemCount' => 0, 'isHidden' => $hidden];
}

/**
 * @param  list<array<string, mixed>|Response|Throwable>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function catalogueMailbox(array $responses, array &$history, ?GrantContext $context = null, ?TestGrantTokenProvider $tokens = null): MailboxResource
{
    $stack = HandlerStack::create(new MockHandler(array_map(
        static fn (array|Response|Throwable $response): Response|Throwable => is_array($response)
            ? new Response(200, [], json_encode($response, JSON_THROW_ON_ERROR))
            : $response,
        $responses,
    )));
    $stack->push(Middleware::history($history));
    $context ??= new GrantContext('connection', 'grant', '1', 'ms-graph', 'reader@example.com');
    $tokens ??= new TestGrantTokenProvider([
        $context->grantId => ['connection' => $context->connectionId, 'generation' => $context->lifecycleGeneration,
            'mailboxes' => [$context->mailbox], 'token' => 'selected-token'],
    ]);

    return Mailbox::forGrant($context, $tokens, new Client(['handler' => $stack, 'base_uri' => 'https://graph.microsoft.com/v1.0/']));
}

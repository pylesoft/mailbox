<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Pyle\Mailbox\Drivers\MsGraph\BatchRequest;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphDeltaSync;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphMailboxResource;
use Pyle\Mailbox\Drivers\MsGraph\RateLimiter;
use Pyle\Mailbox\Drivers\MsGraph\TokenManager;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\ProviderTransportException;

/** @param array<int, mixed> $responses
 * @param  array<int, mixed>  $history
 */
function continuationGraphClient(array $responses, array &$history): GraphClient
{
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::history($history));
    $tokenClient = new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, [], '{"access_token":"private-bearer","expires_in":3600}'),
    ]))]);
    $tokenManager = new TokenManager([
        'tenant_id' => 'test-tenant',
        'client_id' => 'test-client',
        'client_secret' => 'test-secret',
    ], $tokenClient);

    return new GraphClient(['max_retries' => 1, 'queue_retry_strategy' => 'sleep'], $tokenManager, new RateLimiter([]), new Client([
        'handler' => $handler,
        'base_uri' => 'https://graph.microsoft.com/v1.0/',
    ]));
}

it('preserves the original mailbox scoped batch GET URL', function (bool $absolute): void {
    $history = [];
    $graph = continuationGraphClient([new Response(200, [], '{"value":[]}')], $history);
    $url = ($absolute ? 'https://graph.microsoft.com/v1.0/' : '').'users/team%40example.com/messages?$skiptoken=a%2fb&opaque=one&opaque=two';

    $result = (new BatchRequest($graph, 'team@example.com'))->send([['id' => 'one', 'method' => 'GET', 'url' => $url]]);

    expect($result['responses'][0]['status'])->toBe(200)
        ->and($history)->toHaveCount(1)
        ->and((string) $history[0]['request']->getUri())->toBe($absolute ? $url : 'https://graph.microsoft.com/v1.0/'.$url);
})->with([false, true]);

it('rejects an untrusted mailbox scoped batch GET origin before dispatch', function (): void {
    $history = [];
    $graph = continuationGraphClient([new Response(200, [], '{"value":[]}')], $history);

    expect(fn () => (new BatchRequest($graph, 'team@example.com'))->send([
        ['id' => 'one', 'method' => 'GET', 'url' => 'https://untrusted.example/users/team%40example.com/messages'],
    ]))->toThrow(ApiRequestException::class)
        ->and($history)->toBe([]);
});

it('preserves opaque next and delta queries through real graph requests', function (): void {
    $path = 'https://graph.microsoft.com/v1.0/users/team%40example.com/mailFolders/inbox/messages/delta';
    $next = $path.'?$skiptoken=a%2bb%2Fc%3D&repeat=one&repeat=two&literal=a+b';
    $delta = $path.'?$deltatoken=private%2Fdelta%3D&repeat=three&repeat=four';
    $history = [];
    $graph = continuationGraphClient([
        new Response(200, [], json_encode(['value' => [['id' => 'first']], '@odata.nextLink' => $next])),
        new Response(200, [], json_encode(['value' => [['id' => 'second']], '@odata.deltaLink' => $delta])),
        new Response(200, [], json_encode(['value' => [], '@odata.deltaLink' => $delta])),
    ], $history);

    $sync = new MsGraphDeltaSync($graph);
    $result = $sync->syncFolder('team@example.com', 'inbox');
    $sync->syncFolder('team@example.com', 'inbox', $result->deltaLink);

    expect($result->created->pluck('id')->all())->toBe(['first', 'second'])
        ->and(array_map(fn (array $entry): string => (string) $entry['request']->getUri(), $history))->toBe([$path, $next, $delta]);
    foreach ($history as $entry) {
        expect($entry['request']->getHeaderLine('Authorization'))->toBe('Bearer private-bearer');
    }
});

it('rejects authenticated destinations outside the provider and selected mailbox before HTTP', function (string $endpoint): void {
    $history = [];
    $graph = continuationGraphClient([new Response(200, [], '{"value":[]}')], $history);

    expect(fn () => $graph->get($endpoint, mailbox: 'team@example.com'))->toThrow(ApiRequestException::class);
    expect($history)->toBe([]);
})->with([
    'foreign origin' => ['https://evil.example/v1.0/users/team%40example.com/messages?$skiptoken=secret'],
    'foreign scheme' => ['http://graph.microsoft.com/v1.0/users/team%40example.com/messages'],
    'foreign port' => ['https://graph.microsoft.com:444/v1.0/users/team%40example.com/messages'],
    'foreign mailbox' => ['https://graph.microsoft.com/v1.0/users/other%40example.com/messages'],
    'foreign resource' => ['https://graph.microsoft.com/v1.0/groups/team/messages'],
    'userinfo' => ['https://private-bearer@graph.microsoft.com/v1.0/users/team%40example.com/messages'],
    'scheme relative' => ['//evil.example/v1.0/users/team%40example.com/messages'],
    'path traversal' => ['users/team%40example.com/../other%40example.com/messages'],
    'encoded path traversal' => ['users/team%40example.com/%2e%2e/other%40example.com/messages'],
    'encoded path backslash' => ['users/team%40example.com/%5c..%5cother%40example.com/messages'],
    'fragment' => ['users/team%40example.com/messages?$skiptoken=private#fragment'],
]);

it('fails missing collection content instead of returning a completed empty read', function (string $resource): void {
    $history = [];
    $graph = continuationGraphClient([new Response(200, [], '{"@odata.deltaLink":"https://graph.microsoft.com/v1.0/users/team%40example.com/mailFolders/inbox/messages/delta?$deltatoken=done"}')], $history);
    $mailbox = new MsGraphMailboxResource($graph, new BatchRequest($graph), new MsGraphDeltaSync($graph), 'team@example.com');

    expect(fn () => match ($resource) {
        'delta' => $mailbox->folder('inbox')->delta(),
        'messages' => $mailbox->messages()->get(),
        'folders' => $mailbox->folders()->get(),
    })->toThrow(ApiRequestException::class);
})->with(['delta', 'messages', 'folders']);

it('reports the message page limit without claiming a completed collection', function (): void {
    config(['mailbox.max_query_pages' => 1]);
    $history = [];
    $graph = continuationGraphClient([new Response(200, [], '{"value":[],"@odata.nextLink":"https://graph.microsoft.com/v1.0/users/team%40example.com/mailFolders/inbox/messages?$skiptoken=next"}')], $history);
    $mailbox = new MsGraphMailboxResource($graph, new BatchRequest($graph), new MsGraphDeltaSync($graph), 'team@example.com');

    expect(fn () => $mailbox->messages()->get())->toThrow(ApiRequestException::class, 'page limit');
    expect($history)->toHaveCount(1);
});

it('keeps continuation queries and bearer secrets out of errors and logs', function (string $failure): void {
    $path = 'https://graph.microsoft.com/v1.0/users/team%40example.com/messages?$skiptoken=private-continuation';
    $response = match ($failure) {
        'HTTP error' => new Response(400, [], '{"error":"private-bearer private-recovery"}'),
        'transport error' => new ConnectException('private-bearer private-recovery '.$path, new Request('GET', $path, ['Authorization' => 'Bearer private-bearer'])),
        'invalid JSON' => new Response(200, [], '{private-recovery'),
    };
    $history = [];
    $records = new TestHandler;
    Log::channel((string) config('mailbox.log_channel', 'stack'))->getLogger()->pushHandler($records);
    $graph = continuationGraphClient([$response, $response], $history);

    try {
        $graph->get($path, mailbox: 'team@example.com');
        test()->fail('Expected provider failure.');
    } catch (ApiRequestException|ProviderTransportException $exception) {
        $diagnostics = (string) $exception.json_encode($exception).json_encode($records->getRecords());
        foreach (['private-bearer', 'private-continuation', 'private-recovery'] as $secret) {
            expect($diagnostics)->not->toContain($secret);
        }
    }
})->with(['HTTP error', 'transport error', 'invalid JSON']);

it('redacts private checkpoints and authenticated client state from debug output', function (): void {
    $history = [];
    $graph = continuationGraphClient([
        new Response(200, [], '{"value":[],"@odata.deltaLink":"https://graph.microsoft.com/v1.0/users/team%40example.com/mailFolders/inbox/messages/delta?$deltatoken=private-recovery"}'),
    ], $history);
    $result = (new MsGraphDeltaSync($graph))->syncFolder('team@example.com', 'inbox');

    ob_start();
    var_dump($graph, $result);
    $debug = ob_get_clean();

    expect($debug)->not->toContain('private-bearer');
    expect($debug)->not->toContain('private-recovery');
    expect($result->deltaLink)->toContain('private-recovery');
});

it('retries replay-safe continuation reads without changing the opaque URL', function (string $failure): void {
    $path = 'https://graph.microsoft.com/v1.0/users/team%40example.com/mailFolders/inbox/messages/delta?$deltatoken=opaque%2B%2F&repeat=1&repeat=2';
    $history = [];
    $graph = continuationGraphClient([
        $failure === 'server error' ? new Response(503) : new ConnectException('Connection reset', new Request('GET', $path)),
        new Response(200, [], json_encode(['value' => [], '@odata.deltaLink' => $path])),
    ], $history);

    $result = (new MsGraphDeltaSync($graph))->syncFolder('team@example.com', 'inbox', $path);

    expect($result->deltaLink)->toBe($path)
        ->and(array_map(fn (array $entry): string => (string) $entry['request']->getUri(), $history))->toBe([$path, $path]);
})->with(['server error', 'connection reset']);

it('fails cyclic provider pages instead of completing partial reads', function (string $resource): void {
    $history = [];
    $path = 'https://graph.microsoft.com/v1.0/users/team%40example.com/'.match ($resource) {
        'delta' => 'mailFolders/inbox/messages/delta',
        'messages' => 'mailFolders/inbox/messages',
        'folders' => 'mailFolders',
    };
    $page = ['value' => [], '@odata.nextLink' => $path.'?$skiptoken=repeat'];
    $graph = continuationGraphClient([
        new Response(200, [], json_encode($page)),
        new Response(200, [], json_encode($page)),
        new Response(200, [], json_encode(['value' => [], '@odata.deltaLink' => $path.'?$deltatoken=done'])),
    ], $history);
    $mailbox = new MsGraphMailboxResource($graph, new BatchRequest($graph), new MsGraphDeltaSync($graph), 'team@example.com');

    expect(fn () => match ($resource) {
        'delta' => $mailbox->folder('inbox')->delta(),
        'messages' => $mailbox->messages()->get(),
        'folders' => $mailbox->folders()->get(),
    })->toThrow(ApiRequestException::class);
    expect($history)->toHaveCount(2);
})->with(['delta', 'messages', 'folders']);

it('continues relative provider links within the original folder', function (string $next): void {
    $history = [];
    $path = 'https://graph.microsoft.com/v1.0/users/team%40example.com/mailFolders/inbox/messages/delta';
    $graph = continuationGraphClient([
        new Response(200, [], json_encode(['value' => [], '@odata.nextLink' => $next])),
        new Response(200, [], json_encode(['value' => [], '@odata.deltaLink' => $path.'?$deltatoken=done'])),
    ], $history);

    $result = (new MsGraphDeltaSync($graph))->syncFolder('team@example.com', 'inbox');

    expect($result->fullSyncRequired)->toBeFalse()
        ->and((string) $history[1]['request']->getUri())->toBe($path.'?$skiptoken=opaque%2b%2F&repeat=1&repeat=2');
})->with([
    'root relative' => ['/v1.0/users/team%40example.com/mailFolders/inbox/messages/delta?$skiptoken=opaque%2b%2F&repeat=1&repeat=2'],
    'API relative' => ['users/team%40example.com/mailFolders/inbox/messages/delta?$skiptoken=opaque%2b%2F&repeat=1&repeat=2'],
    'query relative' => ['?$skiptoken=opaque%2b%2F&repeat=1&repeat=2'],
    'path relative' => ['delta?$skiptoken=opaque%2b%2F&repeat=1&repeat=2'],
]);

it('fails incomplete or invalid delta progress without reading another resource', function (array $progress): void {
    $history = [];
    $graph = continuationGraphClient([
        new Response(200, [], json_encode(['value' => [], ...$progress])),
        new Response(200, [], '{"value":[]}'),
    ], $history);

    expect(fn () => (new MsGraphDeltaSync($graph))->syncFolder('team@example.com', 'inbox'))
        ->toThrow(ApiRequestException::class);
    expect($history)->toHaveCount(1);
})->with([
    'missing completion' => [[]],
    'empty continuation' => [['@odata.nextLink' => '']],
    'malformed continuation' => [['@odata.nextLink' => ['invalid']]],
    'wrong folder' => [['@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/team%40example.com/mailFolders/archive/messages/delta?$skiptoken=private']],
    'wrong operation' => [['@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/team%40example.com/mailFolders/inbox/messages?$skiptoken=private']],
    'missing query' => [['@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/users/team%40example.com/mailFolders/inbox/messages/delta']],
    'malformed query escape' => [['@odata.nextLink' => '?$skiptoken=bad%zz']],
]);

it('refuses redirects before a second authenticated request', function (string $location): void {
    $history = [];
    $graph = continuationGraphClient([
        new Response(302, ['Location' => $location]),
        new Response(200, [], '{"value":[]}'),
    ], $history);

    expect(fn () => $graph->get('users/team%40example.com/messages', mailbox: 'team@example.com'))
        ->toThrow(ApiRequestException::class);
    expect($history)->toHaveCount(1);
})->with([
    'foreign origin' => ['https://evil.example/collect'],
    'same origin other mailbox' => ['https://graph.microsoft.com/v1.0/users/other%40example.com/messages'],
]);

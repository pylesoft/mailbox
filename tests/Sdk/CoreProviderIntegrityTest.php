<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Drivers\MsGraph\BatchRequest;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphDeltaCollector;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphDeltaSync;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphMailboxResource;
use Pyle\Mailbox\Drivers\MsGraph\RateLimiter;
use Pyle\Mailbox\Drivers\MsGraph\TokenManager;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('performs bulk message changes with the selected Graph grant', function (string $operation): void {
    $history = [];
    $mailbox = coreIntegrityMailbox([new Response(200, [], '{"id":"one"}'), new Response(200, [], '{"id":"two"}')], $history);

    if ($operation === 'moveTo') {
        $mailbox->messages()->moveTo('archive', ['one', 'two']);
    } else {
        $mailbox->messages()->{$operation}(['one', 'two']);
    }

    expect($history)->toHaveCount(2);
    foreach ($history as $index => $entry) {
        expect($entry['request']->getHeaderLine('Authorization'))->toBe('Bearer selected-token')
            ->and($entry['request']->getUri()->getPath())->toBe('/v1.0/users/same%40example.com/messages/'.['one', 'two'][$index].($operation === 'moveTo' ? '/move' : ''))
            ->and(json_decode((string) $entry['request']->getBody(), true))->toBe($operation === 'moveTo' ? ['destinationId' => 'archive'] : ['isRead' => $operation === 'markAsRead']);
    }
})->with(['markAsRead', 'markAsUnread', 'moveTo']);

it('preserves an eager delta checkpoint over a supplied default Graph query', function (): void {
    $history = [];
    $checkpoint = 'https://graph.microsoft.com/v1.0/users/same%40example.com/mailFolders/inbox/messages/delta?$deltatoken=a%2bb%2Fc%3D&repeat=one&repeat=two&literal=a+b';
    $mailbox = coreIntegrityMailbox([new Response(200, [], json_encode(['value' => [], '@odata.deltaLink' => $checkpoint], JSON_THROW_ON_ERROR))], $history, ['query' => ['unrelated' => 'default']]);

    $result = $mailbox->folder('inbox')->delta($checkpoint);

    expect($result->deltaLink)->toBe($checkpoint)
        ->and((string) $history[0]['request']->getUri())->toBe($checkpoint);
});

it('rejects native Graph array tombstones before completing a delta', function (): void {
    $history = [];
    $mailbox = coreIntegrityMailbox([new Response(200, [], '{"value":[{"id":"one","@removed":[]}],"@odata.deltaLink":"https://graph.microsoft.com/v1.0/users/same%40example.com/mailFolders/inbox/messages/delta?$deltatoken=done"}')], $history);

    expect(fn () => $mailbox->initialSync(new SyncScope('inbox'), new SyncBudget))->toThrow(ApiRequestException::class)
        ->and($history)->toHaveCount(1);
});

it('retains a native empty Graph tombstone object as completed removal evidence', function (): void {
    $history = [];
    $mailbox = coreIntegrityMailbox([new Response(200, [], '{"value":[{"id":"one","@removed":{}}],"@odata.deltaLink":"https://graph.microsoft.com/v1.0/users/same%40example.com/mailFolders/inbox/messages/delta?$deltatoken=done"}')], $history);

    $result = $mailbox->initialSync(new SyncScope('inbox'), new SyncBudget);

    expect($result->removals)->toBe(['one'])->and($result->checkpoint)->not->toBeNull();
});

it('rejects native recipient objects through ordinary Graph message reads', function (string $entrypoint): void {
    $history = [];
    $message = '{"id":"one","toRecipients":{}}';
    $mailbox = coreIntegrityMailbox([new Response(200, [], $entrypoint === 'message' ? $message : '{"value":['.$message.']}')], $history);

    expect(fn () => $entrypoint === 'message' ? $mailbox->message('one')->get() : $mailbox->messages()->get())->toThrow(MailboxException::class)
        ->and($history)->toHaveCount(1);
})->with(['message', 'query']);

it('validates native recipient kinds in configured Graph message reads without changing raw arrays', function (string $entrypoint): void {
    $history = [];
    $message = '{"id":"one","toRecipients":{}}';
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], $entrypoint === 'message' ? $message : '{"value":['.$message.']}'), new Response(200, [], $message)]));
    $stack->push(Middleware::history($history));
    $tokens = new TokenManager(['tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret'], new Client(['handler' => new MockHandler([new Response(200, [], '{"access_token":"configured","expires_in":3600}')])]));
    $graph = new GraphClient([], $tokens, new RateLimiter([]), new Client(['handler' => $stack]));
    $mailbox = new MsGraphMailboxResource($graph, new BatchRequest($graph), new MsGraphDeltaSync($graph), 'same@example.com');

    expect(fn () => $entrypoint === 'message' ? $mailbox->message('one')->get() : $mailbox->messages()->get())->toThrow(MailboxException::class);
    expect($graph->get('users/same%40example.com/messages/one', mailbox: 'same@example.com'))->toBe(['id' => 'one', 'toRecipients' => []])
        ->and($history)->toHaveCount(2);
})->with(['message', 'query']);

it('rejects malformed native Graph delta collections before completing a checkpoint', function (string $collection): void {
    $history = [];
    $mailbox = coreIntegrityMailbox([new Response(200, [], '{"value":'.$collection.',"@odata.deltaLink":"https://graph.microsoft.com/v1.0/users/same%40example.com/mailFolders/inbox/messages/delta?$deltatoken=done"}')], $history);

    expect(fn () => $mailbox->folder('inbox')->delta())->toThrow(MailboxException::class)
        ->and($history)->toHaveCount(1);
})->with(['{}', '{"0":{"id":"one"}}', 'null', '"invalid"']);

it('rejects unusable eager Graph delta identities before returning progress', function (array $identity, bool $removed, string $progress): void {
    $history = [];
    $row = $removed ? [...$identity, '@removed' => (object) []] : $identity;
    $endpoint = 'https://graph.microsoft.com/v1.0/users/same%40example.com/mailFolders/inbox/messages/delta';
    $checkpoint = $endpoint.'?$deltatoken=retained';
    $link = $progress === 'nextLink' ? '?$skiptoken=unconsumed' : '?$deltatoken=unusable';
    $mailbox = coreIntegrityMailbox([
        new Response(200, [], json_encode([
            'value' => [(object) $row],
            '@odata.'.$progress => $endpoint.$link,
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['value' => [], '@odata.deltaLink' => $endpoint.'?$deltatoken=advanced'], JSON_THROW_ON_ERROR)),
    ], $history);

    expect(fn () => $mailbox->folder('inbox')->delta($checkpoint))->toThrow(ApiRequestException::class);

    expect($history)->toHaveCount(1);
    expect((string) $history[0]['request']->getUri())->toBe($checkpoint);
})->with([
    'missing ID' => [[]],
    'null ID' => [['id' => null]],
    'numeric ID' => [['id' => 12]],
    'boolean ID' => [['id' => true]],
    'empty ID' => [['id' => '']],
    'blank ID' => [['id' => " \t"]],
])->with([false, true])->with(['nextLink', 'deltaLink']);

it('rejects non-array eager Graph delta rows from supplied clients', function (mixed $row): void {
    $client = new class($row) extends GraphClient
    {
        public function __construct(private mixed $row)
        {
            parent::__construct([], new TokenManager([]), new RateLimiter([]));
        }

        public function getMessagePayload(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            return ['value' => [$this->row], '@odata.deltaLink' => '?$deltatoken=unusable'];
        }
    };

    expect(fn () => (new MsGraphDeltaCollector($client))->collect('same@example.com', 'inbox', null))
        ->toThrow(ApiRequestException::class);
})->with([null, 'invalid']);

it('preserves eager Graph delta messages with only an identity and minimal tombstones', function (): void {
    $history = [];
    $checkpoint = 'https://graph.microsoft.com/v1.0/users/same%40example.com/mailFolders/inbox/messages/delta?$deltatoken=done';
    $mailbox = coreIntegrityMailbox([new Response(200, [], json_encode([
        'value' => [['id' => 'created'], ['id' => 'deleted', '@removed' => (object) []]],
        '@odata.deltaLink' => $checkpoint,
    ], JSON_THROW_ON_ERROR))], $history);

    $result = $mailbox->folder('inbox')->delta();

    expect($result->created->sole()->id)->toBe('created');
    expect($result->created->sole()->subject)->toBe('');
    expect($result->deleted->all())->toBe(['deleted']);
    expect($result->deltaLink)->toBe($checkpoint);
});

it('rejects native folder object envelopes before listing or creating folders', function (string $collection, string $operation): void {
    $history = [];
    $mailbox = coreIntegrityMailbox([
        new Response(200, [], '{"value":'.$collection.'}'),
        new Response(200, [], '{"id":"existing","displayName":"Existing"}'),
        new Response(200, [], '{"id":"existing","displayName":"Existing"}'),
    ], $history);

    expect(fn () => $operation === 'list' ? $mailbox->folders()->get() : $mailbox->folders()->createPath('Existing'))
        ->toThrow(MailboxException::class)
        ->and($history)->toHaveCount(1);
})->with(['{}', '{"0":{"id":"existing","displayName":"Existing"}}'])->with(['list', 'create']);

it('preserves eager message and folder continuation queries with supplied client defaults', function (string $resource): void {
    $history = [];
    $path = 'https://graph.microsoft.com/v1.0/users/same%40example.com/'.($resource === 'messages' ? 'mailFolders/inbox/messages' : 'mailFolders');
    $next = $path.'?$skiptoken=a%2bb%2Fc%3D&repeat=one&repeat=two&literal=a+b';
    $mailbox = coreIntegrityMailbox([new Response(200, [], json_encode(['value' => [], '@odata.nextLink' => $next], JSON_THROW_ON_ERROR)), new Response(200, [], '{"value":[]}')], $history, ['query' => ['unrelated' => 'default']]);

    $mailbox->{$resource}()->get();

    expect((string) $history[1]['request']->getUri())->toBe($next);
})->with(['messages', 'folders']);

it('rejects malformed eager Graph message rows for collection result methods', function (mixed $row, string $method): void {
    $history = [];
    $mailbox = coreIntegrityMailbox([new Response(200, [], json_encode(['value' => [$row]], JSON_THROW_ON_ERROR))], $history);

    expect(fn () => $mailbox->messages()->{$method}())->toThrow(MailboxException::class);
    expect($history)->toHaveCount(1);
})->with([null, 12, 'broken'])->with(['get', 'count', 'first']);

it('rejects malformed selected or supplied eager Graph message identities', function (mixed $id, array $fields): void {
    $history = [];
    $mailbox = coreIntegrityMailbox([new Response(200, [], json_encode(['value' => [['id' => $id, 'subject' => 'Projected subject']]], JSON_THROW_ON_ERROR))], $history);

    expect(fn () => $mailbox->messages()->select($fields)->get())->toThrow(MailboxException::class);
    expect($history)->toHaveCount(1);
})->with([null, 12, '', '   '])->with([[['id', 'subject']], [['subject']]]);

it('rejects an absent selected eager Graph message identity', function (): void {
    $history = [];
    $mailbox = coreIntegrityMailbox([new Response(200, [], '{"value":[{"subject":"Projected subject"}]}')], $history);

    expect(fn () => $mailbox->messages()->select(['id', 'subject'])->get())->toThrow(MailboxException::class);
    expect($history)->toHaveCount(1);
});

it('preserves eager Graph subject projections without requiring an unselected identity', function (): void {
    $history = [];
    $mailbox = coreIntegrityMailbox([new Response(200, [], '{"value":[{"subject":"Projected subject"}]}')], $history);

    $message = $mailbox->messages()->select(['subject'])->get()->sole();

    expect($message->subject)->toBe('Projected subject')->and($message->id)->toBe('');
    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    expect($query['$select'])->toBe('subject');
});

it('rejects native list rows in eager Graph subject projections', function (string $row): void {
    $history = [];
    $mailbox = coreIntegrityMailbox([new Response(200, [], '{"value":['.$row.']}')], $history);

    expect(fn () => $mailbox->messages()->select(['subject'])->get())->toThrow(MailboxException::class);
    expect($history)->toHaveCount(1);
})->with(['[]', '["broken"]']);

it('preserves native empty objects in eager Graph partial projections', function (): void {
    $history = [];
    $mailbox = coreIntegrityMailbox([new Response(200, [], '{"value":[{}]}')], $history);

    $message = $mailbox->messages()->select(['subject'])->get()->sole();

    expect($message->subject)->toBe('')->and($message->id)->toBe('')->and($message->raw)->toBe([]);
    expect($history)->toHaveCount(1);
});

it('rejects malformed eager Graph folder rows before returning folders or creating a path', function (mixed $row, string $operation): void {
    $history = [];
    $mailbox = coreIntegrityMailbox([
        new Response(200, [], json_encode(['value' => [$row]], JSON_THROW_ON_ERROR)),
        new Response(201, [], '{"id":"created","displayName":"Existing"}'),
        new Response(200, [], '{"id":"created","displayName":"Existing"}'),
    ], $history);

    expect(fn () => $operation === 'get' ? $mailbox->folders()->get() : $mailbox->folders()->createPath('Existing'))
        ->toThrow(MailboxException::class);
    expect($history)->toHaveCount(1);
})->with([[null], [12], ['broken'], [['displayName' => 'Existing']], [['id' => null]], [['id' => 12]], [['id' => '']], [['id' => '   ']]])
    ->with(['get', 'createPath']);

/**
 * @param  list<mixed>  $responses
 * @param  array<int, mixed>  $history
 * @param  array<string, mixed>  $options
 */
function coreIntegrityMailbox(array $responses, array &$history, array $options = []): MailboxResource
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'selected-token'],
    ]);

    return Mailbox::forGrant(new GrantContext('connection', 'grant', '1', 'ms-graph', 'same@example.com'), $tokens, new Client(['handler' => $stack, ...$options]));
}

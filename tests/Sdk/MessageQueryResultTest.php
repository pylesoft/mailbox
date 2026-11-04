<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\SupportsMessageQueryResults;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\InvalidReceivedHeaderException;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Exceptions\ResourceNotFoundException;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('retains Graph header rejections while reading valid messages across pages', function (): void {
    $history = [];
    $mailbox = queryResultMailbox('ms-graph', [
        ['value' => [['id' => 'a', 'subject' => 'A'], ['id' => 'bad', 'ccRecipients' => [['emailAddress' => ['name' => 'Name only']]]]],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/reader%40example.com/mailFolders/inbox/messages?$skiptoken=next'],
        ['value' => [['id' => 'c', 'subject' => 'C']]],
    ], $history);

    $query = $mailbox->messages();
    expect($query)->toBeInstanceOf(SupportsMessageQueryResults::class);
    $result = $query->getResult();

    expect($result->messages->pluck('id')->all())->toBe(['a', 'c'])
        ->and($result->rejections->sole()->providerMessageId)->toBe('bad')
        ->and($result->rejections->sole()->reason)->toBe('invalid_received_header')
        ->and($result->exhausted)->toBeTrue();
    expect($history)->toHaveCount(2);
    foreach ($history as $entry) {
        expect($entry['request']->getHeaderLine('Authorization'))->toBe('Bearer selected-token')
            ->and($entry['request']->getHeaderLine('Prefer'))->toContain('ImmutableId');
    }
});

it('retains Gmail header rejections while reading valid messages across pages', function (): void {
    $history = [];
    $mailbox = queryResultMailbox('gmail', [
        ['messages' => [['id' => 'a'], ['id' => 'bad']], 'nextPageToken' => 'next'],
        queryResultGmailMessage('a'),
        queryResultGmailMessage('bad', [['name' => 'Cc', 'value' => 'Name only']]),
        ['messages' => [['id' => 'bad'], ['id' => 'c']]],
        queryResultGmailMessage('c'),
    ], $history);

    $query = $mailbox->messages()->orderBy('subject', 'asc');
    expect($query)->toBeInstanceOf(SupportsMessageQueryResults::class);
    $result = $query->getResult();

    expect($result->messages->pluck('id')->all())->toBe(['a', 'c'])
        ->and($result->rejections->sole()->providerMessageId)->toBe('bad')
        ->and($result->rejections->sole()->reason)->toBe('invalid_received_header')
        ->and($result->exhausted)->toBeTrue();
    expect($history)->toHaveCount(5);
});

it('isolates malformed Graph received header collections within a page', function (string $field): void {
    $history = [];
    $mailbox = queryResultMailbox('ms-graph', [['value' => [
        ['id' => 'a'], ['id' => 'bad', $field => (object) ['broken' => true]], ['id' => 'c'],
    ]]], $history);

    $result = $mailbox->messages()->orderBy('subject', 'asc')->getResult();

    expect($result->messages->pluck('id')->all())->toBe(['a', 'c'])
        ->and($result->rejections->pluck('providerMessageId')->all())->toBe(['bad']);
})->with(['toRecipients', 'ccRecipients', 'bccRecipients', 'replyTo', 'internetMessageHeaders']);

it('isolates a malformed Gmail root header collection within a page', function (mixed $headers): void {
    $history = [];
    $mailbox = queryResultMailbox('gmail', [
        ['messages' => [['id' => 'a'], ['id' => 'bad'], ['id' => 'c']]],
        queryResultGmailMessage('a'),
        ['id' => 'bad', 'payload' => ['headers' => $headers, 'parts' => []]],
        queryResultGmailMessage('c'),
    ], $history);

    $result = $mailbox->messages()->orderBy('subject', 'asc')->getResult();

    expect($result->messages->pluck('id')->all())->toBe(['a', 'c'])
        ->and($result->rejections->pluck('providerMessageId')->all())->toBe(['bad']);
})->with([
    'object collection' => [(object) ['broken' => true]],
    'scalar header' => [[12]],
    'missing value' => [[['name' => 'Cc']]],
    'non-string name' => [[['name' => 12, 'value' => 'value']]],
]);

it('validates Gmail raw identity before malformed header conversion or hydration', function (): void {
    $history = [];
    $mailbox = queryResultMailbox('gmail', [
        ['messages' => [['id' => 'expected']]],
        ['id' => 'different', 'payload' => ['headers' => (object) [], 'mimeType' => 'text/plain', 'body' => ['attachmentId' => 'body']]],
    ], $history);

    expect(fn () => $mailbox->messages()->getResult())->toThrow(ApiRequestException::class, 'mismatched');
    expect($history)->toHaveCount(2);
});

it('keeps malformed nested Gmail MIME collections fatal', function (string $field): void {
    $history = [];
    $mailbox = queryResultMailbox('gmail', [
        ['messages' => [['id' => 'bad']]],
        ['id' => 'bad', 'payload' => ['headers' => [], 'parts' => [[$field => (object) []]]]],
    ], $history);

    expect(fn () => $mailbox->messages()->getResult())->toThrow(MailboxException::class);
})->with(['parts', 'headers']);

it('keeps malformed received headers strict through existing query and single reads', function (string $driver, string $entrypoint): void {
    $history = [];
    $bad = $driver === 'gmail'
        ? queryResultGmailMessage('bad', [['name' => 'Cc', 'value' => 'Name only']])
        : ['id' => 'bad', 'ccRecipients' => [['emailAddress' => ['name' => 'Name only']]]];
    $responses = $entrypoint === 'message' ? [$bad] : ($driver === 'gmail' ? [['messages' => [['id' => 'bad']]], $bad] : [['value' => [$bad]]]);
    $mailbox = queryResultMailbox($driver, $responses, $history);

    expect(fn () => $entrypoint === 'message' ? $mailbox->message('bad')->get() : $mailbox->messages()->{$entrypoint}())
        ->toThrow(InvalidReceivedHeaderException::class);
})->with(['ms-graph', 'gmail'])->with(['get', 'first', 'message']);

it('rejects invalid provider identities instead of retaining a header rejection', function (string $driver, mixed $id): void {
    $history = [];
    $row = ['id' => $id];
    $mailbox = queryResultMailbox($driver, [$driver === 'gmail' ? ['messages' => [$row]] : ['value' => [$row]]], $history);

    expect(fn () => $mailbox->messages()->getResult())->toThrow(ApiRequestException::class);
    expect($history)->toHaveCount(1);
})->with(['ms-graph', 'gmail'])->with([[null], [12], [''], ['  '], ["bad\0id"], ["bad\nid"], ["bad\x7fid"]]);

it('rejects Gmail listed read identity mismatches before retaining a bad header', function (): void {
    $history = [];
    $mailbox = queryResultMailbox('gmail', [
        ['messages' => [['id' => 'expected']]],
        queryResultGmailMessage('different', [['name' => 'Cc', 'value' => 'Name only']]),
    ], $history);

    expect(fn () => $mailbox->messages()->getResult())->toThrow(ApiRequestException::class, 'mismatched');
});

it('does not isolate Gmail MIME body failures', function (): void {
    $history = [];
    $mailbox = queryResultMailbox('gmail', [
        ['messages' => [['id' => 'bad']]],
        ['id' => 'bad', 'payload' => ['mimeType' => 'text/plain', 'body' => ['data' => '%%%']]],
    ], $history);

    expect(fn () => $mailbox->messages()->getResult())->toThrow(MailboxException::class);
});

it('fails oversized listing responses instead of returning successful exhaustion', function (string $driver): void {
    $history = [];
    $body = $driver === 'gmail' ? '{"messages":[]}' : '{"value":[]}';
    $mailbox = queryResultMailbox($driver, [new Response(200, ['Content-Length' => '8388609'], $body)], $history);

    expect(fn () => $mailbox->messages()->getResult())->toThrow(ResponseLimitExceededException::class);
})->with(['ms-graph', 'gmail']);

it('preserves encountered rejections, local filters, deduplication and limits', function (string $driver): void {
    $history = [];
    $bad = ['id' => 'bad', 'subject' => 'private subject', 'ccRecipients' => [['emailAddress' => ['name' => 'Name only']]]];
    $next = 'https://graph.microsoft.com/v1.0/users/reader%40example.com/mailFolders/archive/messages?$skiptoken=next';
    $responses = $driver === 'gmail' ? [
        ['messages' => [['id' => 'bad'], ['id' => 'skip']], 'nextPageToken' => 'next'],
        queryResultGmailMessage('bad', [['name' => 'From', 'value' => 'Name only']]),
        queryResultGmailMessage('skip'),
        ['messages' => [['id' => 'bad'], ['id' => 'keep-a'], ['id' => 'keep-a'], ['id' => 'keep-c']], 'nextPageToken' => 'more'],
        queryResultGmailMessage('keep-a'), queryResultGmailMessage('keep-c'),
    ] : [
        ['value' => [$bad, ['id' => 'skip', 'subject' => 'skip']], '@odata.nextLink' => $next],
        ['value' => [$bad, ['id' => 'keep-a', 'subject' => 'keep-a'], ['id' => 'keep-a', 'subject' => 'keep-a'], ['id' => 'keep-c', 'subject' => 'keep-c']],
            '@odata.nextLink' => str_replace('next', 'more', $next)],
    ];
    $mailbox = queryResultMailbox($driver, $responses, $history);
    $result = $mailbox->messages()->inFolder('archive')->search('keep')->where('subject', 'contains', 'keep')->orderBy('subject', 'asc')->take(1)->getResult();

    expect($result->messages->pluck('id')->all())->toBe(['keep-a'])
        ->and($result->rejections->pluck('providerMessageId')->all())->toBe(['bad'])
        ->and($result->exhausted)->toBeFalse();
    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    expect($driver === 'gmail' ? $query['labelIds'] : $history[0]['request']->getUri()->getPath())
        ->toBe($driver === 'gmail' ? ['archive'] : '/v1.0/users/reader%40example.com/mailFolders/archive/messages');
})->with(['ms-graph', 'gmail']);

it('reports exhaustion when the final page exactly fills the result limit', function (string $driver): void {
    $history = [];
    $responses = $driver === 'gmail' ? [['messages' => [['id' => 'a']]], queryResultGmailMessage('a')] : [['value' => [['id' => 'a']]]];
    $result = queryResultMailbox($driver, $responses, $history)->messages()->take(1)->getResult();

    expect($result->messages)->toHaveCount(1)->and($result->exhausted)->toBeTrue();
})->with(['ms-graph', 'gmail']);

it('keeps unusable continuations fatal even when the result limit is reached', function (string $driver): void {
    $history = [];
    $responses = $driver === 'gmail'
        ? [['messages' => [['id' => 'a']], 'nextPageToken' => ''], queryResultGmailMessage('a')]
        : [['value' => [['id' => 'a']], '@odata.nextLink' => '']];

    expect(fn () => queryResultMailbox($driver, $responses, $history)->messages()->take(1)->getResult())->toThrow(MailboxException::class);
})->with(['ms-graph', 'gmail']);

it('does not report successful exhaustion at the configured page bound', function (string $driver): void {
    config(['mailbox.max_query_pages' => 1]);
    $history = [];
    $responses = $driver === 'gmail'
        ? [['messages' => [], 'nextPageToken' => 'next']]
        : [['value' => [], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/reader%40example.com/mailFolders/inbox/messages?$skiptoken=next']];

    $mailbox = queryResultMailbox($driver, $responses, $history);
    expect(fn () => $mailbox->messages()->getResult())->toThrow(ApiRequestException::class);
    expect($history)->toHaveCount(1);
})->with(['ms-graph', 'gmail']);

it('redacts message content and identifiers from ordinary result output', function (): void {
    $history = [];
    $result = queryResultMailbox('ms-graph', [['value' => [
        ['id' => 'private-valid-id', 'subject' => 'private subject'],
        ['id' => 'private-rejected-id', 'ccRecipients' => [['emailAddress' => ['name' => 'private name']]]],
    ]]], $history)->messages()->getResult();

    expect($result->toArray())->toBe(['messageCount' => 1, 'rejectionCount' => 1, 'exhausted' => true]);
    expect(json_encode($result, JSON_THROW_ON_ERROR))->toBe('{"messageCount":1,"rejectionCount":1,"exhausted":true}');
    expect($result->rejections->sole()->toArray())->toBe(['reason' => 'invalid_received_header']);
    ob_start();
    var_dump($result, $result->rejections->sole());
    $debug = ob_get_clean();
    expect($debug)->not->toContain('private');
});

it('propagates provider HTTP failures instead of header rejections', function (string $driver, int $status): void {
    config(['mailbox.max_retries' => 0]);
    $history = [];
    $mailbox = queryResultMailbox($driver, [new Response($status, ['Retry-After' => '0'], '{}')], $history);

    expect(fn () => $mailbox->messages()->getResult())->toThrow(MailboxException::class);
    expect($history)->toHaveCount(1);
})->with(['ms-graph', 'gmail'])->with([401, 403, 429, 503]);

it('propagates transport failures instead of header rejections', function (string $driver): void {
    config(['mailbox.max_retries' => 0]);
    $history = [];
    $mailbox = queryResultMailbox($driver, [new ConnectException('Synthetic transport failure', new Request('GET', 'https://provider.example'))], $history);

    expect(fn () => $mailbox->messages()->getResult())->toThrow(MailboxException::class);
    expect($history)->toHaveCount(1);
})->with(['ms-graph', 'gmail']);

it('rejects malformed native listing envelopes', function (string $driver, string $collection): void {
    $history = [];
    $key = $driver === 'gmail' ? 'messages' : 'value';
    $mailbox = queryResultMailbox($driver, [new Response(200, [], '{"'.$key.'":'.$collection.'}')], $history);

    expect(fn () => $mailbox->messages()->getResult())->toThrow(MailboxException::class);
})->with(['ms-graph', 'gmail'])->with(['{}', 'null', '"broken"', '[12]', '[{}]']);

it('rejects cyclic listing continuations', function (string $driver): void {
    $history = [];
    $page = $driver === 'gmail' ? ['messages' => [], 'nextPageToken' => 'same']
        : ['value' => [], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/reader%40example.com/mailFolders/inbox/messages?$skiptoken=same'];
    $mailbox = queryResultMailbox($driver, [$page, $page], $history);

    expect(fn () => $mailbox->messages()->getResult())->toThrow(ApiRequestException::class);
    expect($history)->toHaveCount(2);
})->with(['ms-graph', 'gmail']);

it('identifies missing messages only through native exact reads', function (string $driver, string $method): void {
    $history = [];
    $mailbox = queryResultMailbox($driver, [new Response(404, [], '{}')], $history);

    try {
        $mailbox->message('missing')->{$method}();
        test()->fail('A missing provider message must fail.');
    } catch (ResourceNotFoundException $exception) {
        expect($exception->resourceType)->toBe($method === 'getExact' ? 'message' : 'resource')
            ->and($exception->resourceId)->toBe($method === 'getExact' ? 'missing' : 'users/reader%40example.com/messages/missing');
    }
})->with(['ms-graph', 'gmail'])->with(['get', 'getExact']);

it('rejects invalid exact-read identities before requesting a provider', function (string $driver): void {
    $history = [];
    $mailbox = queryResultMailbox($driver, [new Response(404, [], '{}')], $history);

    expect(fn () => $mailbox->message("bad\0id")->getExact())->toThrow(MailboxException::class, 'valid provider message identity');
    expect($history)->toBeEmpty();
})->with(['ms-graph', 'gmail']);

it('keeps a missing Gmail external body operational during an exact read', function (): void {
    $history = [];
    $mailbox = queryResultMailbox('gmail', [
        ['id' => 'expected', 'payload' => ['mimeType' => 'text/plain', 'body' => ['attachmentId' => 'body']]],
        new Response(404, [], '{}'),
    ], $history);

    try {
        $mailbox->message('expected')->getExact();
        test()->fail('A missing external body must fail.');
    } catch (MailboxException $exception) {
        expect($exception)->not->toBeInstanceOf(ResourceNotFoundException::class);
        expect($exception->getMessage())->toBe('External Gmail body content was not available.');
    }
});

/** @param list<array{name: string, value: string}> $headers
 * @return array<string, mixed>
 */
function queryResultGmailMessage(string $id, array $headers = []): array
{
    return ['id' => $id, 'labelIds' => ['INBOX', 'UNREAD'], 'payload' => ['headers' => [
        ...$headers,
        ['name' => 'Subject', 'value' => $id],
        ['name' => 'From', 'value' => 'Sender <sender@example.com>'],
    ], 'parts' => []]];
}

/**
 * @param  list<array<string, mixed>|Response|Throwable>  $responses
 * @param  array<int, mixed>  $history
 */
function queryResultMailbox(string $driver, array $responses, array &$history): MailboxResource
{
    $stack = HandlerStack::create(new MockHandler(array_map(
        static fn (array|Response|Throwable $response): Response|Throwable => is_array($response)
            ? new Response(200, [], json_encode($response, JSON_THROW_ON_ERROR)) : $response,
        $responses,
    )));
    $stack->push(Middleware::history($history));
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['reader@example.com'], 'token' => 'selected-token'],
    ]);

    return Mailbox::forGrant(new GrantContext('connection', 'grant', '1', $driver, 'reader@example.com'), $tokens,
        new Client(['handler' => $stack, 'base_uri' => $driver === 'gmail' ? 'https://gmail.googleapis.com/gmail/v1/' : 'https://graph.microsoft.com/v1.0/']));
}

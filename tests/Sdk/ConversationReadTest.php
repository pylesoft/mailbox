<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Pyle\Mailbox\Contracts\FolderQueryBuilder;
use Pyle\Mailbox\Contracts\FolderResource;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\Contracts\SupportsConversationRead;
use Pyle\Mailbox\DTOs\BodyReadLimits;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncContinuation;
use Pyle\Mailbox\DTOs\SyncIdentity;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\InvalidSyncStateException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('reads an entire selected Gmail thread with older context and externalized text', function (): void {
    $history = [];
    $mailbox = conversationMailbox('gmail', [
        ['id' => 'thread', 'messages' => [
            gmailConversationMessage('older', 'Old context', '1262304000000'),
            ['id' => 'recent', 'threadId' => 'thread', 'internalDate' => '1793232000000', 'payload' => [
                'mimeType' => 'multipart/mixed',
                'parts' => [
                    ['mimeType' => 'text/plain', 'body' => ['attachmentId' => 'external-body', 'size' => 3]],
                    ['mimeType' => 'image/png', 'filename' => 'logo.png', 'headers' => [['name' => 'Content-ID', 'value' => '<logo>']], 'body' => ['attachmentId' => 'logo', 'size' => 2]],
                ],
            ]],
        ]],
        ['data' => 'WWVz', 'size' => 3],
    ], $history);
    $scope = new SyncScope('INBOX', new DateTimeImmutable('2026-10-01T00:00:00Z'));

    expect($mailbox)->toBeInstanceOf(SupportsConversationRead::class);
    $read = $mailbox->conversation('thread', $scope, new SyncBudget(maxPages: 2));

    expect($read->complete)->toBeTrue()
        ->and($read->continuation)->toBeNull()
        ->and($read->identity->scope)->toBe($scope)
        ->and(array_column($read->messages, 'id'))->toBe(['older', 'recent'])
        ->and($read->messages[0]->body?->content)->toBe('Old context')
        ->and($read->messages[1]->body?->content)->toBe('Yes')
        ->and($read->files[0]['messageId'])->toBe('recent')
        ->and($read->files[0]['attachment']->id)->toBe('logo')
        ->and($read->files[0]['attachment']->contentId)->toBe('<logo>')
        ->and($history)->toHaveCount(2)
        ->and($history[0]['request']->getUri()->getPath())->toEndWith('/threads/thread')
        ->and($history[0]['request']->getUri()->getQuery())->toBe('format=full')
        ->and($history[1]['request']->getUri()->getPath())->toEndWith('/messages/recent/attachments/external-body');
});

it('retains completed Gmail messages and resumes hydration after the operation request limit', function (): void {
    $history = [];
    $external = ['id' => 'recent', 'threadId' => 'thread', 'payload' => [
        'mimeType' => 'text/plain', 'body' => ['attachmentId' => 'external-body', 'size' => 3],
    ]];
    $mailbox = conversationMailbox('gmail', [
        ['id' => 'thread', 'messages' => [gmailConversationMessage('older'), $external]],
        ['data' => 'WWVz', 'size' => 3],
    ], $history);
    $scope = new SyncScope;

    $first = $mailbox->conversation('thread', $scope, new SyncBudget(maxRequests: 1));

    expect($first->complete)->toBeFalse()
        ->and($first->reason)->toBe('request_limit')
        ->and(array_column($first->messages, 'id'))->toBe(['older'])
        ->and($first->continuation)->not->toBeNull()
        ->and($history)->toHaveCount(1);

    $resumedHistory = [];
    $resumed = conversationMailbox('gmail', [$external, ['data' => 'WWVz', 'size' => 3]], $resumedHistory)
        ->conversation('thread', $scope, new SyncBudget(maxRequests: 2), $first->continuation);

    expect($resumed->complete)->toBeTrue()
        ->and(array_column($resumed->messages, 'id'))->toBe(['recent'])
        ->and($resumed->messages[0]->body?->content)->toBe('Yes')
        ->and($resumedHistory)->toHaveCount(2)
        ->and($resumedHistory[0]['request']->getUri()->getPath())->toEndWith('/messages/recent');
});

it('charges assembled Gmail body separators to the aggregate conversation content limit', function (): void {
    $history = [];
    $message = ['threadId' => 'thread', 'payload' => ['mimeType' => 'multipart/mixed', 'parts' => [
        ['mimeType' => 'text/plain', 'body' => ['data' => 'QQ', 'size' => 1]],
        ['mimeType' => 'text/plain', 'body' => ['data' => 'Qg', 'size' => 1]],
    ]]];
    $mailbox = conversationMailbox('gmail', [['id' => 'thread', 'messages' => [
        ['id' => 'one', ...$message], ['id' => 'two', ...$message],
    ]]], $history);

    $read = $mailbox->conversation('thread', new SyncScope, new SyncBudget, limits: new BodyReadLimits(maxBodyBytes: 5));

    expect($read->complete)->toBeFalse()
        ->and($read->reason)->toBe('content_limit')
        ->and(array_column($read->messages, 'id'))->toBe(['one'])
        ->and($read->messages[0]->body?->content)->toBe("A\nB")
        ->and($read->continuation)->not->toBeNull()
        ->and($history)->toHaveCount(1);
});

it('pages Graph conversation messages and inline file metadata only within the selected folder', function (): void {
    $history = [];
    $conversationId = "thread'one";
    $next = 'https://graph.microsoft.com/v1.0/users/reader%40example.com/mailFolders/FolderA/messages?$skiptoken=opaque%2bb%2Fc%3D&repeat=one&repeat=two';
    $filesNext = 'https://graph.microsoft.com/v1.0/users/reader%40example.com/mailFolders/FolderA/messages/older/attachments?$skiptoken=files%2Fnext%3D';
    $mailbox = conversationMailbox('ms-graph', [
        ['value' => [graphConversationMessage('older', $conversationId)], '@odata.nextLink' => $next],
        ['value' => [['id' => 'inline', 'name' => 'logo.png', 'contentType' => 'image/png', 'size' => 2, 'isInline' => true, 'contentId' => 'logo']], '@odata.nextLink' => $filesNext],
        ['value' => [['id' => 'document', 'name' => 'notes.txt', 'contentType' => 'text/plain', 'size' => 3, 'isInline' => false]]],
        ['value' => [graphConversationMessage('recent', $conversationId)]],
        ['value' => []],
    ], $history);
    $scope = new SyncScope('FolderA', new DateTimeImmutable('2026-10-01T00:00:00Z'));

    expect($mailbox)->toBeInstanceOf(SupportsConversationRead::class);
    $read = $mailbox->conversation($conversationId, $scope, new SyncBudget(maxPages: 5, maxRequests: 5));

    expect($read->complete)->toBeTrue()
        ->and($read->identity->context->mailbox)->toBe('reader@example.com')
        ->and($read->identity->scope)->toBe($scope)
        ->and(array_column($read->messages, 'id'))->toBe(['older', 'recent'])
        ->and($read->messages[0]->body?->content)->toBe('Older context')
        ->and($read->files[0]['attachment']->isInline)->toBeTrue()
        ->and($read->files[1]['attachment']->id)->toBe('document')
        ->and($history)->toHaveCount(5)
        ->and((string) $history[2]['request']->getUri())->toBe($filesNext)
        ->and((string) $history[3]['request']->getUri())->toBe($next);
    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    expect($query['$filter'])->toBe("conversationId eq 'thread''one'");
    expect($query)->not->toHaveKey('$orderby');
    expect($query['$filter'])->not->toContain('receivedDateTime');
    parse_str($history[1]['request']->getUri()->getQuery(), $filesQuery);
    expect($filesQuery['$select'])->not->toContain('contentBytes');
    foreach ($history as $entry) {
        expect($entry['request']->getUri()->getPath())->toContain('/mailFolders/FolderA/messages');
    }
});

it('resumes Graph file metadata pages without omitting earlier metadata or widening the folder scope', function (): void {
    $history = [];
    $filesNext = 'https://graph.microsoft.com/v1.0/users/reader%40example.com/mailFolders/FolderA/messages/older/attachments?$skiptoken=next%2Ffiles%3D';
    $mailbox = conversationMailbox('ms-graph', [
        ['value' => [graphConversationMessage('older')]],
        ['value' => [['id' => 'inline', 'name' => 'logo.png', 'contentType' => 'image/png', 'size' => 2, 'isInline' => true, 'contentId' => 'logo']], '@odata.nextLink' => $filesNext],
    ], $history);
    $scope = new SyncScope('FolderA');

    $first = $mailbox->conversation('thread', $scope, new SyncBudget(maxPages: 2));

    expect($first->complete)->toBeFalse()
        ->and($first->reason)->toBe('page_limit')
        ->and($first->messages)->toBe([])
        ->and($history)->toHaveCount(2);

    $resumedHistory = [];
    $read = conversationMailbox('ms-graph', [
        graphConversationMessage('older'),
        ['value' => [['id' => 'file', 'name' => 'file.txt', 'contentType' => 'text/plain', 'size' => 3, 'isInline' => false]]],
    ], $resumedHistory)->conversation('thread', $scope, new SyncBudget(maxRequests: 2), $first->continuation);

    expect($read->complete)->toBeTrue()
        ->and(array_column($read->messages, 'id'))->toBe(['older'])
        ->and(array_map(fn (array $file): string => $file['attachment']->id, $read->files))->toBe(['inline', 'file'])
        ->and($resumedHistory)->toHaveCount(2)
        ->and($resumedHistory[0]['request']->getUri()->getPath())->toEndWith('/mailFolders/FolderA/messages/older')
        ->and((string) $resumedHistory[1]['request']->getUri())->toBe($filesNext);
});

it('bounds an unpaged Gmail thread by actual response bytes and can restart it with a larger budget', function (): void {
    $history = [];
    $thread = ['id' => 'thread', 'messages' => [gmailConversationMessage('one', str_repeat('x', 1000))]];
    $source = Utils::streamFor(json_encode($thread, JSON_THROW_ON_ERROR));
    $bytes = 0;
    $closed = false;
    $stream = FnStream::decorate($source, [
        'read' => function (int $length) use ($source, &$bytes): string {
            $chunk = $source->read($length);
            $bytes += strlen($chunk);

            return $chunk;
        },
        'close' => function () use ($source, &$closed): void {
            $closed = true;
            $source->close();
        },
    ]);

    $first = conversationMailbox('gmail', [new Response(200, [], $stream)], $history)
        ->conversation('thread', new SyncScope, new SyncBudget(maxResponseBytes: 128));

    expect($first->complete)->toBeFalse()
        ->and($first->reason)->toBe('response_limit')
        ->and($first->messages)->toBe([])
        ->and($bytes)->toBe(129)
        ->and($closed)->toBeTrue()
        ->and($history)->toHaveCount(1);

    $resumedHistory = [];
    $read = conversationMailbox('gmail', [$thread], $resumedHistory)
        ->conversation('thread', new SyncScope, new SyncBudget, $first->continuation);

    expect($read->complete)->toBeTrue()
        ->and($read->messages[0]->body?->content)->toBe(str_repeat('x', 1000))
        ->and($resumedHistory)->toHaveCount(1);
});

it('retains earlier Graph messages when a later metadata fetch is interrupted without hidden retries', function (): void {
    $history = [];
    $mailbox = conversationMailbox('ms-graph', [
        ['value' => [graphConversationMessage('older'), graphConversationMessage('recent')]],
        ['value' => []],
        new Response(503, [], '{"error":"private-provider-detail"}'),
    ], $history);
    $scope = new SyncScope('FolderA');

    $first = $mailbox->conversation('thread', $scope, new SyncBudget(maxPages: 5));

    expect($first->complete)->toBeFalse()
        ->and($first->reason)->toBe('provider_interrupted')
        ->and(array_column($first->messages, 'id'))->toBe(['older'])
        ->and($first->continuation)->not->toBeNull()
        ->and($history)->toHaveCount(3);
    expect(json_encode($first))->not->toContain('private-provider-detail');
    expect(json_encode($first))->not->toContain('selected-token');

    $resumedHistory = [];
    $last = conversationMailbox('ms-graph', [graphConversationMessage('recent'), ['value' => []]], $resumedHistory)
        ->conversation('thread', $scope, new SyncBudget, $first->continuation);

    expect($last->complete)->toBeTrue()
        ->and(array_column($last->messages, 'id'))->toBe(['recent'])
        ->and($resumedHistory)->toHaveCount(2);
});

it('never completes a malformed object in place of a conversation collection', function (string $provider, string $body): void {
    $history = [];
    $mailbox = conversationMailbox($provider, [new Response(200, [], $body)], $history);

    $read = $mailbox->conversation('thread', new SyncScope, new SyncBudget);

    expect($read->complete)->toBeFalse()
        ->and($read->messages)->toBe([])
        ->and($read->reason)->toBe('content_incomplete')
        ->and($read->continuation)->not->toBeNull()
        ->and($history)->toHaveCount(1);
})->with([
    'Gmail messages object' => ['gmail', '{"id":"thread","messages":{}}'],
    'Gmail MIME parts object' => ['gmail', '{"id":"thread","messages":[{"id":"one","threadId":"thread","payload":{"mimeType":"multipart/mixed","parts":{}}}]}'],
    'Gmail headers object' => ['gmail', '{"id":"thread","messages":[{"id":"one","threadId":"thread","payload":{"mimeType":"text/plain","headers":{},"body":{"data":"T0s","size":2}}}]}'],
    'Graph value object' => ['ms-graph', '{"value":{}}'],
]);

it('rejects conversation continuations bound to a different operation before provider IO', function (string $provider, string $difference): void {
    $history = [];
    $scope = new SyncScope('FolderA', new DateTimeImmutable('2026-01-01T00:00:00Z'));
    $first = conversationMailbox($provider, [$provider === 'gmail'
        ? ['id' => 'thread', 'messages' => [gmailConversationMessage('older'), gmailConversationMessage('recent')]]
        : ['value' => [graphConversationMessage('older')]]], $history)
        ->conversation('thread', $scope, new SyncBudget(maxItems: 1, maxRequests: 1));
    $original = $first->continuation;
    $context = $original->identity->context;
    $identity = new SyncIdentity(
        $difference === 'provider' ? 'other-provider' : $provider,
        new GrantContext(
            $difference === 'connection' ? 'other' : $context->connectionId,
            $difference === 'grant' ? 'other' : $context->grantId,
            $difference === 'generation' ? 'other' : $context->lifecycleGeneration,
            $difference === 'driver' ? 'other' : $context->driver,
            $difference === 'mailbox' ? 'other@example.com' : $context->mailbox,
        ),
        new SyncScope($difference === 'folder' ? 'other' : $scope->folderId, $difference === 'cutoff' ? new DateTimeImmutable('2025-01-01T00:00:00Z') : $scope->importSince),
        $difference === 'version' ? 2 : 1,
    );
    $continuation = new SyncContinuation($identity, $difference === 'phase' ? 'other' : $original->phase, $original->position);
    $deniedHistory = [];
    $mailbox = conversationMailbox($provider, [], $deniedHistory);

    expect(fn () => $mailbox->conversation($difference === 'conversation' ? 'other' : 'thread', $scope, new SyncBudget, $continuation))
        ->toThrow(InvalidSyncStateException::class)
        ->and($deniedHistory)->toBe([]);
})->with(['gmail', 'ms-graph'])->with(['connection', 'grant', 'generation', 'driver', 'mailbox', 'provider', 'folder', 'cutoff', 'version', 'phase', 'conversation']);

it('rejects a retained conversation reader after the host invalidates its generation', function (string $provider): void {
    $history = [];
    $tokens = new TestGrantTokenProvider(['grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['reader@example.com'], 'token' => 'selected-token']]);
    $mailbox = conversationMailbox($provider, [$provider === 'gmail'
        ? ['id' => 'thread', 'messages' => [gmailConversationMessage('older'), gmailConversationMessage('recent')]]
        : ['value' => [graphConversationMessage('older')]]], $history, tokens: $tokens);
    $first = $mailbox->conversation('thread', new SyncScope, new SyncBudget(maxItems: 1, maxRequests: 1));
    $tokens->grants['grant']['generation'] = '2';

    expect(fn () => $mailbox->conversation('thread', new SyncScope, new SyncBudget, $first->continuation))
        ->toThrow(GrantAccessDeniedException::class)
        ->and($history)->toHaveCount(1);
})->with(['gmail', 'ms-graph']);

it('does not follow a Graph conversation continuation outside the selected mailbox or folder', function (string $next): void {
    $history = [];
    $read = conversationMailbox('ms-graph', [['value' => [graphConversationMessage('older')], '@odata.nextLink' => $next]], $history)
        ->conversation('thread', new SyncScope('FolderA'), new SyncBudget(maxPages: 5));

    expect($read->complete)->toBeFalse()
        ->and($read->reason)->toBe('provider_interrupted')
        ->and($history)->toHaveCount(1);
    expect(json_encode($read))->not->toContain('recovery-secret');
    expect(json_encode($read))->not->toContain('selected-token');
})->with([
    'folder' => 'https://graph.microsoft.com/v1.0/users/reader%40example.com/mailFolders/Other/messages?$skiptoken=recovery-secret',
    'mailbox' => 'https://graph.microsoft.com/v1.0/users/other%40example.com/mailFolders/FolderA/messages?$skiptoken=recovery-secret',
    'origin' => 'https://evil.example/v1.0/users/reader%40example.com/mailFolders/FolderA/messages?$skiptoken=recovery-secret',
    'HTTP' => 'http://graph.microsoft.com/v1.0/users/reader%40example.com/mailFolders/FolderA/messages?$skiptoken=recovery-secret',
]);

it('returns explicit unavailable scope without inventing conversation content', function (string $provider): void {
    $history = [];
    $read = conversationMailbox($provider, [new Response(403, [], '{"error":"private-access-detail"}')], $history)
        ->conversation('thread', new SyncScope('FolderA'), new SyncBudget);

    expect($read->complete)->toBeFalse()
        ->and($read->reason)->toBe('scope_unavailable')
        ->and($read->messages)->toBe([])
        ->and($read->continuation)->not->toBeNull()
        ->and($history)->toHaveCount(1);
    expect(json_encode($read))->not->toContain('private-access-detail');
})->with(['gmail', 'ms-graph']);

it('rejects conversation reads without an explicit grant on configured resources', function (): void {
    $mailbox = Mailbox::driver('ms-graph')->mailbox('reader@example.com');

    expect(fn () => $mailbox->conversation('thread', new SyncScope, new SyncBudget))->toThrow(UnsupportedCapabilityException::class);
});

it('does not complete missing or malformed conversation content', function (string $provider, array $responses): void {
    $history = [];
    $read = conversationMailbox($provider, $responses, $history)->conversation('thread', new SyncScope, new SyncBudget(maxPages: 5));

    expect($read->complete)->toBeFalse()
        ->and($read->reason)->toBe('content_incomplete')
        ->and($read->messages)->toBe([])
        ->and($read->continuation)->not->toBeNull();
})->with([
    'Gmail missing payload' => ['gmail', [new Response(200, [], '{"id":"thread","messages":[{"id":"one","threadId":"thread"}]}')]],
    'Gmail empty multipart' => ['gmail', [new Response(200, [], '{"id":"thread","messages":[{"id":"one","threadId":"thread","payload":{"mimeType":"multipart/mixed","parts":[]}}]}')]],
    'Gmail missing text bytes' => ['gmail', [new Response(200, [], '{"id":"thread","messages":[{"id":"one","threadId":"thread","payload":{"mimeType":"text/plain","body":{"size":3}}}]}')]],
    'Graph missing body' => ['ms-graph', [new Response(200, [], '{"value":[{"id":"one","conversationId":"thread"}]}'), ['id' => 'one', 'conversationId' => 'thread']]],
    'Graph malformed body' => ['ms-graph', [new Response(200, [], '{"value":[{"id":"one","conversationId":"thread","body":{"contentType":"html"}}]}')]],
    'Graph malformed file collection' => ['ms-graph', [['value' => [graphConversationMessage('one')]], new Response(200, [], '{"value":{}}')]],
    'Graph missing file size' => ['ms-graph', [['value' => [graphConversationMessage('one')]], ['value' => [['id' => 'file', 'name' => 'file', 'contentType' => 'text/plain', 'isInline' => false]]]]],
]);

it('keeps provider paging recovery tokens out of public conversation serialization and debugging', function (): void {
    $history = [];
    $read = conversationMailbox('ms-graph', [['value' => [], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/reader%40example.com/messages?$skiptoken=recovery-secret']], $history)
        ->conversation('thread', new SyncScope, new SyncBudget);

    expect($read->complete)->toBeFalse();
    expect(json_encode($read))->not->toContain('recovery-secret');
    expect(print_r($read, true))->not->toContain('recovery-secret');
    expect($read->continuation->position['nextLink'])->toContain('recovery-secret');
    expect(unserialize(serialize($read))->continuation->position['nextLink'])->toContain('recovery-secret');
});

it('redacts private conversation recovery positions from rejected-operation exception traces', function (): void {
    $history = [];
    $mailbox = conversationMailbox('ms-graph', [['value' => [], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/reader%40example.com/messages?$skiptoken=recovery-secret']], $history);
    $read = $mailbox->conversation('thread', new SyncScope, new SyncBudget);
    $previous = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');

    try {
        try {
            $mailbox->conversation('other', new SyncScope, new SyncBudget, $read->continuation);
            $this->fail('A continuation for another conversation was accepted.');
        } catch (InvalidSyncStateException $exception) {
            $trace = array_values(array_filter($exception->getTrace(), fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'Pyle\\Mailbox\\')));
            expect(json_encode($trace, JSON_PARTIAL_OUTPUT_ON_ERROR))->not->toContain('recovery-secret');
        }
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
});

it('charges secondary content and metadata requests to the same conversation budget', function (string $provider, string $limit): void {
    $history = [];
    $external = fn (string $id): array => ['id' => $id, 'threadId' => 'thread', 'payload' => ['mimeType' => 'text/plain', 'body' => ['attachmentId' => $id.'-body', 'size' => 3]]];
    $messages = $provider === 'gmail'
        ? ['id' => 'thread', 'messages' => [$external('one'), $external('two')]]
        : ['value' => [
            [...graphConversationMessage('one'), 'body' => ['contentType' => 'html', 'content' => 'Yes']],
            [...graphConversationMessage('two'), 'body' => ['contentType' => 'html', 'content' => 'Yes']],
        ]];
    $body = ['data' => 'WWVz', 'size' => 3];
    $file = fn (string $id): array => ['value' => [['id' => $id, 'name' => 'file.txt', 'contentType' => 'text/plain', 'size' => 3, 'isInline' => false]]];
    $secondary = $provider === 'gmail' ? [$body, $body] : ($limit === 'parts' ? [$file('one-file'), $file('two-file')] : [['value' => []], ['value' => []]]);
    $responses = [$messages, ...$secondary];
    $maxResponseBytes = $limit === 'response' ? array_sum(array_map(fn (array $value): int => strlen(json_encode($value, JSON_THROW_ON_ERROR)), $responses)) - 1 : 8388608;

    $read = conversationMailbox($provider, $responses, $history)->conversation('thread', new SyncScope,
        new SyncBudget(maxPages: 5, maxRequests: $limit === 'requests' ? 2 : 10, maxResponseBytes: $maxResponseBytes),
        limits: new BodyReadLimits(maxBodyBytes: $limit === 'content' ? 5 : 4194304, maxHydrations: $limit === 'hydrations' ? 1 : 32, maxParts: $limit === 'parts' ? 1 : 256));

    $reason = match ($limit) {
        'requests' => 'request_limit',
        'response' => 'response_limit',
        'hydrations' => $provider === 'gmail' ? 'content_limit' : 'hydration_limit',
        'parts' => $provider === 'gmail' ? 'content_limit' : 'part_limit',
        default => 'content_limit',
    };
    expect($read->complete)->toBeFalse()
        ->and($read->reason)->toBe($reason)
        ->and(array_column($read->messages, 'id'))->toBe(['one'])
        ->and($read->continuation)->not->toBeNull();
    $requests = $limit === 'requests' || $limit === 'hydrations' || ($provider === 'ms-graph' && $limit === 'content') || ($provider === 'gmail' && $limit === 'parts') ? 2 : 3;
    expect($history)->toHaveCount($requests);
})->with(['gmail', 'ms-graph'])->with(['requests', 'response', 'content', 'hydrations', 'parts']);

it('keeps basic custom mailbox resources usable without a conversation capability', function (): void {
    $history = [];
    $original = conversationMailbox('ms-graph', [['id' => 'basic']], $history);
    $custom = new class($original) implements MailboxResource
    {
        public function __construct(private readonly MailboxResource $original) {}

        public function messages(): MessageQueryBuilder
        {
            return $this->original->messages();
        }

        public function message(string $messageId): MessageResource
        {
            return $this->original->message($messageId);
        }

        public function folders(): FolderQueryBuilder
        {
            return $this->original->folders();
        }

        public function folder(string|WellKnownFolder $folderId): FolderResource
        {
            return $this->original->folder($folderId);
        }
    };

    expect($custom)->not->toBeInstanceOf(SupportsConversationRead::class)
        ->and($custom->message('basic')->get()->id)->toBe('basic')
        ->and($history)->toHaveCount(1);
});

it('does not report a repeated Graph conversation page as complete', function (): void {
    $history = [];
    $next = 'https://graph.microsoft.com/v1.0/users/reader%40example.com/messages?$skiptoken=repeated';
    $read = conversationMailbox('ms-graph', [
        ['value' => [], '@odata.nextLink' => $next], ['value' => [], '@odata.nextLink' => $next],
    ], $history)->conversation('thread', new SyncScope, new SyncBudget(maxPages: 5));

    expect($read->complete)->toBeFalse()
        ->and($read->reason)->toBe('provider_interrupted')
        ->and($read->continuation)->not->toBeNull()
        ->and($history)->toHaveCount(2);
});

/** @return array<string, mixed> */
function graphConversationMessage(string $id, string $conversationId = 'thread'): array
{
    return [
        'id' => $id, 'conversationId' => $conversationId, 'parentFolderId' => 'FolderA',
        'body' => ['contentType' => 'html', 'content' => 'Older context'],
        'receivedDateTime' => '2010-01-01T00:00:00Z', 'hasAttachments' => false,
    ];
}

/** @return array<string, mixed> */
function gmailConversationMessage(string $id, string $body = 'Context', string $date = '1262304000000'): array
{
    return [
        'id' => $id, 'threadId' => 'thread', 'internalDate' => $date,
        'payload' => ['mimeType' => 'text/plain', 'body' => ['data' => rtrim(strtr(base64_encode($body), '+/', '-_'), '='), 'size' => strlen($body)]],
    ];
}

/**
 * @param  list<array<string, mixed>|Response|Throwable>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function conversationMailbox(string $provider, array $responses, array &$history, ?GrantContext $context = null, ?TestGrantTokenProvider $tokens = null): MailboxResource
{
    $stack = HandlerStack::create(new MockHandler(array_map(
        static fn (array|Response|Throwable $response): Response|Throwable => is_array($response)
            ? new Response(200, [], json_encode($response, JSON_THROW_ON_ERROR))
            : $response,
        $responses,
    )));
    $stack->push(Middleware::history($history));
    $context ??= new GrantContext('connection', 'grant', '1', $provider, 'reader@example.com');
    $tokens ??= new TestGrantTokenProvider([
        $context->grantId => ['connection' => $context->connectionId, 'generation' => $context->lifecycleGeneration, 'mailboxes' => [$context->mailbox], 'token' => 'selected-token'],
    ]);

    return Mailbox::forGrant($context, $tokens, new Client([
        'handler' => $stack,
        'base_uri' => $provider === 'gmail' ? 'https://gmail.googleapis.com/gmail/v1/' : 'https://graph.microsoft.com/v1.0/',
    ]));
}

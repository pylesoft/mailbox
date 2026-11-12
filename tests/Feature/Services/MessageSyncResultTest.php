<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\InvalidReceivedHeaderException;
use Pyle\Mailbox\Exceptions\InvalidRecoveryContinuationException;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Exceptions\ProviderServerException;
use Pyle\Mailbox\Exceptions\RateLimitException;
use Pyle\Mailbox\Exceptions\ResourceNotFoundException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Facades\Mailbox as MailboxFacade;
use Pyle\Mailbox\Services\Persistence\MessageSyncService;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

require_once __DIR__.'/Support/MessagePersistenceTestSupport.php';

it('compares poll requests using the SDK defaults without conflating different reads', function (): void {
    $service = new MessageSyncService;
    $options = ['folder_reference' => 'inbox'];
    $key = $service->pollRequestKey($options, 'ms-graph');
    $sent = $service->pollRequestKey(['folder_reference' => 'wk:sent', 'filters' => ['subject_contains' => [' Invoice '], 'from_email_addresses' => [' sender@example.com ']]], 'ms-graph');

    expect($service->pollRequestKey(['filters' => ['mail_folder_id' => 'wk:inbox', 'limit' => '100', 'page_size' => 25, 'lookback_hours' => 6]], 'ms-graph'))->toBe($key)
        ->and($service->pollRequestKey(['folder_reference' => 'sentitems', 'filters' => ['subject_contains' => ['Invoice'], 'from_email_addresses' => ['sender@example.com']]], 'ms-graph'))->toBe($sent)
        ->and($service->pollRequestKey([...$options, 'include_attachments' => false], 'ms-graph'))->not->toBe($key)
        ->and($service->pollRequestKey([...$options, 'filters' => ['lookback_hours' => 12]], 'ms-graph'))->not->toBe($key)
        ->and($service->pollRequestKey([...$options, 'filters' => ['received_after' => '2026-11-12T00:00:00+00:00']], 'ms-graph'))->not->toBe($key)
        ->and($service->pollRequestKey([], 'ms-graph'))->not->toBe($key);

    foreach (['has_attachments', 'is_read'] as $filter) {
        $enabled = $service->pollRequestKey([...$options, 'filters' => [$filter => true]], 'ms-graph');
        $disabled = $service->pollRequestKey([...$options, 'filters' => [$filter => false]], 'ms-graph');

        expect($service->pollRequestKey([...$options, 'filters' => [$filter => 1]], 'ms-graph'))->toBe($enabled)
            ->and($service->pollRequestKey([...$options, 'filters' => [$filter => '0']], 'ms-graph'))->toBe($disabled)
            ->and($service->pollRequestKey([...$options, 'filters' => [$filter => null]], 'ms-graph'))->toBe($key)
            ->and($disabled)->not->toBe($key)
            ->and($disabled)->not->toBe($enabled);
    }
});

it('shares the poll key when sender and subject alternatives are reordered', function (): void {
    $service = new MessageSyncService;
    $filters = [
        'from_email_addresses' => ['b@example.com', 'a@example.com'],
        'subject_contains' => ['Invoice', 'Confirmation'],
    ];
    $key = $service->pollRequestKey(['filters' => $filters], 'ms-graph');

    expect($service->pollRequestKey(['filters' => array_map(array_reverse(...), $filters)], 'ms-graph'))->toBe($key)
        ->and($service->pollRequestKey(['filters' => [...$filters, 'subject_contains' => ['Invoice', 'Receipt']]], 'ms-graph'))->not->toBe($key);
});

it('persists valid query outcomes and retains exact provider correlations without a clean timestamp', function (): void {
    $mailbox = createTestMailbox('Partial', 'reader@example.com', 'Reader');
    $resource = syncResultResource('ms-graph', [['value' => [
        ['id' => 'a', 'internetMessageId' => '<same@example.com>'],
        ['id' => 'bad', 'ccRecipients' => [['emailAddress' => ['name' => 'Name only']]]],
        ['id' => 'c', 'internetMessageId' => '<same@example.com>'],
    ]]]);
    expectMailboxFacadeForMailbox($mailbox, $resource);

    $result = (new MessageSyncService)->syncMailboxResult($mailbox);

    expect($result->messages)->toHaveCount(1)
        ->and($result->persisted->pluck('providerMessageId')->all())->toBe(['a', 'c'])
        ->and($result->persisted->pluck('message.id')->unique())->toHaveCount(1)
        ->and($result->rejections->sole()->providerMessageId)->toBe('bad')
        ->and($result->exhausted)->toBeTrue()
        ->and($mailbox->fresh()->last_synced_at)->toBeNull();
    expect(json_encode($result))->not->toContain('same@example.com', 'bad', 'providerMessageId');
});

it('recovers complete Graph pages with frozen bounds even above the ordinary poll limit', function (): void {
    $mailbox = createTestMailbox('Recovery pages', 'reader@example.com', 'Reader');
    $link = 'https://graph.microsoft.com/v1.0/users/reader%40example.com/mailFolders/inbox/messages?$skiptoken=opaque%2Btoken';
    $message = static fn (string $id): array => [
        'id' => $id,
        'subject' => 'Invoice',
        'receivedDateTime' => '2026-11-12T01:00:00Z',
    ];
    $history = [];
    $resource = syncResultResource('ms-graph', [
        ['value' => [$message('a'), $message('b')], '@odata.nextLink' => $link],
        ['value' => [[...$message('bad'), 'ccRecipients' => [['emailAddress' => ['name' => 'Name only']]]]], '@odata.nextLink' => str_replace('opaque', 'another', $link)],
        ['value' => [$message('b'), $message('c'), [...$message('excluded'), 'subject' => 'Report']]],
    ], $history);
    expectMailboxFacadeForMailbox($mailbox, $resource, $resource, $resource);
    $service = new MessageSyncService;
    $options = [
        'folder_reference' => 'inbox',
        'filters' => [
            'received_after' => '2026-11-12T00:00:00Z',
            'received_before' => '2026-11-12T06:00:00Z',
            'page_size' => 2,
            'limit' => 1,
        ],
        'rule_tree' => ['operator' => 'AND', 'conditions' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'Invoice']]],
    ];

    $first = $service->syncMailboxRecoveryPage($mailbox, $options, null);
    expect($first->messages->pluck('provider_message_id')->all())->toBe(['a', 'b'])
        ->and($first->nextContinuation)->not->toBeNull()->not->toContain('opaque')
        ->and($first->continuationFingerprint)->toBe(hash('sha256', $link))
        ->and($first->exhausted)->toBeFalse()
        ->and(json_encode($first))->not->toContain($first->nextContinuation);
    expect($history)->toHaveCount(1);
    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    expect($query['$top'])->toBe('2')
        ->and($query['$filter'])->toContain('2026-11-12T00:00:00+00:00', '2026-11-12T06:00:00+00:00', "contains(subject,'Invoice')");

    $rejected = $service->syncMailboxRecoveryPage($mailbox, $options, $first->nextContinuation);
    expect((string) $history[1]['request']->getUri())->toBe($link);
    expect($rejected->messages)->toBeEmpty()
        ->and($rejected->rejections->sole()->providerMessageId)->toBe('bad')
        ->and($rejected->exhausted)->toBeFalse();

    $last = $service->syncMailboxRecoveryPage($mailbox, $options, $rejected->nextContinuation);
    expect($last->messages->pluck('provider_message_id')->all())->toBe(['b', 'c'])
        ->and($last->excludedProviderMessageIds->all())->toBe(['excluded'])
        ->and($last->nextContinuation)->toBeNull()
        ->and($last->continuationFingerprint)->toBeNull()
        ->and($last->exhausted)->toBeTrue()
        ->and($mailbox->fresh()->last_synced_at)->toBeNull();
    $this->assertDatabaseCount('mailbox_messages', 3);
});

it('rejects a modified or differently scoped recovery continuation before a Graph request', function (): void {
    $mailbox = createTestMailbox('Recovery scope', 'reader@example.com', 'Reader');
    $link = 'https://graph.microsoft.com/v1.0/users/reader%40example.com/messages?$skiptoken=next';
    $resource = syncResultResource('ms-graph', [['value' => [], '@odata.nextLink' => $link]]);
    expectMailboxFacadeForMailbox($mailbox, $resource);
    $service = new MessageSyncService;
    $options = ['filters' => ['received_after' => '2026-11-12T00:00:00Z', 'received_before' => '2026-11-12T06:00:00Z']];
    $first = $service->syncMailboxRecoveryPage($mailbox, $options, null);

    expect(fn () => $service->syncMailboxRecoveryPage($mailbox, $options, $link))->toThrow(InvalidRecoveryContinuationException::class)
        ->and(fn () => $service->syncMailboxRecoveryPage($mailbox, [...$options, 'folder_reference' => 'inbox'], $first->nextContinuation))
        ->toThrow(InvalidRecoveryContinuationException::class);
    $mailbox->connection->lifecycle_generation = 'another-generation';
    expect(fn () => $service->syncMailboxRecoveryPage($mailbox, $options, $first->nextContinuation))->toThrow(InvalidRecoveryContinuationException::class);
});

it('distinguishes an expired recovery continuation from an ordinary Graph failure', function (): void {
    $mailbox = createTestMailbox('Recovery expiry', 'reader@example.com', 'Reader');
    $link = 'https://graph.microsoft.com/v1.0/users/reader%40example.com/messages?$skiptoken=next';
    $resource = syncResultResource('ms-graph', [
        ['value' => [], '@odata.nextLink' => $link],
        new Response(410, [], '{}'),
        new Response(400, [], '{}'),
    ]);
    expectMailboxFacadeForMailbox($mailbox, $resource, $resource, $resource);
    $service = new MessageSyncService;
    $options = ['filters' => ['received_after' => '2026-11-12T00:00:00Z', 'received_before' => '2026-11-12T06:00:00Z']];
    $first = $service->syncMailboxRecoveryPage($mailbox, $options, null);

    expect(fn () => $service->syncMailboxRecoveryPage($mailbox, $options, $first->nextContinuation))
        ->toThrow(InvalidRecoveryContinuationException::class)
        ->and(fn () => $service->syncMailboxRecoveryPage($mailbox, $options, $first->nextContinuation))
        ->toThrow(ApiRequestException::class);
});

it('keeps a cyclic or out-of-scope Graph page from becoming a recovery continuation', function (bool $cycle): void {
    $mailbox = createTestMailbox('Recovery invalid next link', 'reader@example.com', 'Reader');
    $link = 'https://graph.microsoft.com/v1.0/users/reader%40example.com/messages?$skiptoken=next';
    $badLink = $cycle ? $link : str_replace('reader', 'other', $link);
    $resource = syncResultResource('ms-graph', [
        ['value' => [], '@odata.nextLink' => $link],
        ['value' => [['id' => 'uncommitted']], '@odata.nextLink' => $badLink],
    ]);
    expectMailboxFacadeForMailbox($mailbox, $resource, $resource);
    $service = new MessageSyncService;
    $options = ['filters' => ['received_after' => '2026-11-12T00:00:00Z', 'received_before' => '2026-11-12T06:00:00Z']];
    $first = $service->syncMailboxRecoveryPage($mailbox, $options, null);

    expect(fn () => $service->syncMailboxRecoveryPage($mailbox, $options, $first->nextContinuation))
        ->toThrow($cycle ? InvalidRecoveryContinuationException::class : ApiRequestException::class);
    $this->assertDatabaseCount('mailbox_messages', 0);
})->with(['cycle' => [true], 'wrong mailbox' => [false]]);

it('returns Graph Retry-After to the queue after one provider attempt', function (int $status, ?string $hint, ?int $seconds): void {
    $mailbox = createTestMailbox('Queue retry', 'reader@example.com', 'Reader');
    $history = [];
    $resource = syncResultResource('ms-graph', [
        new Response($status, $hint === null ? [] : ['Retry-After' => $hint], '{}'),
        ['value' => []],
    ], $history);
    expectMailboxFacadeForMailbox($mailbox, $resource);

    try {
        (new MessageSyncService)->syncMailboxResult($mailbox, ['queue_managed_retry' => true]);
        test()->fail('The transient Graph response should return control to the queue.');
    } catch (ProviderServerException|RateLimitException $exception) {
        expect($exception)->toBeInstanceOf($status === 429 ? RateLimitException::class : ProviderServerException::class)
            ->and($exception->retryAfter)->toBe($seconds);
        if ($exception instanceof RateLimitException) {
            expect($exception->localContention)->toBeFalse();
        }
    }
    expect($history)->toHaveCount(1);
})->with([
    'throttled' => [429, '30', 30],
    'unavailable with delay' => [503, '180', 180],
    'gateway without delay' => [502, null, null],
]);

it('returns attachment read failures to the polling queue without releasing the job', function (string $stage, bool $recovery): void {
    $mailbox = createTestMailbox('Queue-owned attachments', 'reader@example.com', 'Reader');
    $job = Mockery::mock(Job::class);
    $job->shouldNotReceive('release');
    $this->app->instance('queue.job', $job);
    $attachment = ['id' => 'file', 'name' => 'invoice.pdf', 'contentType' => 'application/pdf', 'size' => 4];
    $responses = [['value' => [['id' => 'message', 'hasAttachments' => true, 'receivedDateTime' => '2026-11-12T01:00:00Z']]]];
    if ($stage !== 'list') {
        $responses[] = ['value' => [$attachment]];
    }
    if ($stage === 'stream') {
        $responses[] = $attachment;
    }
    $responses[] = new Response(429, ['Retry-After' => '30'], '{}');
    $history = [];
    expectMailboxFacadeForMailbox($mailbox, syncResultResource('ms-graph', $responses, $history));
    $service = new MessageSyncService;

    try {
        if ($recovery) {
            $service->syncMailboxRecoveryPage($mailbox, ['filters' => [
                'received_after' => '2026-11-12T00:00:00Z',
                'received_before' => '2026-11-12T06:00:00Z',
            ]], null);
        } else {
            $service->syncMailboxResult($mailbox, ['queue_managed_retry' => true]);
        }
        test()->fail('The attachment failure should return control to the queue.');
    } catch (RateLimitException $exception) {
        expect($exception->retryAfter)->toBe(30)
            ->and($exception->localContention)->toBeFalse();
    }

    expect($history)->toHaveCount(count($responses))
        ->and($mailbox->fresh()->last_synced_at)->toBeNull();
})->with(['list', 'metadata', 'stream'])->with(['scheduled' => [false], 'recovery' => [true]]);

it('reads an HTTP date in Graph Retry-After without waiting inside the worker', function (): void {
    $mailbox = createTestMailbox('Queue retry date', 'reader@example.com', 'Reader');
    $resource = syncResultResource('ms-graph', [new Response(503, ['Retry-After' => gmdate(DATE_RFC7231, time() + 120)], '{}')]);
    expectMailboxFacadeForMailbox($mailbox, $resource);

    try {
        (new MessageSyncService)->syncMailboxResult($mailbox, ['queue_managed_retry' => true]);
        test()->fail('The Graph response should return the retry date to the queue.');
    } catch (ProviderServerException $exception) {
        expect($exception->retryAfter)->toBeGreaterThan(60)->toBeLessThanOrEqual(120);
    }
});

it('does not wait or send a provider request when the queue managed poll has no slot', function (): void {
    $mailbox = createTestMailbox('Queue contention', 'reader@example.com', 'Reader');
    config(['mailbox.max_concurrent_per_mailbox' => 1, 'mailbox.concurrency_lock_timeout' => 30]);
    $lock = Cache::lock('mailbox:lock:ms-graph:reader@example.com:slot:1', 2);
    expect($lock->get())->toBeTrue();
    $history = [];
    $resource = syncResultResource('ms-graph', [['value' => []]], $history);
    expectMailboxFacadeForMailbox($mailbox, $resource);

    try {
        (new MessageSyncService)->syncMailboxResult($mailbox, ['queue_managed_retry' => true]);
        test()->fail('Queue-managed polling should immediately yield a contended slot.');
    } catch (RateLimitException $exception) {
        expect($exception->localContention)->toBeTrue()
            ->and($exception->retryAfter)->toBe(30);
    } finally {
        $lock->release();
    }
    expect($history)->toBeEmpty();
});

it('recovers an old explicit message while retaining current folder and explicit filter eligibility', function (string $driver, array $options, bool $included): void {
    $mailbox = createTestMailbox('Recovery', 'reader@example.com', 'Reader', $driver);
    $message = $driver === 'gmail'
        ? ['id' => 'old', 'internalDate' => '1704110400000', 'labelIds' => ['INBOX', 'UNREAD', 'Label_custom'], 'payload' => ['headers' => [['name' => 'Subject', 'value' => 'Invoice']], 'parts' => []]]
        : ['id' => 'old', 'receivedDateTime' => '2024-01-01T12:00:00Z', 'subject' => 'Invoice', 'parentFolderId' => 'folder-custom'];
    $resource = syncResultResource($driver, [$message, ['id' => ($options['folder'] ?? '') === 'wrong' ? 'elsewhere' : ($driver === 'gmail' ? 'Label_custom' : 'folder-custom')]]);
    expectMailboxFacadeForMailbox($mailbox, $resource);

    $persisted = (new MessageSyncService)->syncMailboxMessage($mailbox, 'old', $options);

    expect($persisted !== null)->toBe($included)
        ->and($mailbox->fresh()->last_synced_at)->toBeNull();
})->with(['ms-graph', 'gmail'])->with([
    'derived lookback only' => [['filters' => ['lookback_hours' => 1]], true],
    'explicit lower date' => [['filters' => ['received_after' => '2025-01-01T00:00:00Z']], false],
    'explicit upper date' => [['filters' => ['received_before' => '2023-01-01T00:00:00Z']], false],
    'read filter' => [['filters' => ['is_read' => true]], false],
    'subject filter with a rule tree' => [['filters' => ['subject_contains' => ['Wrong']], 'rule_tree' => ['operator' => 'AND', 'conditions' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'Invoice']]]], false],
    'Internet message id' => [['filters' => ['internet_message_id' => 'different@example.com']], false],
    'folder membership including secondary Gmail labels' => [['folder' => 'custom'], true],
    'wrong folder' => [['folder' => 'wrong'], false],
    'sender filter' => [['filters' => ['from_email_addresses' => ['different@example.com']]], false],
    'attachment filter' => [['filters' => ['has_attachments' => true]], false],
    'importance filter' => [['filters' => ['importance' => 'high']], false],
]);

it('excludes Gmail Spam and Trash from exact recovery under ordinary list scope', function (string $driver, array $labels, array $options): void {
    $mailbox = createTestMailbox('Gmail scope', 'reader@example.com', 'Reader', $driver);
    expectMailboxFacadeForMailbox($mailbox, syncResultResource('gmail', [
        ['id' => 'old', 'internalDate' => '1704110400000', 'labelIds' => $labels, 'payload' => ['headers' => [], 'parts' => []]],
        ['id' => 'Label_custom'],
    ]));

    $message = (new MessageSyncService)->syncMailboxMessage($mailbox, 'old', $options);

    expect($message)->toBeNull();
    $this->assertDatabaseCount('mailbox_messages', 0);
    expect($mailbox->fresh()->last_synced_at)->toBeNull();
})->with(['gmail', 'google-workspace'])->with([
    'Spam without a folder' => [['SPAM'], []],
    'Trash without a folder' => [['TRASH'], []],
    'Spam retaining a custom label' => [['SPAM', 'Label_custom'], ['folder' => 'custom']],
    'Trash retaining a custom label' => [['TRASH', 'Label_custom'], ['folder' => 'custom']],
]);

it('requires valid Gmail label evidence for exact recovery even without a folder', function (array $membership): void {
    $mailbox = createTestMailbox('Gmail label evidence', 'reader@example.com', 'Reader', 'gmail');
    expectMailboxFacadeForMailbox($mailbox, syncResultResource('gmail', [
        ['id' => 'old', 'internalDate' => '1704110400000', ...$membership, 'payload' => ['headers' => [], 'parts' => []]],
    ]));

    expect(fn () => (new MessageSyncService)->syncMailboxMessage($mailbox, 'old'))
        ->toThrow(MailboxException::class);
    $this->assertDatabaseCount('mailbox_messages', 0);
})->with([
    'missing labels' => [[]],
    'non-list labels' => [['labelIds' => ['label' => 'INBOX']]],
    'non-string label after Spam' => [['labelIds' => ['SPAM', 42]]],
    'blank label' => [['labelIds' => [' ']]],
]);

it('recovers visible Gmail messages within their current folder scope', function (string $driver, array $labels, array $options, string $folderId): void {
    $mailbox = createTestMailbox('Visible Gmail recovery', 'reader@example.com', 'Reader', $driver);
    expectMailboxFacadeForMailbox($mailbox, syncResultResource('gmail', [
        ['id' => 'old', 'internalDate' => '1704110400000', 'labelIds' => $labels, 'payload' => ['headers' => [], 'parts' => []]],
        ['id' => $folderId],
    ]));

    $message = (new MessageSyncService)->syncMailboxMessage($mailbox, 'old', $options);

    expect($message?->provider_message_id)->toBe('old');
    $this->assertDatabaseCount('mailbox_messages', 1);
    expect($mailbox->fresh()->last_synced_at)->toBeNull();
})->with(['gmail', 'google-workspace'])->with([
    'Inbox without a folder' => [['INBOX'], [], 'INBOX'],
    'unlabelled without a folder' => [[], [], 'INBOX'],
    'explicit Inbox' => [['INBOX'], ['folder' => 'inbox'], 'INBOX'],
    'secondary custom label' => [['INBOX', 'Label_custom'], ['folder' => 'custom'], 'Label_custom'],
]);

it('fences every local write and performs attachment downloads outside the fence', function (bool $hasAttachments): void {
    $mailbox = createTestMailbox('Guarded', 'reader@example.com', 'Reader');
    $message = testMailboxMessageDto('guarded', null, 'inbox', $hasAttachments);
    $inside = false;
    $writes = 0;
    $observing = true;
    $attachment = new AttachmentDto('file', 'invoice.pdf', 'application/pdf', 4, false, null);
    $resource = new TestMailboxResource(new TestMessageQueryBuilder(collect([$message])), [
        'guarded' => new TestMessageResource($message, collect([$attachment]), ['file' => function () use (&$inside): StreamInterface {
            expect($inside)->toBeFalse();

            return Utils::streamFor('data');
        }]),
    ]);
    expectMailboxFacadeForMailbox($mailbox, $resource);
    DB::listen(function ($query) use (&$inside, &$writes, &$observing): void {
        if ($observing && preg_match('/^(insert|update|delete)\b/i', $query->sql)) {
            expect($inside)->toBeTrue();
            $writes++;
        }
    });
    $guard = function (Closure $write) use (&$inside): mixed {
        $inside = true;
        try {
            return $write();
        } finally {
            $inside = false;
        }
    };

    try {
        $messages = (new MessageSyncService)->syncMailboxResult($mailbox, [], $guard)->messages;
    } finally {
        $observing = false;
    }

    expect($messages)->toHaveCount(1)->and($writes)->toBe($hasAttachments ? 4 : 3);
})->with([true, false]);

it('retains strict targeted failures instead of manufacturing a rejection', function (array|Response $response, string $exception): void {
    $mailbox = createTestMailbox('Strict recovery', 'reader@example.com', 'Reader');
    $resource = syncResultResource('ms-graph', [$response]);
    expectMailboxFacadeForMailbox($mailbox, $resource);

    expect(fn () => (new MessageSyncService)->syncMailboxMessage($mailbox, 'expected'))->toThrow($exception);
    expect($mailbox->fresh()->messages)->toHaveCount(0);
})->with([
    'malformed header' => [['id' => 'expected', 'ccRecipients' => [['emailAddress' => ['name' => 'Name only']]]], InvalidReceivedHeaderException::class],
    'mismatched identity' => [['id' => 'different'], MailboxException::class],
    'unavailable' => [new Response(404, [], '{}'), MailboxException::class],
]);

it('propagates a lost write fence without advancing the clean timestamp', function (): void {
    $mailbox = createTestMailbox('Lost guard', 'reader@example.com', 'Reader');
    $resource = syncResultResource('ms-graph', [['value' => [['id' => 'good']]]]);
    expectMailboxFacadeForMailbox($mailbox, $resource);

    expect(fn () => (new MessageSyncService)->syncMailboxResult($mailbox, [], function (Closure $write): never {
        throw new RuntimeException('Binding changed');
    }))->toThrow(RuntimeException::class, 'Binding changed');
    expect($mailbox->fresh()->messages)->toHaveCount(0)
        ->and($mailbox->fresh()->last_synced_at)->toBeNull();
});

it('stops each later attachment write, stale-row delete and timestamp when the fence is lost', function (int $stopAt): void {
    $mailbox = createTestMailbox('Later fence', 'reader@example.com', 'Reader');
    $message = testMailboxMessageDto('guarded', null, 'inbox', true);
    $attachment = new AttachmentDto('file', 'invoice.pdf', 'application/pdf', 4, false, null);
    $resource = new TestMailboxResource(new TestMessageQueryBuilder(collect([$message])), [
        'guarded' => new TestMessageResource($message, collect([$attachment]), ['file' => 'data']),
    ]);
    expectMailboxFacadeForMailbox($mailbox, $resource);
    $writes = 0;

    expect(fn () => (new MessageSyncService)->syncMailboxResult($mailbox, [], function (Closure $write) use (&$writes, $stopAt): mixed {
        if (++$writes === $stopAt) {
            throw new RuntimeException('Claim expired');
        }

        return $write();
    }))->toThrow(RuntimeException::class, 'Claim expired');
    expect($mailbox->fresh()->last_synced_at)->toBeNull()
        ->and($mailbox->fresh()->messages->sole()->attachments)->toHaveCount($stopAt === 2 ? 0 : 1);
})->with([2, 3, 4]);

it('retains Gmail header rejections and current local exclusions through persisted outcomes', function (): void {
    $mailbox = createTestMailbox('Gmail partial', 'reader@example.com', 'Reader', 'gmail');
    $payload = static fn (string $id, string $subject, bool $bad = false): array => [
        'id' => $id, 'internalDate' => (string) (now()->getTimestamp() * 1000), 'labelIds' => ['INBOX'], 'payload' => ['headers' => [
            ['name' => 'Subject', 'value' => $subject],
            ['name' => 'Cc', 'value' => $bad ? 'Name only' : 'Valid <valid@example.com>'],
        ], 'parts' => []],
    ];
    $resource = syncResultResource('gmail', [
        ['messages' => [['id' => 'a'], ['id' => 'bad'], ['id' => 'c']]],
        $payload('a', 'Invoice'), $payload('bad', 'Invoice', true), $payload('c', 'Other'),
    ]);
    expectMailboxFacadeForMailbox($mailbox, $resource);

    $result = (new MessageSyncService)->syncMailboxResult($mailbox, ['rule_tree' => ['operator' => 'AND', 'conditions' => [
        ['field' => 'subject', 'operator' => 'matches_regex', 'value' => '/Invoice/'],
    ]]]);

    expect($result->persisted->pluck('providerMessageId')->all())->toBe(['a'])
        ->and($result->excludedProviderMessageIds->all())->toBe(['c'])
        ->and($result->rejections->sole()->providerMessageId)->toBe('bad')
        ->and($mailbox->fresh()->last_synced_at)->toBeNull();
});

it('preserves custom strict query fallback and clean sync timestamps', function (): void {
    $mailbox = createTestMailbox('Custom fallback', 'reader@example.com', 'Reader', 'custom');
    $message = testMailboxMessageDto('custom', null, null, false);
    expectMailboxFacadeForMailbox($mailbox, new TestMailboxResource(new TestMessageQueryBuilder(collect([$message])), []));

    $result = (new MessageSyncService)->syncMailboxResult($mailbox);

    expect($result->messages)->toHaveCount(1)
        ->and($result->rejections)->toBeEmpty()
        ->and($result->exhausted)->toBeFalse()
        ->and($mailbox->fresh()->last_synced_at)->not->toBeNull();
});

it('rejects a targeted identity mismatch before parsing malformed headers', function (string $driver): void {
    $mailbox = createTestMailbox('Mismatched recovery', 'reader@example.com', 'Reader', $driver);
    $payload = $driver === 'gmail'
        ? ['id' => 'different', 'payload' => ['headers' => [['name' => 'Cc', 'value' => 'Name only']], 'parts' => []]]
        : ['id' => 'different', 'ccRecipients' => [['emailAddress' => ['name' => 'Name only']]]];
    expectMailboxFacadeForMailbox($mailbox, syncResultResource($driver, [$payload]));

    expect(fn () => (new MessageSyncService)->syncMailboxMessage($mailbox, 'expected'))
        ->toThrow(MailboxException::class, 'mismatched message identity');
})->with(['ms-graph', 'gmail']);

it('requires exact-read support for targeted recovery on custom resources', function (): void {
    $mailbox = createTestMailbox('Custom exact read', 'reader@example.com', 'Reader', 'custom');
    $message = testMailboxMessageDto('expected', null, null, false);
    $resource = new TestMailboxResource(new TestMessageQueryBuilder(collect()), [
        'expected' => new TestMessageResource($message, collect(), []),
    ]);
    expectMailboxFacadeForMailbox($mailbox, $resource);

    expect(fn () => (new MessageSyncService)->syncMailboxMessage($mailbox, 'expected'))
        ->toThrow(UnsupportedCapabilityException::class);
});

it('preserves operational folder and attachment not-found failures during targeted recovery', function (string $failure): void {
    $mailbox = createTestMailbox('Operational recovery', 'reader@example.com', 'Reader');
    $responses = [['id' => 'expected', 'receivedDateTime' => '2024-01-01T12:00:00Z', 'hasAttachments' => $failure !== 'folder']];
    if ($failure === 'stream') {
        $responses[] = ['value' => [['id' => 'file', 'name' => 'invoice.pdf', 'contentType' => 'application/pdf', 'size' => 4]]];
    }
    $responses[] = new Response(404, [], '{}');
    expectMailboxFacadeForMailbox($mailbox, syncResultResource('ms-graph', $responses));

    try {
        (new MessageSyncService)->syncMailboxMessage($mailbox, 'expected', $failure === 'folder' ? ['folder' => 'gone'] : []);
        test()->fail('The operational failure must propagate.');
    } catch (ResourceNotFoundException $exception) {
        expect($exception->resourceType)->toBe('resource')
            ->and($exception->resourceId)->not->toBe('expected');
    }
})->with(['folder', 'metadata', 'stream']);

it('preserves stored attachment bytes when authority changes during a replacement download', function (): void {
    $mailbox = createTestMailbox('Download fence', 'reader@example.com', 'Reader');
    $message = testMailboxMessageDto('guarded', null, 'inbox', true);
    $attachment = new AttachmentDto('file', 'invoice.pdf', 'application/pdf', 4, false, null);
    $allowed = true;
    $downloads = 0;
    $resource = new TestMailboxResource(new TestMessageQueryBuilder(collect([$message])), [
        'guarded' => new TestMessageResource($message, collect([$attachment]), ['file' => function () use (&$allowed, &$downloads): StreamInterface {
            if (++$downloads > 1) {
                $allowed = false;
            }

            return Utils::streamFor($downloads === 1 ? 'original' : 'replacement');
        }]),
    ]);
    expectMailboxFacadeForMailbox($mailbox, $resource, $resource);
    $service = new MessageSyncService;
    $original = $service->syncMailbox($mailbox)->sole();
    $syncedAt = $mailbox->fresh()->last_synced_at;

    expect(function () use ($service, $mailbox, &$allowed): void {
        $service->syncMailboxResult($mailbox, [], function (Closure $write) use (&$allowed): mixed {
            if (! $allowed) {
                throw new RuntimeException('Binding changed during download');
            }

            return $write();
        });
    })->toThrow(RuntimeException::class, 'Binding changed during download');
    expect($original->fresh(['attachments'])->attachments->sole()->content_bytes)->toBe(base64_encode('original'))
        ->and($mailbox->fresh()->last_synced_at->equalTo($syncedAt))->toBeTrue();
});

/**
 * @param  list<array<string, mixed>|Response|Throwable>  $responses
 * @param  array<int, mixed>|null  $history
 */
function syncResultResource(string $driver, array $responses, ?array &$history = null): MailboxResource
{
    $handler = new MockHandler(array_map(static fn (array|Response|Throwable $response): Response|Throwable => is_array($response)
        ? new Response(200, [], json_encode($response, JSON_THROW_ON_ERROR)) : $response, $responses));
    $stack = HandlerStack::create($handler);
    if ($history !== null) {
        $stack->push(Middleware::history($history));
    }
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['reader@example.com'], 'token' => 'selected-token'],
    ]);

    return MailboxFacade::forGrant(new GrantContext('connection', 'grant', '1', $driver, 'reader@example.com'), $tokens,
        new Client(['handler' => $stack, 'base_uri' => $driver === 'gmail' ? 'https://gmail.googleapis.com/gmail/v1/' : 'https://graph.microsoft.com/v1.0/']));
}

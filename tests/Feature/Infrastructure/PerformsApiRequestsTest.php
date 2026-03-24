<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Pyle\Mailbox\Drivers\Concerns\PerformsApiRequests;
use Pyle\Mailbox\Exceptions\ApiRequestException;

it('sends json and query requests through the shared api request pipeline', function (): void {
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode(['value' => ['a']])),
        new Response(200, [], json_encode(['created' => true])),
        new Response(204, [], ''),
        new Response(204, [], ''),
    ]));
    $handler->push(Middleware::history($history));

    $client = new Client([
        'handler' => $handler,
        'base_uri' => 'https://api.example.test/v1/',
    ]);

    $api = new TestPerformsApiRequestsClient(['queue_retry_strategy' => 'sleep'], $client);

    expect($api->get('messages', ['top' => 1], 'Inbox@example.com'))->toBe(['value' => ['a']]);
    expect($api->post('messages', ['subject' => 'Hello'], 'Inbox@example.com'))->toBe(['created' => true]);
    expect($api->patch('messages/1', ['isRead' => true], 'Inbox@example.com'))->toBe([]);
    $api->delete('messages/1', 'Inbox@example.com');

    expect($history)->toHaveCount(4);
    expect($history[0]['request']->getMethod())->toBe('GET');
    expect((string) $history[0]['request']->getUri())->toContain('/v1/messages?top=1');
    expect($history[0]['request']->getHeaderLine('X-Mailbox'))->toBe('inbox@example.com');
    expect($history[1]['request']->getMethod())->toBe('POST');
    expect((string) $history[1]['request']->getBody())->toContain('"subject":"Hello"');
    expect($history[2]['request']->getMethod())->toBe('PATCH');
    expect($history[3]['request']->getMethod())->toBe('DELETE');
});

it('returns stream bodies and preserves absolute download urls', function (): void {
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
        new Response(200, [], 'stream-body'),
    ]));
    $handler->push(Middleware::history($history));

    $client = new Client([
        'handler' => $handler,
        'base_uri' => 'https://api.example.test/v1/',
    ]);

    $api = new TestPerformsApiRequestsClient(['queue_retry_strategy' => 'sleep'], $client);
    $stream = $api->stream('https://downloads.example.test/files/report.pdf', 'Inbox@example.com');

    expect((string) $stream)->toBe('stream-body');
    expect((string) $history[0]['request']->getUri())->toBe('https://downloads.example.test/files/report.pdf');
});

it('exposes retry helpers for retry-after parsing, backoff calculation, and queue release', function (): void {
    $api = new TestPerformsApiRequestsClient(['queue_retry_strategy' => 'release', 'retry_backoff_base' => 3], new Client);

    $request = new Request('GET', 'https://api.example.test/v1/messages');
    $response = new Response(429, ['Retry-After' => '7']);
    $exception = new RequestException('rate limited', $request, $response);

    expect($api->retryAfterFrom($exception))->toBe(7);
    expect($api->backoffFor(3))->toBe(9);
    expect($api->triggerQueueRetry(5, 'rate limit'))->toBeFalse();

    $state = (object) ['releasedDelay' => null];
    app()->instance('queue.job', new TestQueueJob($state));

    expect($api->triggerQueueRetry(5, 'rate limit'))->toBeTrue();
    expect($state->releasedDelay)->toBe(5);

    app()->forgetInstance('queue.job');
});

final class TestPerformsApiRequestsClient
{
    use PerformsApiRequests;

    public TestPerformsApiRequestsRateLimiter $rateLimiter;

    /** @var array<string, mixed> */
    protected array $config;

    public function __construct(array $config, ?Client $client = null)
    {
        $this->config = $config;
        $this->rateLimiter = new TestPerformsApiRequestsRateLimiter;
        $this->bootApiClient($config, $client, 'https://api.example.test/v1/');
    }

    public function retryAfterFrom(RequestException $exception): int
    {
        return $this->retryAfterSeconds($exception);
    }

    public function backoffFor(int $attempt): int
    {
        return $this->backoffSeconds($attempt);
    }

    public function triggerQueueRetry(int $delaySeconds, string $reason): bool
    {
        return $this->handleQueueRetry($delaySeconds, $reason);
    }

    protected function driverKey(): string
    {
        return 'test-driver';
    }

    protected function providerLabel(): string
    {
        return 'Test API';
    }

    protected function mailboxKey(?string $mailbox): string
    {
        return strtolower(trim((string) ($mailbox ?? 'global')));
    }

    protected function buildRequestOptions(array $options, string $mailboxKey): array
    {
        return array_merge_recursive($options, [
            'headers' => [
                'X-Mailbox' => $mailboxKey,
            ],
        ]);
    }

    protected function shouldRetryRequest(
        RequestException $e,
        string $method,
        string $endpoint,
        ?string $mailbox,
        string $mailboxKey,
        int $attempt,
        int $durationMs,
        bool &$reauthAttempted,
    ): bool {
        return false;
    }

    protected function throwMappedException(
        RequestException $e,
        ?string $mailbox,
        string $mailboxKey,
        string $endpoint,
        int $attempt,
    ): never {
        throw new ApiRequestException($e->getMessage(), endpoint: $endpoint, status: $e->getResponse()?->getStatusCode(), previous: $e);
    }

    protected function wrapUnexpectedThrowable(
        Throwable $e,
        string $method,
        string $endpoint,
        ?string $mailbox,
        string $mailboxKey,
        int $attempt,
    ): ApiRequestException {
        return new ApiRequestException($e->getMessage(), endpoint: $endpoint, previous: $e);
    }
}

final class TestPerformsApiRequestsRateLimiter
{
    public function forMailbox(string $driver, string $mailbox, callable $callback): ResponseInterface
    {
        return $callback();
    }
}

final class TestQueueJob implements \Illuminate\Contracts\Queue\Job
{
    public function __construct(private object $state) {}

    public function uuid(): ?string
    {
        return null;
    }

    public function getJobId(): string
    {
        return '1';
    }

    public function payload(): array
    {
        return [];
    }

    public function fire(): void {}

    public function release($delay = 0): void
    {
        $this->state->releasedDelay = (int) $delay;
    }

    public function isReleased(): bool
    {
        return $this->state->releasedDelay !== null;
    }

    public function delete(): void {}

    public function isDeleted(): bool
    {
        return false;
    }

    public function isDeletedOrReleased(): bool
    {
        return false;
    }

    public function attempts(): int
    {
        return 1;
    }

    public function hasFailed(): bool
    {
        return false;
    }

    public function markAsFailed(): void {}

    public function fail($e = null): void {}

    public function maxTries(): ?int
    {
        return null;
    }

    public function maxExceptions(): ?int
    {
        return null;
    }

    public function timeout(): ?int
    {
        return null;
    }

    public function retryUntil(): int|string|null
    {
        return null;
    }

    public function getName(): string
    {
        return 'fake';
    }

    public function resolveName(): string
    {
        return 'fake';
    }

    public function resolveQueuedJobClass(): ?string
    {
        return null;
    }

    public function getConnectionName(): string
    {
        return 'sync';
    }

    public function getQueue(): string
    {
        return 'default';
    }

    public function getRawBody(): string
    {
        return '{}';
    }
}

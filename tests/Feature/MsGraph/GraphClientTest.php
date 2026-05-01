<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Event;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\RateLimiter;
use Pyle\Mailbox\Drivers\MsGraph\TokenManager;
use Pyle\Mailbox\Events\RateLimitHit;
use Pyle\Mailbox\Exceptions\MailboxAccessDeniedException;
use Pyle\Mailbox\Exceptions\RateLimitException;

it('retries on 429 then succeeds', function (): void {
    Event::fake([RateLimitHit::class]);

    $handler = new MockHandler([
        new Response(429, ['Retry-After' => '1'], ''),
        new Response(200, [], json_encode(['value' => []])),
    ]);

    $client = new Client(['handler' => HandlerStack::create($handler), 'base_uri' => 'https://graph.microsoft.com/v1.0/']);

    $token = new class([]) extends TokenManager
    {
        public function __construct(array $config = [])
        {
            parent::__construct($config);
        }

        public function getToken(bool $forceRefresh = false): string
        {
            return 'token';
        }

        public function invalidateToken(): void {}
    };

    $rateLimiter = new class([]) extends RateLimiter
    {
        public function __construct(array $config = [])
        {
            parent::__construct($config);
        }

        public function forMailbox(string $driver, string $mailbox, callable $callback): Response
        {
            return $callback();
        }
    };

    $graph = new GraphClient(['max_retries' => 2, 'queue_retry_strategy' => 'sleep'], $token, $rateLimiter, $client);

    expect($graph->get('users/test/messages'))->toHaveKey('value');
    Event::assertDispatched(RateLimitHit::class);
});

it('retries graph transport failures then succeeds', function (): void {
    $handler = new MockHandler([
        new ConnectException('Connection reset by peer', new Request('GET', 'https://graph.microsoft.com/v1.0/users/test/messages')),
        new Response(200, [], json_encode(['value' => []])),
    ]);

    $client = new Client(['handler' => HandlerStack::create($handler), 'base_uri' => 'https://graph.microsoft.com/v1.0/']);

    $token = new class([]) extends TokenManager
    {
        public function __construct(array $config = [])
        {
            parent::__construct($config);
        }

        public function getToken(bool $forceRefresh = false): string
        {
            return 'token';
        }

        public function invalidateToken(): void {}
    };

    $rateLimiter = new class([]) extends RateLimiter
    {
        public function __construct(array $config = [])
        {
            parent::__construct($config);
        }

        public function forMailbox(string $driver, string $mailbox, callable $callback): Response
        {
            return $callback();
        }
    };

    $graph = new GraphClient([
        'max_retries' => 2,
        'queue_retry_strategy' => 'sleep',
        'retry_backoff_base' => 0,
    ], $token, $rateLimiter, $client);

    expect($graph->get('users/test/messages'))->toHaveKey('value');
});

it('throws mailbox access denied for 403', function (): void {
    $handler = new MockHandler([
        new Response(403, [], ''),
    ]);

    $client = new Client(['handler' => HandlerStack::create($handler), 'base_uri' => 'https://graph.microsoft.com/v1.0/']);

    $token = new class([]) extends TokenManager
    {
        public function __construct(array $config = [])
        {
            parent::__construct($config);
        }

        public function getToken(bool $forceRefresh = false): string
        {
            return 'token';
        }

        public function invalidateToken(): void {}
    };

    $rateLimiter = new class([]) extends RateLimiter
    {
        public function __construct(array $config = [])
        {
            parent::__construct($config);
        }

        public function forMailbox(string $driver, string $mailbox, callable $callback): Response
        {
            return $callback();
        }
    };

    $graph = new GraphClient(['max_retries' => 1], $token, $rateLimiter, $client);

    $graph->get('users/test/messages', mailbox: 'test@example.com');
})->throws(MailboxAccessDeniedException::class);

it('releases queue jobs instead of sleeping when configured', function (): void {
    $handler = new MockHandler([
        new Response(429, ['Retry-After' => '3'], ''),
    ]);

    $client = new Client(['handler' => HandlerStack::create($handler), 'base_uri' => 'https://graph.microsoft.com/v1.0/']);

    $token = new class([]) extends TokenManager
    {
        public function __construct(array $config = [])
        {
            parent::__construct($config);
        }

        public function getToken(bool $forceRefresh = false): string
        {
            return 'token';
        }

        public function invalidateToken(): void {}
    };

    $rateLimiter = new class([]) extends RateLimiter
    {
        public function __construct(array $config = [])
        {
            parent::__construct($config);
        }

        public function forMailbox(string $driver, string $mailbox, callable $callback): Response
        {
            return $callback();
        }
    };

    $state = (object) ['releasedDelay' => null];
    $job = new class($state) implements Job
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
    };

    app()->instance('queue.job', $job);

    $graph = new GraphClient(['max_retries' => 2, 'queue_retry_strategy' => 'release'], $token, $rateLimiter, $client);

    expect(fn () => $graph->get('users/test/messages', mailbox: 'test@example.com'))
        ->toThrow(RateLimitException::class);

    expect($state->releasedDelay)->toBe(3);

    app()->forgetInstance('queue.job');
});

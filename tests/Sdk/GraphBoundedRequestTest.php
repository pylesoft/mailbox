<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\Drivers\MsGraph\BatchRequest;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphMessageQuery;
use Pyle\Mailbox\Drivers\MsGraph\RateLimiter;
use Pyle\Mailbox\Drivers\MsGraph\TokenManager;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\ProviderTransportException;
use Pyle\Mailbox\Support\ResponseBudget;

it('holds the Graph slot through the response read and close beyond the admission timeout', function (string $operation, string $payload): void {
    $this->freezeTime();
    $mailbox = 'reader@example.com';
    $endpoint = 'users/reader%40example.com/messages/message';
    $slot = 'mailbox:lock:ms-graph:'.$mailbox.':slot:1';
    $blockedWhileReading = null;
    $blockedWhileClosing = null;
    $observeRead = function () use ($slot, &$blockedWhileReading): void {
        if ($blockedWhileReading === null) {
            $this->travel(31)->seconds();
            $blockedWhileReading = ! Cache::lock($slot, 1)->get();
        }
    };
    $observeClose = static function () use ($slot, &$blockedWhileClosing): void {
        $blockedWhileClosing = ! Cache::lock($slot, 1)->get();
    };
    $body = new class(Utils::streamFor($payload), $observeRead, $observeClose) implements StreamInterface
    {
        use StreamDecoratorTrait;

        public function __construct(
            private StreamInterface $stream,
            private Closure $onRead,
            private Closure $onClose,
        ) {}

        public function read(int $length): string
        {
            ($this->onRead)();

            return $this->stream->read($length);
        }

        public function close(): void
        {
            ($this->onClose)();
            $this->stream->close();
        }
    };
    $history = [];
    $graph = lifetimeGraphClient([new Response(200, [], $body)], $history);

    $budget = new ResponseBudget(1024);
    match ($operation) {
        'JSON' => $graph->getBoundedObject($endpoint, $budget, mailbox: $mailbox),
        'attachment' => $graph->streamBounded($endpoint.'/$value', $budget, 1024, $mailbox)->close(),
        'MIME' => $graph->messageHeaderBlock($endpoint, $budget, $mailbox),
        'one attempt' => $graph->requestOnce('GET', $endpoint, mailbox: $mailbox),
    };

    expect($blockedWhileReading)->toBeTrue()
        ->and($blockedWhileClosing)->toBeTrue()
        ->and(Cache::lock($slot, 1)->get())->toBeTrue();
})->with([
    'bounded JSON' => ['JSON', '{}'],
    'bounded attachment' => ['attachment', 'file bytes'],
    'MIME headers' => ['MIME', "Subject: test\r\n\r\nbody"],
    'one-attempt GET' => ['one attempt', '{}'],
]);

it('releases the Graph slot before backing off after a failed read', function (bool $bounded): void {
    $mailbox = 'reader@example.com';
    $endpoint = 'users/reader%40example.com/messages/message';
    $history = [];
    $graph = lifetimeGraphClient([new Response(502, [], '{}'), new Response(200, [], '{}')], $history, ['max_retries' => 1]);
    $availableDuringBackoff = null;

    Event::listen(MessageLogged::class, function (MessageLogged $log) use ($mailbox, &$availableDuringBackoff): void {
        if ($log->message === 'Graph server error; applying retry backoff') {
            $slot = Cache::lock('mailbox:lock:ms-graph:'.$mailbox.':slot:1', 1);
            $availableDuringBackoff = $slot->get();
            $slot->release();
        }
    });

    if ($bounded) {
        $graph->getBoundedObject($endpoint, new ResponseBudget(1024), mailbox: $mailbox, retry: true);
    } else {
        $graph->get($endpoint, mailbox: $mailbox);
    }

    expect($availableDuringBackoff)->toBeTrue()
        ->and($history)->toHaveCount(2);
})->with(['bounded' => [true], 'buffered' => [false]]);

it('closes a Graph stream and releases its slot when a body read exceeds the deadline', function (): void {
    $mailbox = 'reader@example.com';
    $source = Utils::streamFor('{}');
    $closed = false;
    $body = FnStream::decorate($source, [
        'read' => function (int $length) use ($source): string {
            usleep(1_050_000);

            return $source->read($length);
        },
        'close' => function () use ($source, &$closed): void {
            $closed = true;
            $source->close();
        },
    ]);
    $history = [];
    $graph = lifetimeGraphClient([new Response(200, [], $body)], $history, ['timeout' => 1]);

    expect(fn () => $graph->getBoundedObject('users/reader%40example.com/messages/message', new ResponseBudget(1024), mailbox: $mailbox))
        ->toThrow(ProviderTransportException::class, 'The provider response exceeded its read deadline.')
        ->and($closed)->toBeTrue()
        ->and(Cache::lock('mailbox:lock:ms-graph:'.$mailbox.':slot:1', 1)->get())->toBeTrue()
        ->and($history[0]['options']['timeout'])->toBe(1)
        ->and($history[0]['options']['read_timeout'])->toBe(1);
});

it('retries a malformed successful Graph page without losing the typed exhaustion signal', function (bool $recovers): void {
    $history = [];
    $graph = lifetimeGraphClient([
        new Response(200, [], '<html>provider error'),
        new Response(200, [], $recovers ? '{"value":[]}' : '<html>provider error'),
    ], $history, ['max_retries' => 1]);
    $query = new MsGraphMessageQuery($graph, new BatchRequest($graph), 'reader@example.com');

    if ($recovers) {
        expect($query->getResult()->messages)->toHaveCount(0);
    } else {
        expect($query->getResult(...))->toThrow(ProviderTransportException::class, 'Graph transport failure after 2 attempts');
    }

    expect($history)->toHaveCount(2);
})->with([true, false]);

it('rejects a Graph page with valid JSON of the wrong shape without replaying it', function (): void {
    $history = [];
    $graph = lifetimeGraphClient([new Response(200, [], '{"value":{}}')], $history);
    $query = new MsGraphMessageQuery($graph, new BatchRequest($graph), 'reader@example.com');

    expect($query->getResult(...))->toThrow(ApiRequestException::class, 'The provider returned a missing or malformed collection.')
        ->and($history)->toHaveCount(1);
});

it('uses the supplied rate limiter for bounded and buffered Graph reads', function (string $mode): void {
    $limiter = new class([]) extends RateLimiter
    {
        public int $calls = 0;

        public function forMailbox(string $driver, string $mailbox, callable $callback): ResponseInterface
        {
            $this->calls++;

            return new Response(200, [], '{"custom":true}');
        }
    };
    $history = [];
    $graph = lifetimeGraphClient([], $history, limiter: $limiter);
    $endpoint = 'users/reader%40example.com/messages/message';
    $mailbox = 'reader@example.com';

    $result = match ($mode) {
        'bounded' => (array) $graph->getBoundedObject($endpoint, new ResponseBudget(1024), mailbox: $mailbox),
        'buffered' => $graph->get($endpoint, mailbox: $mailbox),
        'one attempt' => json_decode($graph->requestOnce('GET', $endpoint, mailbox: $mailbox)->body(), true, flags: JSON_THROW_ON_ERROR),
    };

    expect($result)->toBe(['custom' => true])->and($limiter->calls)->toBe(1)->and($history)->toBe([]);
})->with(['bounded', 'buffered', 'one attempt']);

it('does not reread a consumed Graph body when the limiter clones the response', function (): void {
    $limiter = new class([]) extends RateLimiter
    {
        public function forMailbox(string $driver, string $mailbox, callable $callback): ResponseInterface
        {
            return parent::forMailbox($driver, $mailbox, $callback)->withHeader('X-Custom', 'test');
        }
    };
    $history = [];
    $graph = lifetimeGraphClient([new Response(200, [], '{"value":[]}')], $history, limiter: $limiter);
    $budget = new ResponseBudget(1024);

    expect($graph->getBoundedObject('users/reader%40example.com/messages', $budget, mailbox: 'reader@example.com')->value)
        ->toBe([])
        ->and($budget->remaining())->toBe(1012)
        ->and($history)->toHaveCount(1);
});

it('returns a failed one-attempt Graph read without replay', function (): void {
    $history = [];
    $graph = lifetimeGraphClient([new Response(502, ['Retry-After' => '60'], '{}'), new Response(200, [], '{}')], $history);
    $response = $graph->requestOnce('GET', 'users/reader%40example.com/messages/message', mailbox: 'reader@example.com');

    expect($response->status)->toBe(502)
        ->and($response->headers['retry-after'])->toBe(['60'])
        ->and($history)->toHaveCount(1);
});

it('uses a finite timeout for buffered Graph requests that disable the HTTP timeout', function (): void {
    $history = [];
    $graph = lifetimeGraphClient([new Response(200, [], '{}')], $history, ['timeout' => 7]);

    $graph->request('GET', 'users/reader%40example.com/messages/message', ['timeout' => 0], 'reader@example.com');

    expect($history[0]['options']['timeout'])->toBe(7);
});

/**
 * @param  list<Response>  $responses
 * @param  array<int, array<string, mixed>>  $history
 * @param  array<string, mixed>  $config
 */
function lifetimeGraphClient(array $responses, array &$history, array $config = [], ?RateLimiter $limiter = null): GraphClient
{
    $config += ['max_concurrent_per_mailbox' => 1, 'concurrency_lock_timeout' => 30];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $tokens = new class([]) extends TokenManager
    {
        public function getToken(bool $forceRefresh = false): string
        {
            return 'test-token';
        }
    };

    return new GraphClient($config, $tokens, $limiter ?? new RateLimiter($config), new Client(['handler' => $stack]));
}

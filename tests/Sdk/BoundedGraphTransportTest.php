<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Pyle\Mailbox\Drivers\MsGraph\BoundedGraphTransport;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\RateLimiter;
use Pyle\Mailbox\Drivers\MsGraph\TokenManager;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\ProviderServerException;
use Pyle\Mailbox\Exceptions\ProviderTransportException;
use Pyle\Mailbox\Exceptions\RateLimitException;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;
use Pyle\Mailbox\Support\GrantAuthorization;
use Pyle\Mailbox\Support\ResponseBudget;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;
use Symfony\Component\Process\Process;

it('enforces a total Graph timeout even while the server drips response headers', function (): void {
    $server = graphReadServer('drip');
    $transport = new BoundedGraphTransport;
    $started = hrtime(true);
    try {
        expect(fn () => (new Client)->get(graphReadServerUrl($server), $transport->options(['timeout' => 1, 'proxy' => ''], 1024, null)))
            ->toThrow(function (ConnectException $exception): void {
                expect($exception->getHandlerContext()['errno'] ?? null)->toBe(CURLE_OPERATION_TIMEDOUT);
            });
        expect((hrtime(true) - $started) / 1_000_000_000)->toBeLessThan(4.0);
    } finally {
        $server->stop(0);
    }
})->skip(! extension_loaded('curl'), 'cURL is unavailable');

it('stops the Graph transfer at the byte limit and accounts for received bytes', function (): void {
    $server = graphReadServer('oversize');
    $budget = new ResponseBudget(16);
    try {
        $transport = new BoundedGraphTransport;
        expect(fn () => (new Client)->get(graphReadServerUrl($server), $transport->options(['timeout' => 2, 'proxy' => ''], $budget->remaining(), $budget)))
            ->toThrow(ResponseLimitExceededException::class)
            ->and($budget->remaining())->toBe(0);
    } finally {
        $server->stop(0);
    }
})->skip(! extension_loaded('curl'), 'cURL is unavailable');

it('preserves status evidence when an exchange response exceeds its byte limit', function (): void {
    $server = graphReadServer('oversize');
    try {
        $transport = new BoundedGraphTransport;
        $response = (new Client)->get(graphReadServerUrl($server), $transport->options(['timeout' => 2, 'proxy' => ''], 16, null, allowTruncated: true));

        expect($response->getStatusCode())->toBe(200)
            ->and((string) $response->getBody())->toBe(str_repeat('x', 17));
        $response->getBody()->close();
    } finally {
        $server->stop(0);
    }
})->skip(! extension_loaded('curl'), 'cURL is unavailable');

it('returns the MIME header block without waiting for the rest of the message', function (): void {
    $server = graphReadServer('mime');
    try {
        $transport = new BoundedGraphTransport;
        $response = (new Client)->get(graphReadServerUrl($server), $transport->options(['timeout' => 2, 'proxy' => ''], 32, null, mimeHeadersOnly: true));

        expect((string) $response->getBody())->toBe("Subject: test\r\n\r\n");
        $response->getBody()->close();
    } finally {
        $server->stop(0);
    }
})->skip(! extension_loaded('curl'), 'cURL is unavailable');

it('classifies an incomplete successful Graph response as a transport failure', function (bool $batch): void {
    $server = graphReadServer('partial');
    $mailbox = 'reader@example.com';
    $budget = new ResponseBudget(1024);
    try {
        $graph = loopbackBoundedGraphClient($server, $mailbox);
        $endpoint = 'users/reader%40example.com/mailFolders/inbox/messages/delta';
        $read = $batch
            ? fn () => $graph->getBoundedDeltaBatch([$endpoint.'?$deltatoken=private-cursor'], $budget, 10, $mailbox)
            : fn () => $graph->getBoundedObject($endpoint.'?$deltatoken=private-cursor', $budget, mailbox: $mailbox);

        expect($read)->toThrow(function (ProviderTransportException $failure) use ($batch, $endpoint, $mailbox): void {
            expect($failure->endpoint)->toBe($batch ? '/$batch' : $endpoint)
                ->and($failure->mailbox)->toBe($mailbox)
                ->and($failure->getMessage())->not->toContain('private-cursor')
                ->and($failure->getPrevious())->toBeNull();
        })->and($budget->remaining())->toBe(1022);
    } finally {
        $server->stop(0);
    }
})->with(['GET' => false, 'read-only batch POST' => true])->skip(! extension_loaded('curl'), 'cURL is unavailable');

it('preserves Graph failure status without waiting for or charging ordinary error bodies', function (int $status): void {
    $server = graphReadServer((string) $status);
    $mailbox = 'reader@example.com';
    $budget = new ResponseBudget(16);
    try {
        $graph = loopbackBoundedGraphClient($server, $mailbox);
        $read = fn () => $graph->getBoundedObject('users/reader%40example.com/messages', $budget, mailbox: $mailbox);
        if ($status === 429) {
            expect($read)->toThrow(function (RateLimitException $failure): void {
                expect($failure->retryAfter)->toBe(60);
            });
        } else {
            expect($read)->toThrow(function (ProviderServerException $failure): void {
                $previous = $failure->getPrevious();
                expect($failure->statusCode)->toBe(503)
                    ->and($previous)->toBeInstanceOf(RequestException::class)
                    ->and($previous->getResponse()?->getHeaderLine('Retry-After'))->toBe('60');
            });
        }
        expect($budget->remaining())->toBe(16);
    } finally {
        $server->stop(0);
    }
})->with([429, 503])->skip(! extension_loaded('curl'), 'cURL is unavailable');

it('still reads bounded Graph sync reset errors', function (): void {
    $server = graphReadServer('reset');
    $mailbox = 'reader@example.com';
    $budget = new ResponseBudget(1024);
    try {
        $graph = loopbackBoundedGraphClient($server, $mailbox);

        expect(fn () => $graph->getBoundedDeltaPage('users/reader%40example.com/mailFolders/inbox/messages/delta', $budget, 10, $mailbox, false))
            ->toThrow(function (ApiRequestException $failure): void {
                expect($failure->status)->toBe(410)->and($failure->providerCode)->toBe('syncStateNotFound');
            });
        expect($budget->remaining())->toBeLessThan(1024);
    } finally {
        $server->stop(0);
    }
})->skip(! extension_loaded('curl'), 'cURL is unavailable');

it('retains one-attempt Graph status when an acknowledged body times out', function (): void {
    $server = graphReadServer('stalled');
    $mailbox = 'reader@example.com';
    try {
        $graph = loopbackBoundedGraphClient($server, $mailbox);
        $result = $graph->requestOnce('GET', 'users/reader%40example.com/messages', mailbox: $mailbox);

        expect($result->status)->toBe(200)
            ->and($result->headers)->toBe(['request-id' => ['local-request']])
            ->and($result->body())->toBeNull();
    } finally {
        $server->stop(0);
    }
})->skip(! extension_loaded('curl'), 'cURL is unavailable');

function loopbackBoundedGraphClient(Process $server, string $mailbox): GraphClient
{
    $config = ['timeout' => 1, 'max_concurrent_per_mailbox' => 1];
    $context = new GrantContext('connection', 'grant', '1', 'ms-graph', $mailbox);
    $tokens = new TestGrantTokenProvider(['grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => [$mailbox], 'token' => 'test-token']]);
    $client = new GraphClient($config, new TokenManager($config), new RateLimiter($config), grantAuthorization: new GrantAuthorization($context, $tokens));
    (new ReflectionProperty(GraphClient::class, 'apiBaseUri'))->setValue($client, graphReadServerUrl($server).'/');

    return $client;
}

function graphReadServer(string $mode): Process
{
    $code = <<<'PHP'
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        echo stream_socket_get_name($socket, false), "\n";
        $client = stream_socket_accept($socket, 20);
        if ($client === false) {
            exit(1);
        }
        while (($line = fgets($client)) !== false && $line !== "\r\n") {}
        if ($argv[1] === 'drip') {
            fwrite($client, "HTTP/1.1 200 OK\r\n");
            for ($i = 0; $i < 10; $i++) {
                if (@fwrite($client, "X-Drip-$i: pending\r\n") === false) {
                    exit(0);
                }
                usleep(600_000);
            }
            fwrite($client, "Content-Length: 0\r\n\r\n");
        } elseif ($argv[1] === 'mime') {
            fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 4096\r\n\r\nSubject: test\r\n\r\n" . str_repeat('x', 64));
            sleep(5);
        } elseif (in_array($argv[1], ['429', '503'], true)) {
            fwrite($client, "HTTP/1.1 {$argv[1]} Error\r\nRetry-After: 60\r\nContent-Length: 128\r\n\r\n".str_repeat('x', 64));
            sleep(5);
        } elseif ($argv[1] === 'reset') {
            $body = '{"error":{"code":"syncStateNotFound"}}';
            fwrite($client, "HTTP/1.1 410 Gone\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
        } elseif (in_array($argv[1], ['partial', 'stalled'], true)) {
            fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 30\r\nrequest-id: local-request\r\n\r\n{}");
            if ($argv[1] === 'stalled') {
                sleep(5);
            }
        } else {
            fwrite($client, "HTTP/1.1 200 OK\r\nConnection: close\r\n\r\n" . str_repeat('x', 128));
        }
        fclose($client);
        fclose($socket);
        PHP;

    $server = new Process([PHP_BINARY, '-r', $code, $mode]);
    $server->setTimeout(30);
    $server->start();
    $server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "\n"));

    return $server;
}

function graphReadServerUrl(Process $server): string
{
    $address = trim($server->getOutput());
    expect($address)->toMatch('/\A127\.0\.0\.1:[0-9]+\z/');

    return 'http://'.$address.'/bounded-read';
}

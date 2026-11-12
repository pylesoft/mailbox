<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Pyle\Mailbox\Drivers\MsGraph\BoundedGraphTransport;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;
use Pyle\Mailbox\Support\ResponseBudget;
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

function graphReadServer(string $mode): Process
{
    $code = <<<'PHP'
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        echo stream_socket_get_name($socket, false), "\n";
        $client = stream_socket_accept($socket, 5);
        if ($client === false) {
            exit(1);
        }
        while (($line = fgets($client)) !== false && $line !== "\r\n") {}
        fwrite($client, "HTTP/1.1 200 OK\r\n");
        if ($argv[1] === 'drip') {
            for ($i = 0; $i < 10; $i++) {
                if (@fwrite($client, "X-Drip-$i: pending\r\n") === false) {
                    exit(0);
                }
                usleep(600_000);
            }
            fwrite($client, "Content-Length: 0\r\n\r\n");
        } elseif ($argv[1] === 'mime') {
            fwrite($client, "Content-Length: 4096\r\n\r\nSubject: test\r\n\r\n" . str_repeat('x', 64));
            sleep(5);
        } else {
            fwrite($client, "Connection: close\r\n\r\n" . str_repeat('x', 128));
        }
        fclose($client);
        fclose($socket);
        PHP;

    $server = new Process([PHP_BINARY, '-r', $code, $mode]);
    $server->setTimeout(10);
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

<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\RateLimiter;
use Pyle\Mailbox\Drivers\MsGraph\TokenManager;
use Pyle\Mailbox\Exceptions\MailboxAccessDeniedException;

it('retries on 429 then succeeds', function (): void {
    $handler = new MockHandler([
        new Response(429, ['Retry-After' => '1'], ''),
        new Response(200, [], json_encode(['value' => []])),
    ]);

    $client = new Client(['handler' => HandlerStack::create($handler), 'base_uri' => 'https://graph.microsoft.com/v1.0/']);

    $token = new class([]) extends TokenManager {
        public function __construct(array $config = []) { parent::__construct($config); }
        public function getToken(bool $forceRefresh = false): string { return 'token'; }
        public function invalidateToken(): void {}
    };

    $rateLimiter = new class([]) extends RateLimiter {
        public function __construct(array $config = []) { parent::__construct($config); }
        public function forMailbox(string $mailbox, callable $callback): mixed { return $callback(); }
    };

    $graph = new GraphClient(['max_retries' => 2], $token, $rateLimiter, $client);

    expect($graph->get('users/test/messages'))->toHaveKey('value');
});

it('throws mailbox access denied for 403', function (): void {
    $handler = new MockHandler([
        new Response(403, [], ''),
    ]);

    $client = new Client(['handler' => HandlerStack::create($handler), 'base_uri' => 'https://graph.microsoft.com/v1.0/']);

    $token = new class([]) extends TokenManager {
        public function __construct(array $config = []) { parent::__construct($config); }
        public function getToken(bool $forceRefresh = false): string { return 'token'; }
        public function invalidateToken(): void {}
    };

    $rateLimiter = new class([]) extends RateLimiter {
        public function __construct(array $config = []) { parent::__construct($config); }
        public function forMailbox(string $mailbox, callable $callback): mixed { return $callback(); }
    };

    $graph = new GraphClient(['max_retries' => 1], $token, $rateLimiter, $client);

    $graph->get('users/test/messages', mailbox: 'test@example.com');
})->throws(MailboxAccessDeniedException::class);

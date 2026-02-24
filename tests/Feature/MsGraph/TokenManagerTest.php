<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Pyle\Mailbox\Drivers\MsGraph\TokenManager;

it('acquires and caches token', function (): void {
    Cache::flush();

    $history = [];
    $handler = new MockHandler([
        new Response(200, [], json_encode([
            'access_token' => 'test-token',
            'expires_in' => 3600,
        ])),
    ]);

    $stack = HandlerStack::create($handler);
    $stack->push(Middleware::history($history));

    $http = new Client(['handler' => $stack, 'base_uri' => 'https://login.microsoftonline.com/tenant/oauth2/v2.0/']);

    $manager = new TokenManager([
        'tenant_id' => 'tenant',
        'client_id' => 'client',
        'client_secret' => 'secret',
        'token_refresh_buffer' => 300,
    ], $http);

    expect($manager->getToken())->toBe('test-token');
    expect($manager->getToken())->toBe('test-token');
    expect($history)->toHaveCount(1);
});

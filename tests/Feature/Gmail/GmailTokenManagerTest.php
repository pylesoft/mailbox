<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Pyle\Mailbox\Drivers\Gmail\GmailTokenManager;

it('acquires and caches delegated gmail token per mailbox', function (): void {
    Cache::flush();

    if (! function_exists('openssl_pkey_new')) {
        $this->markTestSkipped('OpenSSL extension is required for delegated Gmail token tests.');
    }

    $history = [];
    $handler = new MockHandler([
        new Response(200, [], json_encode([
            'access_token' => 'gmail-token',
            'expires_in' => 3600,
        ])),
    ]);

    $stack = HandlerStack::create($handler);
    $stack->push(Middleware::history($history));

    $http = new Client(['handler' => $stack]);

    $privateKey = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);

    if ($privateKey === false) {
        $this->markTestSkipped('OpenSSL key generation is not available in this environment.');
    }

    $privateKeyPem = '';
    openssl_pkey_export($privateKey, $privateKeyPem);

    $serviceAccountJson = json_encode([
        'client_email' => 'svc@example.iam.gserviceaccount.com',
        'private_key' => $privateKeyPem,
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ], JSON_THROW_ON_ERROR);

    $manager = new GmailTokenManager([
        'service_account_json' => $serviceAccountJson,
        'token_uri' => 'https://oauth2.googleapis.com/token',
        'token_refresh_buffer' => 300,
    ], $http);

    expect($manager->getToken('invoices@example.com'))->toBe('gmail-token');
    expect($manager->getToken('invoices@example.com'))->toBe('gmail-token');
    expect($history)->toHaveCount(1);
});

<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Pyle\Mailbox\Drivers\Gmail\GmailClient;
use Pyle\Mailbox\Drivers\Gmail\GmailTokenManager;
use Pyle\Mailbox\Drivers\MsGraph\RateLimiter;
use Pyle\Mailbox\Exceptions\ApiRequestException;

/** @param array<int, mixed> $history */
function authenticatedGmailClient(array &$history): GmailClient
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $privateKey = '';
    openssl_pkey_export($key, $privateKey);
    $tokens = new GmailTokenManager(['service_account_json' => json_encode([
        'client_email' => 'transport@example.iam.gserviceaccount.com',
        'private_key' => $privateKey,
    ], JSON_THROW_ON_ERROR)], new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, [], '{"access_token":"private-gmail-bearer","expires_in":3600}'),
    ]))]));
    $handler = HandlerStack::create(new MockHandler([new Response(200, [], '{"messages":[]}')]));
    $handler->push(Middleware::history($history));

    return new GmailClient([], $tokens, new RateLimiter([]), new Client(['handler' => $handler]));
}

it('rejects a gmail URL that does not match the selected mailbox before HTTP', function (string $endpoint): void {
    $history = [];
    $gmail = authenticatedGmailClient($history);

    expect(fn () => $gmail->get($endpoint, mailbox: 'team@example.com'))
        ->toThrow(ApiRequestException::class, 'destination');
    expect($history)->toBe([]);
})->with([
    'another mailbox' => ['users/other%40example.com/messages'],
    'another resource' => ['projects/team/messages'],
    'foreign origin' => ['https://evil.example/gmail/v1/users/team%40example.com/messages'],
]);

it('allows gmail requests for the selected mailbox and its me alias', function (string $target): void {
    $history = [];
    $gmail = authenticatedGmailClient($history);

    expect($gmail->get('users/'.$target.'/messages', mailbox: 'team@example.com'))->toBe(['messages' => []]);
    expect((string) $history[0]['request']->getUri())->toBe('https://gmail.googleapis.com/gmail/v1/users/'.$target.'/messages')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer private-gmail-bearer');
})->with(['team%40example.com', 'me']);

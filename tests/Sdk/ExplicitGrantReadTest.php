<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Pyle\Mailbox\Contracts\GrantTokenProvider;
use Pyle\Mailbox\Drivers\Gmail\GmailClient;
use Pyle\Mailbox\Drivers\Gmail\GmailTokenManager;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphMailboxResource;
use Pyle\Mailbox\Drivers\MsGraph\RateLimiter;
use Pyle\Mailbox\Drivers\MsGraph\TokenManager;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\AuthenticationException;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\MailboxServiceProvider;
use Pyle\Mailbox\Support\GrantAuthorization;
use Pyle\Mailbox\Tests\Support\LegacyCustomDriver;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('reads a message using an explicit grant without package persistence', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{"id":"message-1","subject":"Selected grant"}'),
    ]));
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'base_uri' => 'https://graph.microsoft.com/v1.0/']);
    $context = new GrantContext('connection-a', 'grant-a', 'generation-1', 'ms-graph', 'shared@example.com');
    $tokens = new class implements GrantTokenProvider
    {
        public function assertActive(GrantContext $context): void {}

        public function accessToken(GrantContext $context): string
        {
            return 'selected-token';
        }
    };

    $message = Mailbox::forGrant($context, $tokens, $http)->message('message-1')->get();

    expect($message->id)->toBe('message-1')
        ->and($message->subject)->toBe('Selected grant')
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer selected-token')
        ->and((string) $history[0]['request']->getUri())->toBe('https://graph.microsoft.com/v1.0/users/shared%40example.com/messages/message-1')
        ->and(array_map('realpath', app('migrator')->paths()))->not->toContain(realpath(__DIR__.'/../../database/migrations'))
        ->and(config('database.default'))->toBe('sdk-forbidden');
});

it('isolates grants for two host contexts accessing the same account', function (string $driver): void {
    $history = [];
    $http = grantReadHttp($driver, [new Response(200, [], '{"id":"one"}'), new Response(200, [], '{"id":"two"}'), new Response(200, [], '{"id":"three"}')], $history);
    $tokens = new TestGrantTokenProvider([
        'grant-a' => ['connection' => 'connection-a', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'token-a'],
        'grant-b' => ['connection' => 'connection-b', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'token-b'],
    ]);
    $config = config('mailbox');
    $configuredDriver = Mailbox::driver($driver);
    $first = Mailbox::forGrant(new GrantContext('connection-a', 'grant-a', '1', $driver, 'same@example.com'), $tokens, $http);
    $second = Mailbox::forGrant(new GrantContext('connection-b', 'grant-b', '1', $driver, 'same@example.com'), $tokens, $http);

    expect($first->message('one')->get()->id)->toBe('one')
        ->and($second->message('two')->get()->id)->toBe('two')
        ->and($first->message('three')->get()->id)->toBe('three')
        ->and(array_map(fn (array $entry): string => $entry['request']->getHeaderLine('Authorization'), $history))->toBe(['Bearer token-a', 'Bearer token-b', 'Bearer token-a'])
        ->and(config('mailbox'))->toBe($config)
        ->and(Mailbox::driver($driver))->toBe($configuredDriver);
})->with(['gmail', 'ms-graph']);

it('uses the selected grant despite ordinary supplied client authentication', function (string $driver): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"id":"one"}')]));
    $stack->push(Middleware::history($history));
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'selected-token'],
    ]);
    $mailbox = Mailbox::forGrant(new GrantContext('connection', 'grant', '1', $driver, 'same@example.com'), $tokens,
        new Client(['handler' => $stack, 'auth' => ['unrelated-user', 'unrelated-password']]));

    expect($mailbox->message('one')->get()->id)->toBe('one')
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer selected-token');
})->with(['gmail', 'ms-graph']);

it('checks the captured lifecycle again before a read retry', function (string $driver): void {
    $history = [];
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'selected-token'],
    ]);
    $http = grantReadHttp($driver, [function () use ($tokens): Response {
        $tokens->grants['grant']['generation'] = '2';

        return new Response(503, [], '{"error":"temporary"}');
    }, new Response(200, [], '{"id":"forbidden-retry"}')], $history);
    $resource = Mailbox::forGrant(new GrantContext('connection', 'grant', '1', $driver, 'same@example.com'), $tokens, $http);

    expect(fn () => $resource->message('one')->get())->toThrow(GrantAccessDeniedException::class)
        ->and($history)->toHaveCount(1);
})->with(['gmail', 'ms-graph']);

it('keeps legacy custom creators and single config array constructors usable', function (): void {
    config()->set('mailbox.drivers.custom', ['driver' => 'custom']);
    config()->set('mailbox.driver_classes.custom', LegacyCustomDriver::class);
    Mailbox::extend('custom-creator', fn (): LegacyCustomDriver => new LegacyCustomDriver([]));
    $tokens = new TestGrantTokenProvider([]);

    foreach (['custom', 'custom-creator'] as $driver) {
        expect(Mailbox::driver($driver)->mailbox('same@example.com'))->toBeInstanceOf(MsGraphMailboxResource::class)
            ->and(fn () => Mailbox::forGrant(new GrantContext('connection', 'grant', '1', $driver, 'same@example.com'), $tokens))->toThrow(UnsupportedCapabilityException::class, 'does not support explicit grants');
    }
});

it('preserves the legacy migration registration default', function (): void {
    config()->set('mailbox.persistence', []);
    (new MailboxServiceProvider(app()))->boot();

    expect(array_map('realpath', app('migrator')->paths()))->toContain(realpath(__DIR__.'/../../database/migrations'));
});

it('rejects a context disconnected while its token is being loaded', function (string $driver): void {
    $history = [];
    $http = grantReadHttp($driver, [], $history);
    $tokens = new class implements GrantTokenProvider
    {
        private bool $connected = true;

        public function assertActive(GrantContext $context): void
        {
            if (! $this->connected) {
                throw new GrantAccessDeniedException('Disconnected during token loading.');
            }
        }

        public function accessToken(GrantContext $context): string
        {
            $this->connected = false;

            return 'old-token';
        }
    };

    expect(fn () => Mailbox::forGrant(new GrantContext('connection', 'grant', '1', $driver, 'same@example.com'), $tokens, $http)->message('one')->get())->toThrow(GrantAccessDeniedException::class)
        ->and($history)->toBe([]);
})->with(['gmail', 'ms-graph']);

it('refuses an absent or malformed supplied token before provider IO', function (string $token): void {
    $history = [];
    $http = grantReadHttp('ms-graph', [], $history);
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => $token],
    ]);

    expect(fn () => Mailbox::forGrant(new GrantContext('connection', 'grant', '1', 'ms-graph', 'same@example.com'), $tokens, $http)->message('one')->get())->toThrow(GrantAccessDeniedException::class)
        ->and($history)->toBe([]);
})->with(['', "token\r\nInjected: value"]);

it('requires every explicit authorization identity', function (string $missing): void {
    $values = ['connectionId' => 'connection', 'grantId' => 'grant', 'lifecycleGeneration' => '1', 'driver' => 'gmail', 'mailbox' => 'same@example.com'];
    $values[$missing] = ' ';

    expect(fn () => new GrantContext(...$values))->toThrow(InvalidArgumentException::class);
})->with(['connectionId', 'grantId', 'lifecycleGeneration', 'driver', 'mailbox']);

it('uses one selected grant for multiple permitted mailbox targets', function (string $driver): void {
    $history = [];
    $http = grantReadHttp($driver, [new Response(200, [], '{"id":"one"}'), new Response(200, [], '{"id":"two"}')], $history);
    $tokens = new TestGrantTokenProvider([
        'shared-grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['first@example.com', 'second@example.com'], 'token' => 'shared-token'],
    ]);

    foreach (['first@example.com', 'second@example.com'] as $mailbox) {
        Mailbox::forGrant(new GrantContext('connection', 'shared-grant', '1', $driver, $mailbox), $tokens, $http)->message('message')->get();
    }

    expect(array_map(fn (array $entry): string => $entry['request']->getHeaderLine('Authorization'), $history))->toBe(['Bearer shared-token', 'Bearer shared-token'])
        ->and($history[0]['request']->getUri()->getPath())->toContain('/users/first%40example.com/messages/message')
        ->and($history[1]['request']->getUri()->getPath())->toContain('/users/second%40example.com/messages/message');
})->with(['gmail', 'ms-graph']);

it('rejects retained resources after reconnect or disconnect before further provider IO', function (string $driver, string $change): void {
    $history = [];
    $http = grantReadHttp($driver, [new Response(200, [], '{"id":"one"}')], $history);
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'selected-token'],
    ]);
    $resource = Mailbox::forGrant(new GrantContext('connection', 'grant', '1', $driver, 'same@example.com'), $tokens, $http);
    $resource->message('one')->get();

    if ($change === 'reconnect') {
        $tokens->grants['grant']['generation'] = '2';
        $tokens->grants['grant']['token'] = 'replacement-token';
    } else {
        unset($tokens->grants['grant']);
    }

    expect(fn () => $resource->message('two')->get())->toThrow(GrantAccessDeniedException::class)
        ->and($history)->toHaveCount(1);
})->with(['gmail', 'ms-graph'])->with(['reconnect', 'disconnect']);

it('denies an unowned grant or unpermitted target without provider IO', function (string $driver, string $rejection): void {
    $history = [];
    $http = grantReadHttp($driver, [], $history);
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'owner', 'generation' => '1', 'mailboxes' => ['allowed@example.com'], 'token' => 'selected-token'],
    ]);
    $context = new GrantContext(
        $rejection === 'connection' ? 'other-owner' : 'owner',
        $rejection === 'grant' ? 'missing-grant' : 'grant',
        '1',
        $driver,
        $rejection === 'target' ? 'denied@example.com' : 'allowed@example.com',
    );

    expect(fn () => Mailbox::forGrant($context, $tokens, $http)->message('one')->get())->toThrow(GrantAccessDeniedException::class)
        ->and($history)->toBe([]);
})->with(['gmail', 'ms-graph'])->with(['connection', 'grant', 'target']);

it('does not fall back to configured authority after the selected grant is rejected', function (string $driver): void {
    $history = [];
    $http = grantReadHttp($driver, [new Response(401, [], '{"error":"invalid_token"}'), new Response(200, [], '{"id":"forbidden-fallback"}')], $history);
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'rejected-token'],
    ]);
    $resource = Mailbox::forGrant(new GrantContext('connection', 'grant', '1', $driver, 'same@example.com'), $tokens, $http);

    expect(fn () => $resource->message('one')->get())->toThrow(AuthenticationException::class)
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer rejected-token');
})->with(['gmail', 'ms-graph']);

it('keeps the optional client authorization seam bound to its selected target', function (string $driver, string $mismatch): void {
    $history = [];
    $http = grantReadHttp($driver, [], $history);
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'selected-token'],
    ]);
    $authorization = new GrantAuthorization(new GrantContext('connection', 'grant', '1', $driver, 'same@example.com'), $tokens);
    $client = $driver === 'gmail'
        ? new GmailClient([], new GmailTokenManager([]), new RateLimiter([]), $http, $authorization)
        : new GraphClient([], new TokenManager([]), new RateLimiter([]), $http, $authorization);
    $endpoint = $mismatch === 'origin' ? 'https://untrusted.example/users/same%40example.com/messages/one' : 'users/other%40example.com/messages/one';
    $mailbox = $mismatch === 'argument' ? 'other@example.com' : 'same@example.com';

    expect(fn () => $client->get($endpoint, mailbox: $mailbox))->toThrow($mismatch === 'argument' ? GrantAccessDeniedException::class : ApiRequestException::class)
        ->and($history)->toBe([])
        ->and($tokens->tokenReads)->toBe(0);
})->with(['gmail', 'ms-graph'])->with(['path', 'origin', 'argument']);

it('rejects a changed opaque mailbox at both authorization boundaries', function (string $boundary, string $target): void {
    $history = [];
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['OpaqueMailboxAA'], 'token' => 'selected-token'],
    ]);
    $authorization = new GrantAuthorization(new GrantContext('connection', 'grant', '1', 'ms-graph', 'OpaqueMailboxAA'), $tokens);
    $client = new GraphClient([], new TokenManager([]), new RateLimiter([]), grantReadHttp('ms-graph', [new Response(200, [], '{}')], $history), $authorization);

    expect(fn () => $boundary === 'token'
        ? $authorization->accessToken($target)
        : $client->get('users/'.rawurlencode($target).'/messages/one', mailbox: 'OpaqueMailboxAA'))
        ->toThrow($boundary === 'token' ? GrantAccessDeniedException::class : ApiRequestException::class)
        ->and($tokens->tokenReads)->toBe(0)
        ->and($history)->toBe([]);
})->with(['token', 'path'])->with(['case' => 'opaquemailboxaa', 'whitespace' => 'OpaqueMailboxAA ']);

it('preserves typed mailbox aliases and exact opaque identities', function (string $mailbox, string $target): void {
    $history = [];
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => [$mailbox], 'token' => 'selected-token'],
    ]);
    $authorization = new GrantAuthorization(new GrantContext('connection', 'grant', '1', 'ms-graph', $mailbox), $tokens);
    $client = new GraphClient([], new TokenManager([]), new RateLimiter([]), grantReadHttp('ms-graph', [new Response(200, [], '{"id":"one"}')], $history), $authorization);

    expect($client->get('users/'.rawurlencode($target).'/messages/one', mailbox: $target))->toBe(['id' => 'one'])
        ->and($history)->toHaveCount(1)
        ->and($tokens->tokenReads)->toBe(1);
})->with([
    'email alias' => ['Same@example.com', 'same@EXAMPLE.com'],
    'GUID alias' => ['AABBCCDD-1234-5678-90AB-AABBCCDDEEFF', 'aabbccdd-1234-5678-90ab-aabbccddeeff'],
    'exact opaque identity' => ['OpaqueMailboxAA', 'OpaqueMailboxAA'],
]);

/** @param list<Response|callable> $responses */
function grantReadHttp(string $driver, array $responses, array &$history): Client
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new Client([
        'handler' => $stack,
        'base_uri' => $driver === 'gmail' ? 'https://gmail.googleapis.com/gmail/v1/' : 'https://graph.microsoft.com/v1.0/',
    ]);
}

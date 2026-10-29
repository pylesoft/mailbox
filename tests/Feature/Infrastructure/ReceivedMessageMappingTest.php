<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\Drivers\Gmail\GmailClient;
use Pyle\Mailbox\Drivers\Gmail\GmailMessageResource;
use Pyle\Mailbox\Drivers\Gmail\GmailTokenManager;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphMessageResource;
use Pyle\Mailbox\Drivers\MsGraph\RateLimiter;
use Pyle\Mailbox\Drivers\MsGraph\TokenManager;
use Pyle\Mailbox\Exceptions\MailboxException;

it('preserves quoted names, comments and grouped Gmail recipients without inventing addresses', function (): void {
    $message = receivedMessageResource('gmail', [
        'id' => 'message-1',
        'payload' => ['headers' => [
            ['name' => 'From', 'value' => '"Doe, Jane" (Billing) <jane@example.com>'],
            ['name' => 'To', 'value' => 'Team: "Doe, John" <john@example.com>, mary@example.com (Accounts);, undisclosed-recipients:;, Other: other@example.com;'],
            ['name' => 'Cc', 'value' => '"quoted local"@example.com'],
            ['name' => 'Bcc', 'value' => 'undisclosed-recipients:;'],
        ]],
    ])->get();

    expect($message->from?->toArray())->toBe(['name' => 'Doe, Jane', 'address' => 'jane@example.com'])
        ->and(array_map(fn ($address): array => $address->toArray(), $message->toRecipients))->toBe([
            ['name' => 'Doe, John', 'address' => 'john@example.com'],
            ['name' => '', 'address' => 'mary@example.com'],
            ['name' => '', 'address' => 'other@example.com'],
        ])
        ->and($message->ccRecipients[0]->address)->toBe('"quoted local"@example.com')
        ->and($message->bccRecipients)->toBe([]);
});

it('decodes international Gmail names and subjects while preserving Unicode addresses', function (): void {
    $message = receivedMessageResource('gmail', [
        'id' => 'message-1',
        'payload' => ['headers' => [
            ['name' => 'Subject', 'value' => "=?ISO-8859-1?Q?Facture_=E9t=E9?=\r\n\t=?UTF-8?B?IOKckw==?="],
            ['name' => 'From', 'value' => '=?ISO-8859-1?Q?Andr=E9_Dupont?= <andre@example.com>'],
            ['name' => 'Sender', 'value' => '=?UTF-8?B?5bGx55Sw?= <yamada@例え.jp>'],
            ['name' => 'To', 'value' => 'José <josé@exämple.com>, =?UTF-8?Q?Doe=2C_Jane?= <jane@example.com>'],
        ]],
    ])->get();

    expect($message->subject)->toBe('Facture été ✓')
        ->and($message->from?->toArray())->toBe(['name' => 'André Dupont', 'address' => 'andre@example.com'])
        ->and($message->sender?->toArray())->toBe(['name' => '山田', 'address' => 'yamada@例え.jp'])
        ->and(array_map(fn ($address): array => $address->toArray(), $message->toRecipients))->toBe([
            ['name' => 'José', 'address' => 'josé@exämple.com'],
            ['name' => 'Doe, Jane', 'address' => 'jane@example.com'],
        ]);
});

it('retains all repeated Gmail recipient headers', function (): void {
    $message = receivedMessageResource('gmail', [
        'id' => 'message-1',
        'payload' => ['headers' => [
            ['name' => 'To', 'value' => 'first@example.com'],
            ['name' => 'to', 'value' => 'Other: second@example.com;'],
        ]],
    ])->get();

    expect(array_map(fn ($address): string => $address->address, $message->toRecipients))
        ->toBe(['first@example.com', 'second@example.com']);
});

it('preserves Graph structured international addresses and already decoded text', function (): void {
    $message = receivedMessageResource('graph', [
        'id' => 'message-1',
        'subject' => 'Facture été ✓',
        'from' => ['emailAddress' => ['name' => 'Doe, André (Billing)', 'address' => 'andré@exämple.com']],
        'sender' => ['emailAddress' => ['name' => '山田', 'address' => 'yamada@例え.jp']],
        'toRecipients' => [['emailAddress' => ['name' => 'Doe, Jane', 'address' => 'jane@example.com']]],
        'ccRecipients' => [],
        'bccRecipients' => [],
    ])->get();

    expect($message->subject)->toBe('Facture été ✓')
        ->and($message->from?->toArray())->toBe(['name' => 'Doe, André (Billing)', 'address' => 'andré@exämple.com'])
        ->and($message->sender?->toArray())->toBe(['name' => '山田', 'address' => 'yamada@例え.jp'])
        ->and($message->toRecipients[0]->toArray())->toBe(['name' => 'Doe, Jane', 'address' => 'jane@example.com'])
        ->and($message->toArray())->toHaveKeys(['subject', 'from', 'sender', 'toRecipients', 'raw']);
});

it('rejects malformed provider header and recipient collections instead of returning empty values', function (string $provider, array $payload): void {
    expect(fn () => receivedMessageResource($provider, $payload)->get())->toThrow(MailboxException::class);
})->with([
    'Gmail headers are not objects' => ['gmail', ['payload' => ['headers' => ['broken']]]],
    'Gmail header value is not text' => ['gmail', ['payload' => ['headers' => [['name' => 'To', 'value' => null]]]]],
    'Graph recipients are not a collection' => ['graph', ['toRecipients' => 'jane@example.com']],
    'Graph sender is not an object' => ['graph', ['from' => 'jane@example.com']],
]);

it('rejects Graph recipients without a usable structured email address', function (mixed $recipient): void {
    $resource = receivedMessageResource('graph', ['id' => 'message-1', 'toRecipients' => [$recipient]]);

    expect(fn () => $resource->get())->toThrow(MailboxException::class);
})->with([
    'missing address' => [['emailAddress' => ['name' => 'Jane']]],
    'malformed address' => [['emailAddress' => ['address' => 'not-an-address']]],
    'unsupported recipient shape' => ['jane@example.com'],
]);

it('rejects malformed, truncated or unsupported Gmail headers explicitly', function (string $name, string $value): void {
    $resource = receivedMessageResource('gmail', [
        'id' => 'message-1',
        'payload' => ['headers' => [['name' => $name, 'value' => $value]]],
    ]);

    expect(fn () => $resource->get())->toThrow(MailboxException::class);
})->with([
    'unfinished quoted name' => ['To', '"Doe, Jane <jane@example.com>'],
    'unfinished comment' => ['From', 'Jane (Billing <jane@example.com>'],
    'unfinished angle address' => ['To', 'Jane <jane@example.com'],
    'unfinished group' => ['To', 'Team: jane@example.com'],
    'not an email address' => ['To', 'not-an-address'],
    'missing local part' => ['To', '<@example.com>'],
    'empty sender' => ['From', ''],
    'multiple senders cannot fit the singular DTO' => ['From', 'jane@example.com, john@example.com'],
    'invalid encoded base64' => ['Subject', '=?UTF-8?B?!!!!?='],
    'truncated encoded word' => ['Subject', '=?UTF-8?Q?unfinished'],
    'invalid encoded quoted printable' => ['Subject', '=?UTF-8?Q?broken=ZX?='],
    'unsupported charset' => ['Subject', '=?X-NOT-A-CHARSET?Q?bonjour?='],
    'invalid encoded UTF-8 bytes' => ['Subject', '=?UTF-8?B?/w==?='],
    'unexpected line break' => ['Subject', "Hello\r\nBcc: hidden@example.com"],
]);

/** @param array<string, mixed> $payload */
function receivedMessageResource(string $provider, array $payload): MessageResource
{
    $http = new Client([
        'base_uri' => $provider === 'gmail' ? 'https://gmail.googleapis.com/gmail/v1/' : 'https://graph.microsoft.com/v1.0/',
        'handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'fixture-token', 'expires_in' => 3600], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode($payload, JSON_THROW_ON_ERROR)),
        ])),
    ]);
    $config = ['cache_prefix' => bin2hex(random_bytes(8)), 'max_retries' => 0];
    $rateLimiter = new RateLimiter($config);

    if ($provider === 'gmail') {
        $privateKey = openssl_pkey_new(['private_key_bits' => 2048]);
        if ($privateKey === false) {
            throw new RuntimeException('OpenSSL key generation is required for the fake Gmail authorization exchange.');
        }
        $privateKeyPem = '';
        openssl_pkey_export($privateKey, $privateKeyPem);
        $config['service_account_json'] = json_encode([
            'client_email' => 'fixture@example.iam.gserviceaccount.com',
            'private_key' => $privateKeyPem,
        ], JSON_THROW_ON_ERROR);

        return new GmailMessageResource(new GmailClient($config, new GmailTokenManager($config, $http), $rateLimiter, $http), 'inbox@example.com', 'message-1');
    }

    $config += ['tenant_id' => 'fixture-tenant', 'client_id' => 'fixture-client', 'client_secret' => 'fixture-secret'];

    return new MsGraphMessageResource(new GraphClient($config, new TokenManager($config, $http), $rateLimiter, $http), 'inbox@example.com', 'message-1');
}

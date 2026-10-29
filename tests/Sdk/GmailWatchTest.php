<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Pyle\Mailbox\Contracts\GmailPushVerifier;
use Pyle\Mailbox\Drivers\Gmail\Contracts\SupportsGmailWatch;
use Pyle\Mailbox\DTOs\GmailWatchConfiguration;
use Pyle\Mailbox\DTOs\GmailWatchRegistration;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\InvalidChangeNotificationException;
use Pyle\Mailbox\Exceptions\InvalidSyncStateException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('registers an explicitly selected Gmail watch with host managed renewal evidence', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{"historyId":"90071992547409931234","expiration":"1893456000123"}'),
    ]));
    $stack->push(Middleware::history($history));
    $context = new GrantContext('connection', 'grant', '1', 'gmail', 'same@example.com');
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'selected-token'],
    ]);
    $mailbox = Mailbox::forGrant($context, $tokens, new Client(['handler' => $stack]));

    expect($mailbox)->toBeInstanceOf(SupportsGmailWatch::class);

    $configuration = new GmailWatchConfiguration(
        topic: 'projects/project/topics/mail',
        subscription: 'projects/project/subscriptions/mail-push',
        audience: 'https://host.example/gmail-push',
        serviceAccountEmail: 'push@project.iam.gserviceaccount.com',
        serviceAccountSubject: '123456789',
    );
    $registration = $mailbox->watch($configuration, ['INBOX']);

    expect($registration->provider)->toBe('gmail')
        ->and($registration->context)->toBe($context)
        ->and($registration->configuration)->toBe($configuration)
        ->and($registration->historyId)->toBe('90071992547409931234')
        ->and($registration->expiresAt->format('Y-m-d\TH:i:s.v\Z'))->toBe('2030-01-01T00:00:00.123Z')
        ->and($registration->status)->toBe('active')
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getMethod())->toBe('POST')
        ->and((string) $history[0]['request']->getUri())->toBe('https://gmail.googleapis.com/gmail/v1/users/same%40example.com/watch')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer selected-token')
        ->and(json_decode((string) $history[0]['request']->getBody(), true))->toBe([
            'topicName' => 'projects/project/topics/mail', 'labelIds' => ['INBOX'], 'labelFilterBehavior' => 'include',
        ]);
});

it('verifies the raw PubSub request and returns only a grant bound reconciliation hint', function (): void {
    $history = [];
    $mailbox = watchResource([], $history);
    $registration = watchRegistration();
    $request = watchRequest('90071992547409931234');
    $verifier = new class implements GmailPushVerifier
    {
        public ?RequestInterface $received = null;

        public function verify(RequestInterface $request): array
        {
            $this->received = $request;

            return [
                'iss' => 'https://accounts.google.com', 'aud' => 'https://host.example/gmail-push',
                'sub' => '123456789', 'email' => 'push@project.iam.gserviceaccount.com', 'email_verified' => true,
                'iat' => time() - 60, 'exp' => time() + 600,
            ];
        }
    };

    $hint = $mailbox->notification($request, $registration, $verifier);

    expect($verifier->received)->toBe($request)
        ->and($hint->provider)->toBe('gmail')
        ->and($hint->context)->toBe($registration->context)
        ->and($hint->topic)->toBe('projects/project/topics/mail')
        ->and($hint->subscription)->toBe('projects/project/subscriptions/mail-push')
        ->and($hint->messageId)->toBe('2070443601311540')
        ->and($hint->historyId)->toBe('90071992547409931234')
        ->and($hint->reason)->toBe('mailbox_changed')
        ->and($registration->historyId)->toBe('100')
        ->and(property_exists($hint, 'checkpoint'))->toBeFalse()
        ->and($history)->toBe([]);
});

it('stops a watch once and surfaces a late verified notification as catch up work', function (): void {
    $history = [];
    $mailbox = watchResource([new Response(200, [], '{}')], $history);
    $registration = watchRegistration();

    $stopped = $mailbox->stopWatch($registration);
    $hint = $mailbox->notification(watchRequest(), $stopped, watchVerifier());

    expect($stopped->status)->toBe('stopped')
        ->and($stopped->context)->toBe($registration->context)
        ->and($stopped->configuration)->toBe($registration->configuration)
        ->and($registration->status)->toBe('active')
        ->and($hint->reason)->toBe('watch_stopped')
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getMethod())->toBe('POST')
        ->and((string) $history[0]['request']->getUri())->toBe('https://gmail.googleapis.com/gmail/v1/users/same%40example.com/stop')
        ->and((string) $history[0]['request']->getBody())->toBe('');
});

it('requires a configured raw request verifier rather than trusting decoded claims', function (): void {
    $history = [];
    $request = watchRequest()->withHeader('Authorization', 'Bearer '.base64_encode('{"iss":"https://accounts.google.com","authenticated":true}'));

    expect(fn () => watchResource([], $history)->notification($request, watchRegistration()))
        ->toThrow(UnsupportedCapabilityException::class, 'trusted host verifier')
        ->and($history)->toBe([]);
});

it('fails closed on rejected verification and hides verifier secrets', function (bool $boolean): void {
    $history = [];
    $verifier = new class($boolean) implements GmailPushVerifier
    {
        public function __construct(private bool $boolean) {}

        public function verify(RequestInterface $request): array
        {
            if ($this->boolean) {
                return true;
            }
            throw new RuntimeException('private-token private-body');
        }
    };
    try {
        watchResource([], $history)->notification(watchRequest(), watchRegistration(), $verifier);
        test()->fail('Rejected authentication produced a usable notification.');
    } catch (InvalidChangeNotificationException $exception) {
        expect($exception->getMessage())->not->toContain('private-token');
        expect($exception->getMessage())->not->toContain('private-body');
        expect($exception->getPrevious())->toBeNull();
    }
    expect($history)->toBe([]);
})->with([false, true]);

it('rejects verified claims which do not match the trusted push identity', function (array $claims): void {
    $history = [];

    expect(fn () => watchResource([], $history)->notification(watchRequest(), watchRegistration(), watchVerifier($claims)))
        ->toThrow(InvalidChangeNotificationException::class)
        ->and($history)->toBe([]);
})->with([
    [['iss' => 'https://evil.example']],
    [['aud' => 'https://other.example/push']],
    [['aud' => ['https://host.example/gmail-push']]],
    [['sub' => 'another-principal']],
    [['email' => 'other@project.iam.gserviceaccount.com']],
    [['email_verified' => false]],
    [['email_verified' => 'true']],
    [['exp' => 1]],
    [['exp' => '9999999999']],
    [['iat' => PHP_INT_MAX]],
]);

it('rejects malformed or misrouted PubSub envelopes without returning provider content', function (string $difference): void {
    $history = [];
    $request = watchRequest();
    $envelope = json_decode((string) $request->getBody(), true);
    $payload = ['emailAddress' => 'same@example.com', 'historyId' => '110'];
    switch ($difference) {
        case 'subscription':
            $envelope['subscription'] = 'projects/project/subscriptions/another';
            break;
        case 'account':
            $payload['emailAddress'] = 'other@example.com';
            break;
        case 'history':
            $payload['historyId'] = 110;
            break;
        case 'position':
            $payload['historyId'] = 'not-a-history-id';
            break;
        case 'message-id':
            unset($envelope['message']['messageId']);
            break;
    }
    $envelope['message']['data'] = match ($difference) {
        'base64' => '%invalid',
        'truncated-base64' => 'A',
        'payload' => base64_encode('[]'),
        'payload-json' => base64_encode('{broken'),
        default => base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
    };
    $body = match ($difference) {
        'json' => '{broken',
        'list' => '[]',
        'message' => '{"message":[],"subscription":"projects/project/subscriptions/mail-push"}',
        default => json_encode($envelope, JSON_THROW_ON_ERROR),
    };
    $request = $request->withBody(Utils::streamFor($body));
    if ($difference === 'method') {
        $request = $request->withMethod('GET');
    }
    if ($difference === 'content-type') {
        $request = $request->withoutHeader('Content-Type');
    }

    expect(fn () => watchResource([], $history)->notification($request, watchRegistration(), watchVerifier(), $difference === 'budget' ? 32 : 65_536))
        ->toThrow(InvalidChangeNotificationException::class)
        ->and($history)->toBe([]);
})->with(['subscription', 'account', 'history', 'position', 'message-id', 'base64', 'truncated-base64', 'payload', 'payload-json', 'json', 'list', 'message', 'budget', 'method', 'content-type']);

it('keeps duplicate and out of order history IDs as hints and derives the topic only from trusted configuration', function (): void {
    $history = [];
    $mailbox = watchResource([], $history);
    $registration = watchRegistration();
    $positions = [];
    foreach (['110', '110', '90'] as $position) {
        $request = watchRequest($position);
        $body = json_decode((string) $request->getBody(), true);
        $body['topic'] = 'projects/attacker/topics/forged';
        $request = $request->withBody(Utils::streamFor(json_encode($body, JSON_THROW_ON_ERROR)));
        $hint = $mailbox->notification($request, $registration, watchVerifier());
        $positions[] = $hint->historyId;
        expect($hint->topic)->toBe('projects/project/topics/mail');
        expect(property_exists($hint, 'checkpoint'))->toBeFalse();
    }
    expect($positions)->toBe(['110', '110', '90'])
        ->and($registration->historyId)->toBe('100')
        ->and($history)->toBe([]);
});

it('rejects a registration from another grant mailbox or generation before provider IO', function (string $difference): void {
    $history = [];
    $mailbox = watchResource([], $history);
    $original = watchRegistration();
    $context = new GrantContext(
        $difference === 'connection' ? 'other' : 'connection',
        $difference === 'grant' ? 'other' : 'grant',
        $difference === 'generation' ? '01' : '1',
        'gmail',
        $difference === 'mailbox' ? 'other@example.com' : 'same@example.com',
    );
    $registration = new GmailWatchRegistration($context, $original->configuration, '100', $original->expiresAt);

    expect(fn () => $mailbox->notification(watchRequest(), $registration, watchVerifier()))->toThrow(InvalidSyncStateException::class);
    expect(fn () => $mailbox->stopWatch($registration))->toThrow(InvalidSyncStateException::class);
    expect($history)->toBe([]);
})->with(['connection', 'grant', 'generation', 'mailbox']);

it('does not return a usable registration or hint after the host reconnects during work', function (string $phase): void {
    $history = [];
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'selected-token'],
    ]);
    $invalidate = function () use ($tokens): void {
        $tokens->grants['grant']['generation'] = '2';
    };
    $responses = $phase === 'verifier' || $phase === 'before' ? [] : [function () use ($invalidate, $phase): Response {
        $invalidate();

        return new Response(200, [], $phase === 'stop' ? '{}' : '{"historyId":"110","expiration":"1893456000123"}');
    }];
    $mailbox = watchResource($responses, $history, $tokens);
    $registration = watchRegistration();
    if ($phase === 'before') {
        $invalidate();
    }

    expect(fn () => match ($phase) {
        'watch' => $mailbox->watch($registration->configuration),
        'stop' => $mailbox->stopWatch($registration),
        default => $mailbox->notification(watchRequest(), $registration, watchVerifier(duringVerification: $invalidate)),
    })->toThrow(GrantAccessDeniedException::class);
    expect($history)->toHaveCount(in_array($phase, ['watch', 'stop'], true) ? 1 : 0);
})->with(['before', 'verifier', 'watch', 'stop']);

it('returns an explicit catch up hint for missed expired stopped or removed watches', function (string $state): void {
    $history = [];
    $original = watchRegistration();
    $registration = new GmailWatchRegistration($original->context, $original->configuration, '100', $state === 'expired' ? new DateTimeImmutable('2000-01-01T00:00:00Z') : $original->expiresAt, in_array($state, ['stopped', 'removed'], true) ? $state : 'active');
    $mailbox = watchResource([], $history);

    $hint = $mailbox->watchCatchUpHint($registration);

    expect($hint->reason)->toBe($state === 'active' ? 'missed_delivery' : 'watch_'.$state)
        ->and($hint->messageId)->toBeNull()
        ->and($hint->historyId)->toBeNull()
        ->and($hint->context)->toBe($registration->context)
        ->and($history)->toBe([]);
})->with(['active', 'expired', 'stopped', 'removed']);

it('surfaces one unconfirmed watch operation without replay or private provider content', function (string $operation, string $failure): void {
    $history = [];
    $status = ctype_digit($failure) ? (int) $failure : ($failure === 'timeout' ? null : 200);
    $response = $failure === 'timeout'
        ? new ConnectException('private-provider-token private-response', new Request('POST', 'https://gmail.googleapis.com/'))
        : new Response($status, [], match ($failure) {
            'oversized' => str_repeat('x', 70_000),
            'malformed' => $operation === 'watch' ? '{"historyId":110,"expiration":"invalid"}' : '[]',
            default => '{"error":"private-provider-token private-response"}',
        });
    $mailbox = watchResource([$response], $history);
    $registration = watchRegistration();

    try {
        $operation === 'watch' ? $mailbox->watch($registration->configuration) : $mailbox->stopWatch($registration);
        test()->fail('An unconfirmed operation returned confirmed registration state.');
    } catch (ApiRequestException $exception) {
        expect($exception->status)->toBe($status);
        expect($exception->getMessage())->not->toContain('private-provider-token');
        expect($exception->getMessage())->not->toContain('private-response');
        expect($exception->getPrevious())->toBeNull();
    }
    expect($history)->toHaveCount(1);
})->with(['watch', 'stop'])->with(['401', '403', '429', '503', 'timeout', 'malformed', 'oversized']);

it('requires explicit grant selection for watch operations and rejects stale recovery work', function (): void {
    $history = [];
    $registration = watchRegistration();
    $configured = Mailbox::driver('gmail')->mailbox('same@example.com');

    expect(fn () => $configured->watch($registration->configuration))->toThrow(UnsupportedCapabilityException::class);
    expect(fn () => $configured->stopWatch($registration))->toThrow(UnsupportedCapabilityException::class);
    expect(fn () => $configured->notification(watchRequest(), $registration, watchVerifier()))->toThrow(UnsupportedCapabilityException::class);
    expect(fn () => $configured->watchCatchUpHint($registration))->toThrow(UnsupportedCapabilityException::class);

    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '2', 'mailboxes' => ['same@example.com'], 'token' => 'selected-token'],
    ]);
    $mailbox = watchResource([], $history, $tokens);
    expect(fn () => $mailbox->watchCatchUpHint($registration))->toThrow(GrantAccessDeniedException::class);
    expect($history)->toBe([]);
});

it('does not expose raw request stream failures from notification parsing', function (): void {
    $history = [];
    $stream = FnStream::decorate(Utils::streamFor(''), [
        'eof' => static fn (): bool => false,
        'read' => static fn (): never => throw new RuntimeException('private-stream-secret'),
    ]);
    $request = watchRequest()->withBody($stream);

    try {
        watchResource([], $history)->notification($request, watchRegistration(), watchVerifier());
        test()->fail('Unreadable notification content returned a usable hint.');
    } catch (InvalidChangeNotificationException $exception) {
        expect($exception->getMessage())->not->toContain('private-stream-secret');
        expect($exception->getPrevious())->toBeNull();
    }
    expect($history)->toBe([]);
});

it('can reread the raw envelope after a trusted verifier consumes its seekable body', function (): void {
    $history = [];
    $request = watchRequest();
    $verifier = watchVerifier(duringVerification: static function (RequestInterface $raw): void {
        $raw->getBody()->getContents();
    });

    $hint = watchResource([], $history)->notification($request, watchRegistration(), $verifier);

    expect($hint->historyId)->toBe('110')
        ->and($hint->reason)->toBe('mailbox_changed')
        ->and($history)->toBe([]);
});

it('does not expose its retained host authorization provider in debug dumps', function (): void {
    $history = [];
    $mailbox = watchResource([], $history);

    expect(print_r($mailbox, true))->not->toContain('selected-token');
});

/** @param array<string, mixed> $overrides */
function watchVerifier(array $overrides = [], ?Closure $duringVerification = null): GmailPushVerifier
{
    return new class($overrides, $duringVerification) implements GmailPushVerifier
    {
        public function __construct(private array $overrides, private ?Closure $duringVerification) {}

        public function verify(RequestInterface $request): array
        {
            ($this->duringVerification ?? static fn (): null => null)($request);

            return array_replace([
                'iss' => 'https://accounts.google.com', 'aud' => 'https://host.example/gmail-push',
                'sub' => '123456789', 'email' => 'push@project.iam.gserviceaccount.com', 'email_verified' => true,
                'iat' => time() - 60, 'exp' => time() + 600,
            ], $this->overrides);
        }
    };
}

/** @param list<mixed> $responses */
function watchResource(array $responses, array &$history, ?TestGrantTokenProvider $tokens = null): SupportsGmailWatch
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $context = new GrantContext('connection', 'grant', '1', 'gmail', 'same@example.com');
    $tokens ??= new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'selected-token'],
    ]);

    return Mailbox::forGrant($context, $tokens, new Client(['handler' => $stack]));
}

function watchRegistration(): GmailWatchRegistration
{
    return new GmailWatchRegistration(
        new GrantContext('connection', 'grant', '1', 'gmail', 'same@example.com'),
        new GmailWatchConfiguration('projects/project/topics/mail', 'projects/project/subscriptions/mail-push', 'https://host.example/gmail-push', 'push@project.iam.gserviceaccount.com', '123456789'),
        '100',
        new DateTimeImmutable('2030-01-01T00:00:00Z'),
    );
}

function watchRequest(string $historyId = '110'): RequestInterface
{
    return new Request('POST', 'https://host.example/gmail-push', ['Content-Type' => 'application/json', 'Authorization' => 'Bearer signed-host-verified-token'], json_encode([
        'message' => ['messageId' => '2070443601311540', 'data' => base64_encode(json_encode(['emailAddress' => 'same@example.com', 'historyId' => $historyId], JSON_THROW_ON_ERROR))],
        'subscription' => 'projects/project/subscriptions/mail-push',
    ], JSON_THROW_ON_ERROR));
}

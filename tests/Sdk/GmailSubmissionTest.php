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
use Illuminate\Contracts\Queue\Job;
use Psr\Http\Message\RequestInterface;
use Pyle\Mailbox\Contracts\GrantTokenProvider;
use Pyle\Mailbox\Contracts\SupportsMessageSubmission;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\Enums\SubmissionOutcome;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Support\ReceivedHeaders;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;
use ZBateson\MailMimeParser\MailMimeParser;

it('submits explicit international Gmail content once without package persistence', function (): void {
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
        new Response(200, ['X-Request-Id' => 'request-1'], '{"id":"18af33","threadId":"18af30"}'),
    ]));
    $handler->push(Middleware::mapRequest(static fn (RequestInterface $request): RequestInterface => $request->withBody(Utils::streamFor((string) $request->getBody()))));
    $handler->push(Middleware::history($history));
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['sender@example.com'], 'token' => 'private-token'],
    ]);
    $mailbox = Mailbox::forGrant(
        new GrantContext('connection', 'grant', '1', 'gmail', 'sender@example.com'),
        $tokens,
        new Client(['handler' => $handler, 'auth' => ['unrelated-user', 'unrelated-password']]),
    );
    $prepared = new PreparedMessage(
        sender: new EmailAddressDto('Élodie, Sender', 'sender@example.com'),
        to: [new EmailAddressDto('Zoë', 'to@example.com')],
        cc: [new EmailAddressDto('Copy', 'cc@example.com')],
        bcc: [new EmailAddressDto('Private', 'bcc@example.com')],
        subject: 'Réunion à Montréal',
        textBody: 'Bonjour Zoë',
        htmlBody: '<p>Bonjour Zoë</p>',
        headers: ['X-Project' => 'Montréal'],
        correlationId: 'host-operation-1',
    );

    expect($mailbox)->toBeInstanceOf(SupportsMessageSubmission::class)
        ->and($history)->toBe([]);
    $result = $mailbox->submit($prepared);

    expect($result->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($result->status)->toBe(200)
        ->and($result->messageId)->toBe('18af33')
        ->and($result->threadId)->toBe('18af30')
        ->and($result->correlationId)->toBe('host-operation-1')
        ->and($result->headers)->toBe(['x-request-id' => ['request-1']])
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getMethod())->toBe('POST')
        ->and((string) $history[0]['request']->getUri())->toBe('https://gmail.googleapis.com/gmail/v1/users/sender%40example.com/messages/send')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer private-token');

    $payload = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $mime = base64_decode(strtr($payload['raw'], '-_', '+/'), true);
    expect($mime)->toBeString()->toContain('bcc@example.com', 'to@example.com', 'cc@example.com', 'multipart/alternative', 'host-operation-1');
    preg_match('/^Subject: (.+(?:\r\n[ \t].+)*)/m', $mime, $subject);
    expect(ReceivedHeaders::text(rtrim($subject[1], "\r")))->toBe('Réunion à Montréal')
        ->and(config('database.default'))->toBe('sdk-forbidden');
    $parsed = (new MailMimeParser)->parse($mime, false);
    expect($parsed->getTextContent())->toBe('Bonjour Zoë')
        ->and($parsed->getHtmlContent())->toBe('<p>Bonjour Zoë</p>')
        ->and($parsed->getHeader('From')->getAddresses()[0]->getName())->toBe('Élodie, Sender')
        ->and($parsed->getHeader('To')->getAddresses()[0]->getName())->toBe('Zoë');
});

it('rejects unsafe prepared headers before any provider submission', function (array $changes): void {
    $history = [];
    $mailbox = submissionMailbox([], $history);

    expect(fn () => $mailbox->submit(submissionMessage($changes)))->toThrow(InvalidArgumentException::class)
        ->and($history)->toBe([]);
})->with([
    'subject injection' => [['subject' => "Hello\r\nBcc: hidden@example.com"]],
    'sender name injection' => [['sender' => new EmailAddressDto("Sender\nBcc: hidden@example.com", 'sender@example.com')]],
    'recipient name injection' => [['to' => [new EmailAddressDto("Recipient\r\nBcc: hidden@example.com", 'to@example.com')]]],
    'address injection' => [['to' => [new EmailAddressDto('Recipient', "to@example.com\r\nBcc: hidden@example.com")]]],
    'correlation injection' => [['correlationId' => "operation\r\nBcc: hidden@example.com"]],
    'header name injection' => [['headers' => ["X-Project\r\nBcc" => 'hidden@example.com']]],
    'header value injection' => [['headers' => ['X-Project' => "safe\r\nBcc: hidden@example.com"]]],
    'recipient override' => [['headers' => ['bCc' => 'hidden@example.com']]],
    'content override' => [['headers' => ['Content-Type' => 'multipart/signed']]],
    'correlation override' => [['headers' => ['X-Mailbox-Correlation-Id' => 'different-operation']]],
    'invalid address' => [['to' => [new EmailAddressDto('Recipient', 'missing-domain')]]],
    'invalid UTF-8' => [['subject' => "invalid\xff"]],
    'no explicit recipients' => [['to' => []]],
    'no content' => [['textBody' => null]],
    'no caller correlation' => [['correlationId' => '']],
]);

it('redacts an unexpected host token failure before the send boundary', function (): void {
    $history = [];
    $tokens = new class implements GrantTokenProvider
    {
        public function assertActive(GrantContext $context): void {}

        public function accessToken(GrantContext $context): string
        {
            throw new RuntimeException('private-token host secret');
        }
    };
    $mailbox = submissionMailbox([], $history, $tokens);

    try {
        $mailbox->submit(submissionMessage());
        test()->fail('The host token failure must remain a preflight failure.');
    } catch (ApiRequestException $exception) {
        expect($exception->getMessage())->not->toContain('private-token')->not->toContain('host secret')
            ->and($exception->getPrevious())->toBeNull();
    }
    expect($history)->toBe([]);
});

it('rejects stale or disconnected grants after local preparation without provider IO', function (string $change): void {
    $history = [];
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['sender@example.com'], 'token' => 'private-token'],
    ]);
    $mailbox = submissionMailbox([], $history, $tokens);
    $message = submissionMessage();
    if ($change === 'reconnect') {
        $tokens->grants['grant']['generation'] = '2';
    } else {
        unset($tokens->grants['grant']);
    }

    expect(fn () => $mailbox->submit($message))->toThrow(GrantAccessDeniedException::class)
        ->and($history)->toBe([]);
})->with(['reconnect', 'disconnect']);

it('rechecks lifecycle after loading a token and before sending', function (): void {
    $history = [];
    $tokens = new class implements GrantTokenProvider
    {
        private bool $active = true;

        public function assertActive(GrantContext $context): void
        {
            if (! $this->active) {
                throw new GrantAccessDeniedException('Disconnected while loading a token.');
            }
        }

        public function accessToken(GrantContext $context): string
        {
            $this->active = false;

            return 'private-token';
        }
    };
    $mailbox = submissionMailbox([], $history, $tokens);

    expect(fn () => $mailbox->submit(submissionMessage()))->toThrow(GrantAccessDeniedException::class)
        ->and($history)->toBe([]);
});

it('keeps validated acceptance when closing the provider response fails', function (): void {
    $history = [];
    $closeAttempted = false;
    $body = FnStream::decorate(Utils::streamFor('{"id":"accepted-id"}'), [
        'close' => function () use (&$closeAttempted): void {
            if (! $closeAttempted) {
                $closeAttempted = true;
                throw new RuntimeException('private-token response cleanup failed');
            }
        },
    ]);
    $mailbox = submissionMailbox([new Response(200, ['X-Request-Id' => 'request-1'], $body)], $history);

    $result = $mailbox->submit(submissionMessage());

    expect($result->outcome)->toBe(SubmissionOutcome::Accepted)
        ->and($result->messageId)->toBe('accepted-id')
        ->and($closeAttempted)->toBeTrue()
        ->and($history)->toHaveCount(1);
});

it('keeps status and request identity when a provider response stream is interrupted', function (): void {
    $history = [];
    $closed = false;
    $body = FnStream::decorate(Utils::streamFor('unreadable'), [
        'read' => function (int $length): string {
            throw new RuntimeException('private-token stream interrupted');
        },
        'close' => function () use (&$closed): void {
            $closed = true;
        },
    ]);
    $mailbox = submissionMailbox([new Response(200, ['X-Request-Id' => 'request-1'], $body)], $history);

    $result = $mailbox->submit(submissionMessage());

    expect($result->outcome)->toBe(SubmissionOutcome::Uncertain)
        ->and($result->status)->toBe(200)
        ->and($result->headers)->toBe(['x-request-id' => ['request-1']])
        ->and($closed)->toBeTrue()
        ->and($history)->toHaveCount(1)
        ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('private-token');
});

it('submits Bcc-only mail and never retains recipients for the next prepared message', function (): void {
    $history = [];
    $mailbox = submissionMailbox([new Response(200, [], '{"id":"one"}'), new Response(200, [], '{"id":"two"}')], $history);
    $mailbox->submit(submissionMessage(['to' => [], 'bcc' => [new EmailAddressDto('Private', 'bcc@example.com')]]));
    $mailbox->submit(submissionMessage());

    $first = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $second = json_decode((string) $history[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    expect(base64_decode(strtr($first['raw'], '-_', '+/'), true))->toContain('bcc@example.com')->not->toContain('to@example.com')
        ->and(base64_decode(strtr($second['raw'], '-_', '+/'), true))->toContain('to@example.com')->not->toContain('bcc@example.com');
});

it('enforces the caller request budget before submitting encoded Gmail content', function (array $changes, int $budget): void {
    $history = [];
    $mailbox = submissionMailbox([new Response(200, [], '{"id":"accepted"}')], $history);

    expect(fn () => $mailbox->submit(submissionMessage($changes), $budget))->toThrow(UnsupportedCapabilityException::class)
        ->and($history)->toBe([]);
})->with([
    'body exceeds budget' => [['textBody' => str_repeat('a', 1100)], 1024],
    'base64 JSON exceeds budget' => [['textBody' => str_repeat('a', 800)], 1024],
    'encoded international body exceeds budget' => [['textBody' => str_repeat('é', 300)], 1024],
    'header exceeds budget' => [['headers' => ['X-Project' => str_repeat('a', 1100)]], 1024],
]);

it('rejects unstructured prepared file inputs before provider IO', function (): void {
    $history = [];
    $mailbox = submissionMailbox([], $history);
    $message = submissionMessage(['files' => [['id' => 'host-file']]]);

    expect(fn () => $mailbox->submit($message))->toThrow(UnsupportedCapabilityException::class)
        ->and($history)->toBe([]);
});

it('retains honest send evidence without replaying or releasing its enclosing job', function (mixed $response, SubmissionOutcome $outcome, ?int $status): void {
    $history = [];
    $job = Mockery::mock(Job::class);
    $job->shouldNotReceive('release');
    app()->instance('queue.job', $job);
    $mailbox = submissionMailbox([$response, new Response(200, [], '{"id":"duplicate-send"}')], $history);

    $result = $mailbox->submit(submissionMessage());

    expect($result->outcome)->toBe($outcome)
        ->and($result->status)->toBe($status)
        ->and($result->correlationId)->toBe('operation-1')
        ->and($history)->toHaveCount(1)
        ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('private-token')->not->toContain('private-response')->not->toContain('private-upload');
})->with([
    'empty acknowledgement' => [new Response(200), SubmissionOutcome::Uncertain, 200],
    'invalid JSON' => [new Response(200, [], '{'), SubmissionOutcome::Uncertain, 200],
    'missing identity' => [new Response(200, [], '{}'), SubmissionOutcome::Uncertain, 200],
    'numeric identity' => [new Response(200, [], '{"id":123}'), SubmissionOutcome::Uncertain, 200],
    'invalid identity' => [new Response(200, [], '{"id":"bad/id"}'), SubmissionOutcome::Uncertain, 200],
    'invalid thread identity' => [new Response(200, [], '{"id":"accepted-id","threadId":7}'), SubmissionOutcome::Uncertain, 200],
    'oversized acknowledgement' => [new Response(200, [], str_repeat('x', 65_537)), SubmissionOutcome::Uncertain, 200],
    'empty 202 is not a Gmail acknowledgement' => [new Response(202), SubmissionOutcome::Uncertain, 202],
    'Gmail rejected the request' => [new Response(400, [], '{"error":"private-response"}'), SubmissionOutcome::Rejected, 400],
    'lost authorization' => [new Response(401), SubmissionOutcome::Rejected, 401],
    'rate limited' => [new Response(429, ['Retry-After' => '17']), SubmissionOutcome::Rejected, 429],
    'server failure' => [new Response(503, ['X-Request-Id' => 'request-503', 'Authorization' => 'Bearer private-response', 'Location' => 'https://private-upload']), SubmissionOutcome::Uncertain, 503],
    'timeout' => [new ConnectException('private-token timeout', new Request('POST', 'https://private-upload')), SubmissionOutcome::Uncertain, null],
    'redirect' => [new Response(307, ['Location' => 'https://private-upload']), SubmissionOutcome::Uncertain, 307],
    'unsafe identity header' => [new Response(200, ['X-Request-Id' => 'https://private-upload'], '{"id":"accepted-id"}'), SubmissionOutcome::Accepted, 200],
]);

/** @param array<string, mixed> $changes */
function submissionMessage(array $changes = []): PreparedMessage
{
    return new PreparedMessage(...array_replace([
        'sender' => new EmailAddressDto('Sender', 'sender@example.com'),
        'to' => [new EmailAddressDto('Recipient', 'to@example.com')],
        'subject' => 'Prepared subject',
        'correlationId' => 'operation-1',
        'textBody' => 'Prepared body',
    ], $changes));
}

/**
 * @param  list<mixed>  $responses
 * @param  array<int, mixed>  $history
 */
function submissionMailbox(array $responses, array &$history, ?GrantTokenProvider $tokens = null): SupportsMessageSubmission
{
    $handler = HandlerStack::create(new MockHandler($responses));
    // Small fixture bodies are retained at HTTP inspection; production owned spools close after submit.
    $handler->push(Middleware::mapRequest(static fn (RequestInterface $request): RequestInterface => $request->withBody(Utils::streamFor((string) $request->getBody()))));
    $handler->push(Middleware::history($history));
    $tokens ??= new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['sender@example.com'], 'token' => 'private-token'],
    ]);

    return Mailbox::forGrant(
        new GrantContext('connection', 'grant', '1', 'gmail', 'sender@example.com'),
        $tokens,
        new Client(['handler' => $handler]),
    );
}

<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\PreparedFile;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\SubmissionLimits;
use Pyle\Mailbox\Enums\PreparationOutcome;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('rejects oversized Graph input before making another body sized allocation', function (string $field): void {
    $history = [];
    $mailbox = budgetIntegrityMailbox([new Response(201, [], '{"id":"warm"}')], $history);
    $mailbox->prepareSubmission(budgetIntegrityMessage());
    $large = str_repeat('a', 8_388_608);
    $message = budgetIntegrityMessage($field === 'body' ? ['textBody' => $large] : ['headers' => ['X-Large' => $large]]);
    memory_reset_peak_usage();
    $baseline = memory_get_usage();

    expect(fn () => $mailbox->prepareSubmission($message, 1_048_576))->toThrow(UnsupportedCapabilityException::class);

    expect(memory_get_peak_usage() - $baseline)->toBeLessThan(1_048_576)
        ->and($history)->toHaveCount(1);
})->with(['body', 'header']);

it('bounds attachment intent reconstruction by the caller memory allowance', function (string $body): void {
    $history = [];
    $mailbox = budgetIntegrityMailbox([new Response(201, [], '{"id":"draft"}'), new Response(201, [], '{"id":"file"}')], $history);
    $message = budgetIntegrityMessage(['textBody' => $body, 'files' => [budgetIntegrityFile()]]);
    $preparation = $mailbox->prepareSubmission($message);
    memory_reset_peak_usage();
    $baseline = memory_get_usage();

    expect(fn () => $mailbox->prepareAttachments($preparation, $message, new SubmissionLimits(maxMemoryBytes: 1_048_576)))->toThrow(ResponseLimitExceededException::class);

    expect(memory_get_peak_usage() - $baseline)->toBeLessThan(1_048_576)
        ->and($history)->toHaveCount(1);
})->with(['ASCII' => fn () => str_repeat('a', 2_097_152), 'escaped Unicode' => fn () => str_repeat('é', 100_000)]);

it('retains uncertain attachment work when a draft observation cannot fit memory', function (string $observed): void {
    $history = [];
    $mailbox = budgetIntegrityMailbox([new Response(201, [], '{"id":"draft"}'), new Response(503), new Response(200, [], $observed)], $history);
    $message = budgetIntegrityMessage(['files' => [budgetIntegrityFile()]]);
    $preparation = $mailbox->prepareAttachments($mailbox->prepareSubmission($message), $message);
    expect($preparation->outcome)->toBe(PreparationOutcome::Uncertain);
    memory_reset_peak_usage();
    $baseline = memory_get_usage();

    $result = $mailbox->inspectAttachments($preparation, $message, new SubmissionLimits(maxMemoryBytes: 1_048_576));

    expect($result)->toBe($preparation)
        ->and(memory_get_peak_usage() - $baseline)->toBeLessThan(1_048_576)
        ->and($history)->toHaveCount(3);
})->with([
    'receipt' => fn () => '{"id":"draft","body":{"contentType":"Text","content":"'.str_repeat('a', 2_097_152).'"}}',
    'native decode' => fn () => '{"id":"draft","unknown":['.str_repeat('{},', 25_000).'{}]}',
]);

it('preserves exact Graph JSON write limits with escaping and multibyte chunk boundaries', function (): void {
    $history = [];
    $mailbox = budgetIntegrityMailbox([new Response(201, [], '{"id":"first"}'), new Response(201, [], '{"id":"second"}')], $history);
    $message = budgetIntegrityMessage(['textBody' => str_repeat('é😀"\\/', 2048)]);
    $mailbox->prepareSubmission($message);
    $encoded = (string) $history[0]['request']->getBody();

    expect($mailbox->prepareSubmission($message, strlen($encoded))->outcome)->toBe(PreparationOutcome::Prepared);
    expect(fn () => $mailbox->prepareSubmission($message, strlen($encoded) - 1))->toThrow(UnsupportedCapabilityException::class);
    expect((string) $history[1]['request']->getBody())->toBe($encoded)->and($history)->toHaveCount(2);
});

it('keeps one attempt acknowledgement evidence when an attachment receipt exceeds memory', function (): void {
    $history = [];
    $mailbox = budgetIntegrityMailbox([new Response(201, [], '{"id":"draft"}'), new Response(201, ['request-id' => 'private-operation'], '{"id":"file","extra":"'.str_repeat('a', 2_097_152).'"}')], $history);
    $message = budgetIntegrityMessage(['files' => [budgetIntegrityFile()]]);
    $preparation = $mailbox->prepareSubmission($message);
    memory_reset_peak_usage();
    $baseline = memory_get_usage();

    $result = $mailbox->prepareAttachments($preparation, $message, new SubmissionLimits(maxMemoryBytes: 1_048_576));

    expect($result->outcome)->toBe(PreparationOutcome::Uncertain)
        ->and($result->status)->toBe(201)
        ->and($result->headers)->toBe(['request-id' => ['private-operation']])
        ->and(memory_get_peak_usage() - $baseline)->toBeLessThan(1_048_576)
        ->and($history)->toHaveCount(2);
});

it('rejects invalid UTF-8 prepared content before JSON sizing or provider IO', function (): void {
    $history = [];
    $mailbox = budgetIntegrityMailbox([], $history);
    $message = budgetIntegrityMessage(['textBody' => str_repeat('a', 8191)."\xc3"]);

    expect(fn () => $mailbox->prepareSubmission($message))->toThrow(InvalidArgumentException::class)
        ->and($history)->toBe([]);
});

/** @param array<string, mixed> $changes */
function budgetIntegrityMessage(array $changes = []): PreparedMessage
{
    return new PreparedMessage(...array_replace([
        'sender' => new EmailAddressDto('Sender', 'same@example.com'),
        'to' => [new EmailAddressDto('Recipient', 'to@example.com')],
        'subject' => 'Prepared', 'correlationId' => 'operation', 'textBody' => 'Body',
    ], $changes));
}

function budgetIntegrityFile(): PreparedFile
{
    return new PreparedFile('file', 'file.txt', 'text/plain', 1, static fn () => Utils::streamFor('a'), hash('sha256', 'a'));
}

/** @param list<mixed> $responses
 * @param  array<int, mixed>  $history
 */
function budgetIntegrityMailbox(array $responses, array &$history): MailboxResource
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $tokens = new TestGrantTokenProvider([
        'grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'selected-token'],
    ]);

    return Mailbox::forGrant(new GrantContext('connection', 'grant', '1', 'ms-graph', 'same@example.com'), $tokens, new Client(['handler' => $stack]));
}

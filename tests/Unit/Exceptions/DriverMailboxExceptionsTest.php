<?php

declare(strict_types=1);

use Pyle\Mailbox\Exceptions\AuthenticationException;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Exceptions\ProviderServerException;
use Pyle\Mailbox\Exceptions\ProviderTransportException;

it('captures authentication guidance and previous failures', function (): void {
    $previous = new RuntimeException('previous authentication failure');
    $exception = new AuthenticationException(
        message: 'Authentication failed.',
        guidance: 'Refresh the delegated credentials.',
        code: 401,
        previous: $previous,
    );

    expect($exception)->toBeInstanceOf(MailboxException::class);
    expect($exception->guidance)->toBe('Refresh the delegated credentials.');
    expect($exception->getMessage())->toBe('Authentication failed.');
    expect($exception->getCode())->toBe(401);
    expect($exception->getPrevious())->toBe($previous);
});

it('captures provider status and exhausted retry metadata', function (): void {
    $previous = new RuntimeException('gateway timeout');
    $exception = new ProviderServerException(
        statusCode: 503,
        attemptsExhausted: 4,
        message: 'Provider returned 503 after retries.',
        code: 503,
        previous: $previous,
    );

    expect($exception)->toBeInstanceOf(MailboxException::class);
    expect($exception->statusCode)->toBe(503);
    expect($exception->attemptsExhausted)->toBe(4);
    expect($exception->getMessage())->toBe('Provider returned 503 after retries.');
    expect($exception->getCode())->toBe(503);
    expect($exception->getPrevious())->toBe($previous);
});

it('captures provider transport retry metadata', function (): void {
    $previous = new RuntimeException('connection reset');
    $exception = new ProviderTransportException(
        message: 'Provider transport failure.',
        endpoint: 'users/test/messages',
        mailbox: 'test@example.com',
        attemptsExhausted: 2,
        retryDelay: 4,
        previous: $previous,
    );

    expect($exception)->toBeInstanceOf(MailboxException::class);
    expect($exception->endpoint)->toBe('users/test/messages');
    expect($exception->mailbox)->toBe('test@example.com');
    expect($exception->attemptsExhausted)->toBe(2);
    expect($exception->retryDelay)->toBe(4);
    expect($exception->getMessage())->toBe('Provider transport failure.');
    expect($exception->getPrevious())->toBe($previous);
});

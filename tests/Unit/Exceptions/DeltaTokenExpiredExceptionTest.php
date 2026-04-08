<?php

declare(strict_types=1);

use Pyle\Mailbox\Exceptions\DeltaTokenExpiredException;
use Pyle\Mailbox\Exceptions\MailboxException;

it('captures the expired mailbox and folder context', function (): void {
    $previous = new RuntimeException('previous failure');
    $exception = new DeltaTokenExpiredException(
        mailbox: 'finance@example.com',
        folderId: 'inbox',
        message: 'Delta token expired.',
        code: 410,
        previous: $previous,
    );

    expect($exception)->toBeInstanceOf(MailboxException::class);
    expect($exception->mailbox)->toBe('finance@example.com');
    expect($exception->folderId)->toBe('inbox');
    expect($exception->getMessage())->toBe('Delta token expired.');
    expect($exception->getCode())->toBe(410);
    expect($exception->getPrevious())->toBe($previous);
});

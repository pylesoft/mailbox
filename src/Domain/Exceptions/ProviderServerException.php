<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Exceptions;

final class ProviderServerException extends MailboxException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly int $attemptsExhausted,
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}

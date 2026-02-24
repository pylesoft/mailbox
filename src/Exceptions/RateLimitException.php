<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Exceptions;

final class RateLimitException extends MailboxException
{
    public function __construct(
        public readonly int $retryAfter,
        public readonly string $mailbox,
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}

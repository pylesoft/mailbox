<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Exceptions;

final class AuthenticationException extends MailboxException
{
    public function __construct(
        string $message,
        public readonly ?string $guidance = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}

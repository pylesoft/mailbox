<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Exceptions;

final class ApiRequestException extends MailboxException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $endpoint = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}

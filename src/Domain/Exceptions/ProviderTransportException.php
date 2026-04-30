<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Exceptions;

final class ProviderTransportException extends MailboxException
{
    public function __construct(
        string $message,
        public readonly ?string $endpoint = null,
        public readonly ?string $mailbox = null,
        public readonly int $attemptsExhausted = 1,
        public readonly ?int $retryDelay = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}

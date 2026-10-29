<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Exceptions;

final class GrantRefreshException extends MailboxException
{
    public function __construct(string $message, public readonly bool $retryable, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }
}

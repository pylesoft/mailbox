<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Exceptions;

final class MailboxAccessDeniedException extends MailboxException
{
    public function __construct(
        string $mailbox,
        string $message,
        public readonly ?string $guidance = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $this->mailbox = $mailbox;
        parent::__construct($message, $code, $previous);
    }

    public readonly string $mailbox;
}

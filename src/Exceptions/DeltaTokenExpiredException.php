<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Exceptions;

final class DeltaTokenExpiredException extends MailboxException
{
    public function __construct(
        public readonly string $mailbox,
        public readonly string $folderId,
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}

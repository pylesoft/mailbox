<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Exceptions;

final class ResourceNotFoundException extends MailboxException
{
    public function __construct(
        public readonly string $resourceType,
        public readonly string $resourceId,
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}

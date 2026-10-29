<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Exceptions;

final class ApiRequestException extends MailboxException
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $endpoint = null,
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?string $providerCode = null,
        public readonly array $headers = [],
    ) {
        parent::__construct($message, $code, $previous);
    }
}

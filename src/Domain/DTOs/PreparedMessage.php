<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

final readonly class PreparedMessage
{
    /**
     * @param  list<EmailAddressDto>  $to
     * @param  list<EmailAddressDto>  $cc
     * @param  list<EmailAddressDto>  $bcc
     * @param  array<string, string>  $headers
     * @param  list<mixed>  $files
     */
    public function __construct(
        public EmailAddressDto $sender,
        public array $to,
        public string $subject,
        public string $correlationId,
        public ?string $textBody = null,
        public ?string $htmlBody = null,
        public array $cc = [],
        public array $bcc = [],
        public array $headers = [],
        public array $files = [],
    ) {}
}

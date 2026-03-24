<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Events;

final readonly class AttachmentSkipped
{
    public function __construct(
        public string $driver,
        public string $mailbox,
        public string $messageId,
        public string $attachmentId,
        public string $path,
    ) {}
}

<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Events;

final readonly class AttachmentDownloaded
{
    public function __construct(
        public string $driver,
        public string $mailbox,
        public string $messageId,
        public string $attachmentId,
        public string $path,
        public string $disk,
    ) {}
}

<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Pyle\Mailbox\Enums\PreparationOutcome;

final readonly class AttachmentPreparation
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public string $fileIdentity,
        public PreparationOutcome $outcome,
        public int $acknowledgedBytes,
        public ?string $attachmentId,
        public ?int $status,
        public array $headers = [],
    ) {}
}

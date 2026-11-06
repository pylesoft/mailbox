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
        #[\SensitiveParameter] private ?GraphUploadSession $session = null,
    ) {}

    public function session(): ?GraphUploadSession
    {
        return $this->session;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['fileIdentity' => $this->fileIdentity, 'outcome' => $this->outcome,
            'acknowledgedBytes' => $this->acknowledgedBytes, 'attachmentId' => $this->attachmentId,
            'status' => $this->status, 'headers' => $this->headers, 'expiresAt' => $this->session?->expiresAt];
    }
}

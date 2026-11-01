<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Pyle\Mailbox\Enums\PreparationOutcome;

final readonly class SubmissionPreparation
{
    /**
     * @param  array<string, list<string>>  $headers
     * @param  list<AttachmentPreparation>  $attachments
     */
    public function __construct(
        public GrantContext $context,
        public PreparationOutcome $outcome,
        public string $correlationId,
        public ?string $draftId,
        public ?int $status,
        public array $headers = [],
        #[\SensitiveParameter] private string $intentFingerprint = '',
        public ?string $conversationId = null,
        public array $attachments = [],
    ) {}

    public function intentFingerprint(): string
    {
        return $this->intentFingerprint;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'context' => $this->context, 'outcome' => $this->outcome, 'correlationId' => $this->correlationId,
            'draftId' => $this->draftId, 'status' => $this->status, 'headers' => $this->headers, 'conversationId' => $this->conversationId,
            'attachments' => $this->attachments,
        ];
    }
}

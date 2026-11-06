<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Pyle\Mailbox\Enums\SubmissionEvidenceOutcome;

final readonly class SubmissionEvidence
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public string $provider,
        public GrantContext $context,
        public string $correlationId,
        public ?string $artifactId,
        public SubmissionEvidenceOutcome $outcome,
        public ?int $status,
        public array $headers = [],
        public ?string $conversationId = null,
    ) {}
}

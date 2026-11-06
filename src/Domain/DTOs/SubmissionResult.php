<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Pyle\Mailbox\Enums\SubmissionOutcome;

final readonly class SubmissionResult
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public string $provider,
        public SubmissionOutcome $outcome,
        public string $correlationId,
        public ?int $status,
        public array $headers = [],
        public ?string $messageId = null,
        public ?string $threadId = null,
        public ?GrantContext $context = null,
    ) {}
}

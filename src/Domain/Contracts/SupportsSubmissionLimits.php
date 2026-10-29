<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\DTOs\SubmissionLimits;

interface SupportsSubmissionLimits
{
    public function withSubmissionLimits(SubmissionLimits $limits): SupportsMessageSubmission;
}

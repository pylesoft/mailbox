<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail\Contracts;

use Pyle\Mailbox\DTOs\SubmissionEvidence;
use Pyle\Mailbox\DTOs\SubmissionResult;

interface SupportsGmailSubmissionInspection
{
    public function inspectSubmission(SubmissionResult $submission): SubmissionEvidence;
}

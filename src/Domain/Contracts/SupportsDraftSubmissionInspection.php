<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\SubmissionEvidence;
use Pyle\Mailbox\DTOs\SubmissionPreparation;

interface SupportsDraftSubmissionInspection
{
    public function inspectSubmission(SubmissionPreparation $preparation, PreparedMessage $message): SubmissionEvidence;
}

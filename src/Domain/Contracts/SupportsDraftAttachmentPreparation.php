<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\SubmissionLimits;
use Pyle\Mailbox\DTOs\SubmissionPreparation;

interface SupportsDraftAttachmentPreparation extends SupportsDraftSubmission
{
    public function prepareAttachments(SubmissionPreparation $preparation, PreparedMessage $message, ?SubmissionLimits $limits = null, int $maxRequestBytes = 4_000_000): SubmissionPreparation;
}

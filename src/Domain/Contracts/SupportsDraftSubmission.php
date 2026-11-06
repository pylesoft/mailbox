<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\SubmissionPreparation;
use Pyle\Mailbox\DTOs\SubmissionResult;

interface SupportsDraftSubmission
{
    public function prepareSubmission(PreparedMessage $message, int $maxRequestBytes = 4_000_000): SubmissionPreparation;

    public function submitPrepared(SubmissionPreparation $preparation, PreparedMessage $message): SubmissionResult;
}

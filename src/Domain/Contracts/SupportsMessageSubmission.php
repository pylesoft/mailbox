<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\SubmissionResult;

interface SupportsMessageSubmission
{
    public function submit(PreparedMessage $message, int $maxRequestBytes = 1_048_576): SubmissionResult;
}

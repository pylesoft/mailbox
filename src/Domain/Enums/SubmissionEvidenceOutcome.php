<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Enums;

enum SubmissionEvidenceOutcome: string
{
    case SentCopyObserved = 'sent_copy_observed';
    case Inconclusive = 'inconclusive';
}

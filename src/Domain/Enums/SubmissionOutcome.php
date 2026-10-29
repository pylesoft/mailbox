<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Enums;

enum SubmissionOutcome: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Uncertain = 'uncertain';
}

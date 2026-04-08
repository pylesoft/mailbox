<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\Models\Mailbox;

interface MailboxResourceResolver
{
    public function forMailbox(Mailbox $mailbox): MailboxResource;
}

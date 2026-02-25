<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail\Contracts;

use Pyle\Mailbox\Drivers\Gmail\GmailClient;

interface SupportsRawClient
{
    public function raw(): GmailClient;
}

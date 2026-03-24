<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

interface MailboxDriverResolver
{
    public function driver(?string $driver = null): MailboxDriver;
}

<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\DTOs\ConnectionTestResult;
use Pyle\Mailbox\DTOs\HealthCheckResult;

interface MailboxDriver
{
    public function mailbox(string $emailAddress): MailboxResource;

    public function testConnection(?string $emailAddress = null): ConnectionTestResult;

    public function healthCheck(): HealthCheckResult;
}

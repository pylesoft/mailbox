<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Tests\Support;

use Pyle\Mailbox\Contracts\MailboxDriver;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphDriver;
use Pyle\Mailbox\DTOs\ConnectionTestResult;
use Pyle\Mailbox\DTOs\HealthCheckResult;

final class LegacyCustomDriver implements MailboxDriver
{
    private MsGraphDriver $driver;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->driver = new MsGraphDriver($config);
    }

    public function mailbox(string $emailAddress): MailboxResource
    {
        return $this->driver->mailbox($emailAddress);
    }

    public function testConnection(?string $emailAddress = null): ConnectionTestResult
    {
        return $this->driver->testConnection($emailAddress);
    }

    public function healthCheck(): HealthCheckResult
    {
        return $this->driver->healthCheck();
    }
}

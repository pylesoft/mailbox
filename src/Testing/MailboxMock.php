<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Testing;

use Mockery\MockInterface;
use Pyle\Mailbox\Contracts\MailboxDriver;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Drivers\Gmail\Contracts\SupportsRawClient as SupportsGmailRawClient;
use Pyle\Mailbox\Drivers\Gmail\GmailClient;
use Pyle\Mailbox\Drivers\MsGraph\Contracts\SupportsRawClient;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\DTOs\ConnectionTestResult;
use Pyle\Mailbox\DTOs\HealthCheckResult;
use Pyle\Mailbox\Facades\Mailbox;
use RuntimeException;

final class MailboxMock
{
    /**
     * Bind a mock raw Microsoft Graph client to `Mailbox::driver('ms-graph')`.
     *
     * @return MockInterface&GraphClient
     */
    public static function mockMsGraphRawClient(string $driver = 'ms-graph'): object
    {
        self::ensureMockeryInstalled();

        /** @var MockInterface&GraphClient $rawClientMock */
        $rawClientMock = \Mockery::mock(GraphClient::class);

        $driverInstance = new class($rawClientMock) implements MailboxDriver, SupportsRawClient
        {
            public function __construct(private readonly GraphClient $rawClient) {}

            public function mailbox(string $emailAddress): MailboxResource
            {
                throw new RuntimeException('MailboxMock fake driver only supports raw() in tests.');
            }

            public function testConnection(?string $emailAddress = null): ConnectionTestResult
            {
                throw new RuntimeException('MailboxMock fake driver only supports raw() in tests.');
            }

            public function healthCheck(): HealthCheckResult
            {
                throw new RuntimeException('MailboxMock fake driver only supports raw() in tests.');
            }

            public function raw(): GraphClient
            {
                return $this->rawClient;
            }
        };

        Mailbox::shouldReceive('driver')
            ->with($driver)
            ->andReturn($driverInstance);

        if ($driver === (string) config('mailbox.default', 'ms-graph')) {
            Mailbox::shouldReceive('driver')
                ->withNoArgs()
                ->andReturn($driverInstance);
        }

        return $rawClientMock;
    }

    /**
     * Bind a mock raw Gmail client to `Mailbox::driver('gmail')`.
     *
     * @return MockInterface&GmailClient
     */
    public static function mockGmailRawClient(string $driver = 'gmail'): object
    {
        self::ensureMockeryInstalled();

        /** @var MockInterface&GmailClient $rawClientMock */
        $rawClientMock = \Mockery::mock(GmailClient::class);

        $driverInstance = new class($rawClientMock) implements MailboxDriver, SupportsGmailRawClient
        {
            public function __construct(private readonly GmailClient $rawClient) {}

            public function mailbox(string $emailAddress): MailboxResource
            {
                throw new RuntimeException('MailboxMock fake driver only supports raw() in tests.');
            }

            public function testConnection(?string $emailAddress = null): ConnectionTestResult
            {
                throw new RuntimeException('MailboxMock fake driver only supports raw() in tests.');
            }

            public function healthCheck(): HealthCheckResult
            {
                throw new RuntimeException('MailboxMock fake driver only supports raw() in tests.');
            }

            public function raw(): GmailClient
            {
                return $this->rawClient;
            }
        };

        Mailbox::shouldReceive('driver')
            ->with($driver)
            ->andReturn($driverInstance);

        if ($driver === (string) config('mailbox.default', 'ms-graph')) {
            Mailbox::shouldReceive('driver')
                ->withNoArgs()
                ->andReturn($driverInstance);
        }

        return $rawClientMock;
    }

    private static function ensureMockeryInstalled(): void
    {
        if (! class_exists(\Mockery::class)) {
            throw new RuntimeException('MailboxMock requires mockery/mockery in your dev dependencies.');
        }
    }
}

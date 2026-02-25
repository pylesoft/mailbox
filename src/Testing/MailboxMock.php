<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Testing;

use Pyle\Mailbox\Contracts\MailboxDriver;
use Pyle\Mailbox\Drivers\Gmail\Contracts\SupportsRawClient as SupportsGmailRawClient;
use Pyle\Mailbox\Drivers\Gmail\GmailClient;
use Pyle\Mailbox\Drivers\MsGraph\Contracts\SupportsRawClient;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Facades\Mailbox;
use RuntimeException;

final class MailboxMock
{
    /**
     * Bind a mock raw Microsoft Graph client to `Mailbox::driver('ms-graph')`.
     *
     * @return \Mockery\MockInterface&GraphClient
     */
    public static function mockMsGraphRawClient(string $driver = 'ms-graph'): object
    {
        self::ensureMockeryInstalled();

        /** @var \Mockery\MockInterface&MailboxDriver&SupportsRawClient $driverMock */
        $driverMock = \Mockery::mock(MailboxDriver::class, SupportsRawClient::class);
        /** @var \Mockery\MockInterface&GraphClient $rawClientMock */
        $rawClientMock = \Mockery::mock(GraphClient::class);

        /** @phpstan-ignore-next-line */
        $driverMock->shouldReceive('raw')->andReturn($rawClientMock);

        Mailbox::shouldReceive('driver')
            ->with($driver)
            ->andReturn($driverMock);

        if ($driver === (string) config('mailbox.default', 'ms-graph')) {
            Mailbox::shouldReceive('driver')
                ->withNoArgs()
                ->andReturn($driverMock);
        }

        return $rawClientMock;
    }

    /**
     * Bind a mock raw Gmail client to `Mailbox::driver('gmail')`.
     *
     * @return \Mockery\MockInterface&GmailClient
     */
    public static function mockGmailRawClient(string $driver = 'gmail'): object
    {
        self::ensureMockeryInstalled();

        /** @var \Mockery\MockInterface&MailboxDriver&SupportsGmailRawClient $driverMock */
        $driverMock = \Mockery::mock(MailboxDriver::class, SupportsGmailRawClient::class);
        /** @var \Mockery\MockInterface&GmailClient $rawClientMock */
        $rawClientMock = \Mockery::mock(GmailClient::class);

        /** @phpstan-ignore-next-line */
        $driverMock->shouldReceive('raw')->andReturn($rawClientMock);

        Mailbox::shouldReceive('driver')
            ->with($driver)
            ->andReturn($driverMock);

        if ($driver === (string) config('mailbox.default', 'ms-graph')) {
            Mailbox::shouldReceive('driver')
                ->withNoArgs()
                ->andReturn($driverMock);
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

<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\Gmail\Contracts\SupportsRawClient as SupportsGmailRawClient;
use Pyle\Mailbox\Drivers\MsGraph\Contracts\SupportsRawClient;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Testing\MailboxMock;

it('binds a raw ms-graph client mock to the mailbox facade', function (): void {
    $rawClient = MailboxMock::mockMsGraphRawClient();

    $rawClient->shouldReceive('get')
        ->once()
        ->with('users/test@example.com/messages')
        ->andReturn(['value' => []]);

    $driver = Mailbox::driver('ms-graph');

    expect($driver)->toBeInstanceOf(SupportsRawClient::class);
    expect($driver->raw()->get('users/test@example.com/messages'))->toBe(['value' => []]);
});

it('binds the default driver call when no name is provided', function (): void {
    config()->set('mailbox.default', 'ms-graph');

    $rawClient = MailboxMock::mockMsGraphRawClient();

    $rawClient->shouldReceive('get')
        ->once()
        ->with('users/default@example.com/messages')
        ->andReturn(['value' => []]);

    /** @var SupportsRawClient $driver */
    $driver = Mailbox::driver();

    expect($driver->raw()->get('users/default@example.com/messages'))->toBe(['value' => []]);
});

it('binds a raw gmail client mock to the mailbox facade', function (): void {
    config()->set('mailbox.default', 'gmail');

    $rawClient = MailboxMock::mockGmailRawClient();

    $rawClient->shouldReceive('get')
        ->once()
        ->with('users/me/messages')
        ->andReturn(['messages' => []]);

    /** @var SupportsGmailRawClient $driver */
    $driver = Mailbox::driver('gmail');

    expect($driver)->toBeInstanceOf(SupportsGmailRawClient::class);
    expect($driver->raw()->get('users/me/messages'))->toBe(['messages' => []]);
});

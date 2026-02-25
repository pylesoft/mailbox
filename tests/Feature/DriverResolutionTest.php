<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\Gmail\GmailDriver;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphDriver;
use Pyle\Mailbox\Exceptions\DriverNotConfiguredException;
use Pyle\Mailbox\Facades\Mailbox;

it('resolves default driver', function (): void {
    config()->set('mailbox.default', 'ms-graph');
    config()->set('mailbox.drivers.ms-graph', [
        'driver' => 'ms-graph',
        'tenant_id' => 'tenant',
        'client_id' => 'client',
        'client_secret' => 'secret',
    ]);

    expect(Mailbox::driver())->toBeInstanceOf(MsGraphDriver::class);
});

it('throws for unknown driver', function (): void {
    Mailbox::driver('unknown');
})->throws(DriverNotConfiguredException::class);

it('resolves gmail driver from canonical and alias keys', function (): void {
    config()->set('mailbox.drivers.gmail', [
        'driver' => 'gmail',
        'service_account_json' => json_encode([
            'client_email' => 'svc@example.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n",
        ]),
    ]);
    config()->set('mailbox.drivers.google-workspace', [
        'driver' => 'gmail',
    ]);

    expect(Mailbox::driver('gmail'))->toBeInstanceOf(GmailDriver::class);
    expect(Mailbox::driver('google-workspace'))->toBeInstanceOf(GmailDriver::class);
});

<?php

declare(strict_types=1);

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

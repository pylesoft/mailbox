<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Pyle\Mailbox\MailboxServiceProvider;

abstract class SdkTestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MailboxServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mailbox.persistence.enabled', false);
        $app['config']->set('database.default', 'sdk-forbidden');
        $app['config']->set('database.connections.sdk-forbidden', ['driver' => 'sdk-forbidden']);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('mailbox.log_channel', 'null');
    }
}

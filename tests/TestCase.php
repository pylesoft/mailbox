<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Pyle\Mailbox\MailboxServiceProvider;
use Pyle\Mailbox\Tests\Support\ParallelTestingDatabase;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected static ?string $parallelDatabasePath = null;

    protected function getPackageProviders($app): array
    {
        return [MailboxServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $databasePath = ParallelTestingDatabase::resolve($app->basePath());
        ParallelTestingDatabase::prepare($databasePath);

        static::$parallelDatabasePath = $databasePath !== ':memory:' ? $databasePath : null;

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('filesystems.default', 'local');
        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (is_string(static::$parallelDatabasePath)) {
            ParallelTestingDatabase::cleanup(static::$parallelDatabasePath);
            static::$parallelDatabasePath = null;
        }
    }
}

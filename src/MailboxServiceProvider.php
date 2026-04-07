<?php

declare(strict_types=1);

namespace Pyle\Mailbox;

use Illuminate\Support\ServiceProvider;
use Pyle\Mailbox\Commands\FindFolderCommand;
use Pyle\Mailbox\Commands\HealthCheckCommand;
use Pyle\Mailbox\Commands\ListFoldersCommand;
use Pyle\Mailbox\Commands\StatusCommand;
use Pyle\Mailbox\Commands\SyncCommand;
use Pyle\Mailbox\Commands\TestAccessCommand;
use Pyle\Mailbox\Contracts\MailboxDriverResolver;
use Pyle\Mailbox\Contracts\MailboxResourceResolver;

class MailboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mailbox.php', 'mailbox');

        $this->app->singleton(MailboxManager::class, function ($app): MailboxManager {
            return new MailboxManager($app);
        });
        $this->app->bind(MailboxResourceResolver::class, function ($app): MailboxManager {
            return $app->make(MailboxManager::class);
        });
        $this->app->bind(MailboxDriverResolver::class, function ($app): MailboxManager {
            return $app->make(MailboxManager::class);
        });

        $this->app->alias(MailboxManager::class, 'mailbox');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (config('logging.channels.mailbox') === null) {
            config()->set('logging.channels.mailbox', [
                'driver' => 'daily',
                'path' => storage_path('logs/mailbox.log'),
                'level' => (string) config('mailbox.log_level', config('app.debug') ? 'debug' : 'info'),
                'days' => 14,
            ]);
        }

        if ((bool) config('mailbox.oauth.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/mailbox.php' => config_path('mailbox.php'),
        ], 'mailbox-config');

        $this->publishes([
            __DIR__.'/../stubs' => base_path('stubs/mailbox'),
        ], 'mailbox-stubs');

        $this->commands([
            TestAccessCommand::class,
            ListFoldersCommand::class,
            FindFolderCommand::class,
            HealthCheckCommand::class,
            SyncCommand::class,
            StatusCommand::class,
        ]);
    }
}

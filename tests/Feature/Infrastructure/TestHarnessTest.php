<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Pyle\Mailbox\Contracts\MailboxDriverResolver;
use Pyle\Mailbox\Contracts\MailboxResourceResolver;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\MailboxManager;
use Pyle\Mailbox\MailboxServiceProvider;
use Pyle\Mailbox\Tests\Support\ParallelTestingDatabase;
use Workbench\App\Providers\WorkbenchServiceProvider;

it('boots package provider and resolves mailbox services', function (): void {
    expect(app()->bound(MailboxManager::class))->toBeTrue();
    expect(app()->bound(MailboxResourceResolver::class))->toBeTrue();
    expect(app()->bound(MailboxDriverResolver::class))->toBeTrue();
    expect(app('mailbox'))->toBeInstanceOf(MailboxManager::class);
    expect(app(MailboxResourceResolver::class))->toBeInstanceOf(MailboxManager::class);
    expect(app(MailboxDriverResolver::class))->toBeInstanceOf(MailboxManager::class);
    expect(Mailbox::getFacadeRoot())->toBeInstanceOf(MailboxManager::class);
});

it('registers publishable assets under expected tags', function (): void {
    expect(ServiceProvider::pathsToPublish(MailboxServiceProvider::class, 'mailbox-config'))
        ->not->toBeEmpty();
    expect(ServiceProvider::pathsToPublish(MailboxServiceProvider::class, 'mailbox-stubs'))
        ->not->toBeEmpty();
});

it('does not load oauth routes when oauth is disabled', function (): void {
    config()->set('mailbox.oauth.enabled', false);

    $provider = new class(app()) extends MailboxServiceProvider
    {
        public bool $loadedOauthRoutes = false;

        protected function loadRoutesFrom($path): void
        {
            $this->loadedOauthRoutes = str_ends_with((string) $path, '/routes/web.php');
        }
    };

    $provider->boot();

    expect($provider->loadedOauthRoutes)->toBeFalse();
});

it('loads oauth routes when oauth is enabled', function (): void {
    config()->set('mailbox.oauth.enabled', true);

    $provider = new class(app()) extends MailboxServiceProvider
    {
        public bool $loadedOauthRoutes = false;

        protected function loadRoutesFrom($path): void
        {
            $this->loadedOauthRoutes = str_ends_with((string) $path, '/routes/web.php');
        }
    };

    $provider->boot();

    expect($provider->loadedOauthRoutes)->toBeTrue();
});

it('always loads package migrations automatically', function (): void {
    $provider = new class(app()) extends MailboxServiceProvider
    {
        public bool $loadedMigrations = false;

        protected function loadMigrationsFrom($paths): void
        {
            $this->loadedMigrations = true;
        }
    };

    $provider->boot();

    expect($provider->loadedMigrations)->toBeTrue();
});

it('resolves sqlite paths for serial and parallel test modes', function (): void {
    expect(ParallelTestingDatabase::resolve(base_path(), ''))->toBe(':memory:');

    $parallelPath = ParallelTestingDatabase::resolve(base_path(), 'worker-7');

    expect($parallelPath)->toEndWith('/storage/framework/testing/test-worker-7.sqlite');
});

it('boots workbench tooling and runs artisan smoke commands', function (): void {
    expect(class_exists(WorkbenchServiceProvider::class))->toBeTrue();

    $this->artisan('about')->assertSuccessful();
    $this->artisan('route:list')->assertSuccessful();
});

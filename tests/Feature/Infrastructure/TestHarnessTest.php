<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\MailboxManager;
use Pyle\Mailbox\MailboxServiceProvider;
use Pyle\Mailbox\Tests\Support\ParallelTestingDatabase;
use Workbench\App\Providers\WorkbenchServiceProvider;

it('boots package provider and resolves mailbox services', function (): void {
    expect(app()->bound(MailboxManager::class))->toBeTrue();
    expect(app('mailbox'))->toBeInstanceOf(MailboxManager::class);
    expect(Mailbox::getFacadeRoot())->toBeInstanceOf(MailboxManager::class);
});

it('registers publishable assets under expected tags', function (): void {
    expect(ServiceProvider::pathsToPublish(MailboxServiceProvider::class, 'mailbox-config'))
        ->not->toBeEmpty();
    expect(ServiceProvider::pathsToPublish(MailboxServiceProvider::class, 'mailbox-migrations'))
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

it('skips package migration loading when published migration names exist', function (): void {
    $base = storage_path('framework/testing/migration-provider-'.uniqid('', true));
    $packagePath = $base.'/package';
    $appPath = $base.'/app';

    mkdir($packagePath, 0777, true);
    mkdir($appPath, 0777, true);

    file_put_contents($packagePath.'/2026_03_01_000005_create_mailbox_messages_table.php', '<?php');
    file_put_contents($appPath.'/2026_03_02_000010_create_mailbox_messages_table.php', '<?php');

    $provider = new class(app(), $packagePath, $appPath) extends MailboxServiceProvider
    {
        public bool $loadedMigrations = false;

        public function __construct($app, private readonly string $packagePath, private readonly string $appPath)
        {
            parent::__construct($app);
        }

        protected function packageMigrationPath(): string
        {
            return $this->packagePath;
        }

        protected function applicationMigrationPath(): string
        {
            return $this->appPath;
        }

        protected function loadMigrationsFrom($paths): void
        {
            $this->loadedMigrations = true;
        }
    };

    try {
        $provider->boot();
        expect($provider->loadedMigrations)->toBeFalse();
    } finally {
        foreach (glob($packagePath.'/*.php') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($appPath.'/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($packagePath);
        @rmdir($appPath);
        @rmdir($base);
    }
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

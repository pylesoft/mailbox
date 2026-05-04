<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Pyle\Mailbox\Commands\HealthCheckCommand;
use Pyle\Mailbox\Commands\ListFoldersCommand;
use Pyle\Mailbox\Commands\StatusCommand;
use Pyle\Mailbox\Commands\SyncCommand;
use Pyle\Mailbox\Commands\TestAccessCommand;
use Pyle\Mailbox\Contracts\FolderQueryBuilder;
use Pyle\Mailbox\Contracts\FolderResource;
use Pyle\Mailbox\Contracts\MailboxDriver;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\DTOs\ConnectionTestResult;
use Pyle\Mailbox\DTOs\FolderDto;
use Pyle\Mailbox\DTOs\HealthCheckResult;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\MailboxManager;
use Symfony\Component\Console\Command\Command;

beforeEach(function (): void {
    config()->set('mailbox.default', 'healthy-fake');
    config()->set('mailbox.drivers.healthy-fake', ['driver' => 'healthy-fake']);
    config()->set('mailbox.drivers.unhealthy-fake', ['driver' => 'unhealthy-fake']);
    config()->set('mailbox.drivers.denied-fake', ['driver' => 'denied-fake']);
    config()->set('mailbox.drivers.folders-fake', ['driver' => 'folders-fake']);

    registerMailboxTestDriver(
        'healthy-fake',
        new ConnectionTestResult(
            success: true,
            error: null,
            latencyMs: 25,
            authenticatedAs: 'healthy@example.com',
            accessibleMailboxes: ['healthy@example.com'],
        ),
        new HealthCheckResult(
            healthy: true,
            tokenValid: true,
            tokenExpiresIn: 3600,
            apiReachable: true,
            latencyMs: 12,
            secretExpiresAt: null,
            secretExpirationWarning: false,
        ),
    );
    registerMailboxTestDriver(
        'unhealthy-fake',
        new ConnectionTestResult(
            success: true,
            error: null,
            latencyMs: 40,
            authenticatedAs: 'healthy@example.com',
            accessibleMailboxes: ['healthy@example.com'],
        ),
        new HealthCheckResult(
            healthy: false,
            tokenValid: false,
            tokenExpiresIn: null,
            apiReachable: false,
            latencyMs: null,
            secretExpiresAt: null,
            secretExpirationWarning: true,
        ),
    );
    registerMailboxTestDriver(
        'denied-fake',
        new ConnectionTestResult(
            success: false,
            error: 'Mailbox access denied.',
            latencyMs: 75,
            authenticatedAs: null,
            accessibleMailboxes: [],
        ),
        new HealthCheckResult(
            healthy: true,
            tokenValid: true,
            tokenExpiresIn: 3600,
            apiReachable: true,
            latencyMs: 10,
            secretExpiresAt: null,
            secretExpirationWarning: false,
        ),
    );
    registerMailboxTestDriver(
        'folders-fake',
        new ConnectionTestResult(
            success: true,
            error: null,
            latencyMs: 30,
            authenticatedAs: 'folders@example.com',
            accessibleMailboxes: ['folders@example.com'],
        ),
        new HealthCheckResult(
            healthy: true,
            tokenValid: true,
            tokenExpiresIn: 3600,
            apiReachable: true,
            latencyMs: 8,
            secretExpiresAt: null,
            secretExpirationWarning: false,
        ),
        new CommandCoverageMailboxResource(new CommandCoverageFolderQueryBuilder(
            tree: collect([
                new FolderDto('inbox', 'Inbox', null, 1, 10, 2, 'Inbox', WellKnownFolder::INBOX, [
                    new FolderDto('processed', 'Processed', 'inbox', 0, 2, 0, 'Inbox/Processed', null, []),
                ]),
            ]),
            foundFolder: null,
        )),
    );
});

it('fails mailbox:health when the driver reports an unhealthy mailbox integration', function (): void {
    $exitCode = Artisan::call('mailbox:health', ['--driver' => 'unhealthy-fake']);
    $output = Artisan::output();

    expect($exitCode)->toBe(HealthCheckCommand::FAILURE);
    expect($output)->toContain('Mailbox Health Check');
    expect($output)->toContain('unhealthy-fake');
    expect($output)->toContain('Unhealthy');
});

it('fails mailbox:test-access when the driver denies mailbox access', function (): void {
    $exitCode = Artisan::call('mailbox:test-access', [
        'email' => 'denied@example.com',
        '--driver' => 'denied-fake',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(TestAccessCommand::FAILURE);
    expect($output)->toContain('Mailbox access denied.');
    expect($output)->not->toContain('Connected successfully');
});

it('renders the folder tree for mailbox:folders', function (): void {
    $exitCode = Artisan::call('mailbox:folders', [
        'email' => 'folders@example.com',
        '--driver' => 'folders-fake',
        '--tree' => true,
        '--max-depth' => 3,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(ListFoldersCommand::SUCCESS);
    expect($output)->toContain('Folder Tree for folders@example.com');
    expect($output)->toContain('Inbox (2 unread / 10 total)');
    expect($output)->toContain('Processed (0 unread / 2 total)');
});

it('fails mailbox:find-folder when the driver cannot find the requested folder', function (): void {
    $exitCode = Artisan::call('mailbox:find-folder', [
        'email' => 'folders@example.com',
        'name' => 'Missing',
        '--driver' => 'folders-fake',
        '--root' => 'Inbox',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(Command::FAILURE);
    expect($output)->toContain('Folder "Missing" was not found.');
    expect($output)->not->toContain('Found folder:');
});

it('shows an empty mailbox status successfully', function (): void {
    $exitCode = Artisan::call('mailbox:status');
    $output = Artisan::output();

    expect($exitCode)->toBe(StatusCommand::SUCCESS);
    expect($output)->toContain('No mailbox connections configured.');
});

it('reports when mailbox:sync cannot find any matching folders', function (): void {
    $exitCode = Artisan::call('mailbox:sync', ['--mailbox' => 'missing@example.com']);
    $output = Artisan::output();

    expect($exitCode)->toBe(SyncCommand::SUCCESS);
    expect($output)->toContain('No matching folders found.');
});

function registerMailboxTestDriver(
    string $name,
    ConnectionTestResult $connectionResult,
    HealthCheckResult $healthResult,
    ?MailboxResource $mailboxResource = null,
): void {
    /** @var MailboxManager $manager */
    $manager = app(MailboxManager::class);
    $manager->extend($name, fn (): MailboxDriver => new class($connectionResult, $healthResult, $mailboxResource) implements MailboxDriver
    {
        public function __construct(
            private readonly ConnectionTestResult $connectionResult,
            private readonly HealthCheckResult $healthResult,
            private readonly ?MailboxResource $mailboxResource,
        ) {}

        public function mailbox(string $emailAddress): MailboxResource
        {
            if ($this->mailboxResource instanceof MailboxResource) {
                return $this->mailboxResource;
            }

            throw new RuntimeException(sprintf('Mailbox resource not stubbed for %s.', $emailAddress));
        }

        public function testConnection(?string $emailAddress = null): ConnectionTestResult
        {
            return $this->connectionResult;
        }

        public function healthCheck(): HealthCheckResult
        {
            return $this->healthResult;
        }
    });
}

final class CommandCoverageMailboxResource implements MailboxResource
{
    public function __construct(
        private readonly FolderQueryBuilder $folders,
    ) {}

    public function messages(): MessageQueryBuilder
    {
        throw new RuntimeException('Not needed for command coverage tests.');
    }

    public function message(string $messageId): MessageResource
    {
        throw new RuntimeException('Not needed for command coverage tests.');
    }

    public function folders(): FolderQueryBuilder
    {
        return $this->folders;
    }

    public function folder(string|WellKnownFolder $folderId): FolderResource
    {
        throw new RuntimeException('Not needed for command coverage tests.');
    }
}

final class CommandCoverageFolderQueryBuilder implements FolderQueryBuilder
{
    /**
     * @param  Collection<int, FolderDto>  $tree
     */
    public function __construct(
        private readonly Collection $tree,
        private readonly ?FolderDto $foundFolder,
    ) {}

    public function get(): Collection
    {
        return $this->tree;
    }

    public function tree(int $maxDepth = 10): Collection
    {
        return $this->tree;
    }

    public function find(string $name, string|WellKnownFolder|null $root = null, bool $caseSensitive = true): ?FolderDto
    {
        return $this->foundFolder;
    }

    public function create(string $name, ?string $parentId = null): FolderDto
    {
        throw new RuntimeException('Not needed for command coverage tests.');
    }

    public function createPath(string $path): FolderDto
    {
        throw new RuntimeException('Not needed for command coverage tests.');
    }
}

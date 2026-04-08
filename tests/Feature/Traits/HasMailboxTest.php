<?php

declare(strict_types=1);

use Pyle\Mailbox\Contracts\FolderQueryBuilder;
use Pyle\Mailbox\Contracts\FolderResource;
use Pyle\Mailbox\Contracts\MailboxDriver;
use Pyle\Mailbox\Contracts\MailboxDriverResolver;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MailboxResourceResolver;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\Enums\ConnectionStatus;
use Pyle\Mailbox\Enums\SyncStatus;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Facades\Mailbox as MailboxFacade;
use Pyle\Mailbox\MailboxManager;
use Pyle\Mailbox\Models\Folder;
use Pyle\Mailbox\Models\Mailbox;
use Pyle\Mailbox\Models\MailboxConnection;

it('scopes folders by mailbox and connection and resolves mailbox resources through the resolver contract', function (): void {
    $connection = MailboxConnection::query()->create([
        'name' => 'Primary',
        'driver' => 'ms-graph',
        'status' => ConnectionStatus::CONNECTED,
        'config' => [],
    ]);

    $mailbox = Mailbox::query()->create([
        'mailbox_connection_id' => $connection->id,
        'email_address' => 'folders@example.com',
        'display_name' => 'Folders',
        'is_active' => true,
    ]);

    $folder = Folder::query()->create([
        'mailbox_id' => $mailbox->id,
        'folder_id' => 'inbox',
        'display_name' => 'Inbox',
        'is_active' => true,
        'sync_status' => SyncStatus::IDLE,
    ]);

    $resolver = new RecordingMailboxResourceResolver(new NullMailboxResource);
    app()->instance(MailboxResourceResolver::class, $resolver);

    expect(Folder::query()->forMailbox($mailbox)->pluck('id')->all())->toBe([$folder->id]);
    expect(Folder::query()->forConnection($connection)->pluck('id')->all())->toBe([$folder->id]);
    expect($folder->mailboxConnection?->is($connection))->toBeTrue();
    expect($folder->mailboxResource())->toBeInstanceOf(NullMailboxResource::class);
    expect($resolver->calls)->toBe([$mailbox->id]);
});

it('throws when mailboxResource is requested without an associated mailbox', function (): void {
    $folder = new Folder([
        'folder_id' => 'orphaned',
        'display_name' => 'Orphaned',
        'is_active' => true,
        'sync_status' => SyncStatus::IDLE,
    ]);

    expect(fn (): MailboxResource => $folder->mailboxResource())
        ->toThrow(RuntimeException::class, 'No mailbox is associated with this model.');
});

it('uses swapped mailbox managers for resolver-backed model helpers', function (): void {
    $connection = MailboxConnection::query()->create([
        'name' => 'Swappable',
        'driver' => 'ms-graph',
        'status' => ConnectionStatus::CONNECTED,
        'config' => [],
    ]);

    $mailbox = Mailbox::query()->create([
        'mailbox_connection_id' => $connection->id,
        'email_address' => 'swap@example.com',
        'display_name' => 'Swap',
        'is_active' => true,
    ]);

    $folder = Folder::query()->create([
        'mailbox_id' => $mailbox->id,
        'folder_id' => 'inbox',
        'display_name' => 'Inbox',
        'is_active' => true,
        'sync_status' => SyncStatus::IDLE,
    ]);

    app(MailboxResourceResolver::class);
    app(MailboxDriverResolver::class);

    $resource = new NullMailboxResource;
    $driver = new NullMailboxDriver;
    MailboxFacade::swap(new SwappableMailboxManager(app(), $resource, $driver));

    expect($folder->mailboxResource())->toBe($resource);
    expect($connection->resolveDriver())->toBe($driver);
});

final class RecordingMailboxResourceResolver implements MailboxResourceResolver
{
    /** @var array<int, int> */
    public array $calls = [];

    public function __construct(
        private readonly MailboxResource $resource,
    ) {}

    public function forMailbox(Mailbox $mailbox): MailboxResource
    {
        $this->calls[] = $mailbox->id;

        return $this->resource;
    }
}

final class NullMailboxResource implements MailboxResource
{
    public function messages(): MessageQueryBuilder
    {
        throw new RuntimeException('Not used in HasMailbox tests.');
    }

    public function message(string $messageId): MessageResource
    {
        throw new RuntimeException('Not used in HasMailbox tests.');
    }

    public function folders(): FolderQueryBuilder
    {
        throw new RuntimeException('Not used in HasMailbox tests.');
    }

    public function folder(string|WellKnownFolder $folderId): FolderResource
    {
        throw new RuntimeException('Not used in HasMailbox tests.');
    }
}

final class NullMailboxDriver implements MailboxDriver
{
    public function mailbox(string $emailAddress): MailboxResource
    {
        throw new RuntimeException('Not used in HasMailbox tests.');
    }

    public function testConnection(?string $emailAddress = null): \Pyle\Mailbox\DTOs\ConnectionTestResult
    {
        throw new RuntimeException('Not used in HasMailbox tests.');
    }

    public function healthCheck(): \Pyle\Mailbox\DTOs\HealthCheckResult
    {
        throw new RuntimeException('Not used in HasMailbox tests.');
    }
}

final class SwappableMailboxManager extends MailboxManager
{
    public function __construct(
        \Illuminate\Contracts\Container\Container $container,
        private readonly MailboxResource $resource,
        private readonly MailboxDriver $driver,
    ) {
        parent::__construct($container);
    }

    public function forMailbox(Mailbox $mailbox): MailboxResource
    {
        return $this->resource;
    }

    public function driver($driver = null): MailboxDriver
    {
        return $this->driver;
    }
}

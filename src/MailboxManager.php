<?php

declare(strict_types=1);

namespace Pyle\Mailbox;

use Illuminate\Support\Collection;
use Illuminate\Support\Manager;
use Pyle\Mailbox\Contracts\FolderResource;
use Pyle\Mailbox\Contracts\MailboxDriver;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\DTOs\ConnectionTestResult;
use Pyle\Mailbox\DTOs\HealthCheckResult;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Exceptions\DriverNotConfiguredException;
use Pyle\Mailbox\Models\MailboxConnection;
use Pyle\Mailbox\Models\MailboxMessage;
use Pyle\Mailbox\Models\MonitoredFolder;
use Pyle\Mailbox\Models\MonitoredMailbox;
use Pyle\Mailbox\Services\Folders\FolderLookupService;
use Pyle\Mailbox\Services\Persistence\MessageMoveService;
use Pyle\Mailbox\Services\Persistence\MessageSyncService;
use RuntimeException;

class MailboxManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('mailbox.default', 'ms-graph');
    }

    public function mailbox(string $emailAddress): MailboxResource
    {
        return $this->driver()->mailbox($emailAddress);
    }

    public function forMailbox(MonitoredMailbox $mailbox): MailboxResource
    {
        $connection = $mailbox->getRelationValue('connection');

        if (! $connection instanceof MailboxConnection) {
            throw new RuntimeException('Monitored mailbox does not have an associated connection.');
        }

        $driver = $connection->driver;

        return $this->driver($driver)->mailbox($mailbox->email_address);
    }

    public function forFolder(MonitoredFolder $folder): FolderResource
    {
        $mailbox = $folder->mailbox;

        if (! $mailbox instanceof MonitoredMailbox) {
            throw new RuntimeException('Monitored folder does not have an associated mailbox.');
        }

        return $this->forMailbox($mailbox)->folder($folder->folder_id);
    }

    public function testConnection(?string $emailAddress = null): ConnectionTestResult
    {
        return $this->driver()->testConnection($emailAddress);
    }

    public function healthCheck(): HealthCheckResult
    {
        return $this->driver()->healthCheck();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return Collection<int, MailboxMessage>
     */
    public function syncMailbox(MonitoredMailbox $mailbox, array $options = []): Collection
    {
        /** @var MessageSyncService $service */
        $service = $this->container->make(MessageSyncService::class);

        return $service->syncMailbox($mailbox, $options);
    }

    public function moveMessage(MailboxMessage $message, string|WellKnownFolder $destinationFolder): MailboxMessage
    {
        /** @var MessageMoveService $service */
        $service = $this->container->make(MessageMoveService::class);

        return $service->move($message, $destinationFolder);
    }

    /**
     * @return Collection<int, array{id: string, display_name: string, path: string, parent_id: string|null, child_folder_count: int|null}>
     */
    public function listFolderTree(MonitoredMailbox $mailbox, int $maxDepth = 10): Collection
    {
        /** @var FolderLookupService $service */
        $service = $this->container->make(FolderLookupService::class);

        return $service->listTree($mailbox, $maxDepth);
    }

    /**
     * @return array{id: string, display_name: string, path: string, parent_id: string|null, child_folder_count: int|null}|null
     */
    public function findFolderByName(
        MonitoredMailbox $mailbox,
        string $folderName,
        string|WellKnownFolder|null $root = null,
        bool $caseSensitive = true,
    ): ?array {
        /** @var FolderLookupService $service */
        $service = $this->container->make(FolderLookupService::class);

        return $service->findByName($mailbox, $folderName, $root, $caseSensitive);
    }

    /** @return Collection<int, FilterableField> */
    public function filterableFields(): Collection
    {
        /** @var Collection<int, FilterableField> $fields */
        $fields = collect(FilterableField::cases());

        return $fields;
    }

    /**
     * @param  string|null  $driver
     */
    public function driver($driver = null): MailboxDriver
    {
        $driverName = $driver ?? $this->getDefaultDriver();

        try {
            /** @var MailboxDriver $resolved */
            $resolved = parent::driver($driverName);

            return $resolved;
        } catch (\InvalidArgumentException $e) {
            throw DriverNotConfiguredException::forDriver((string) $driverName, array_keys((array) config('mailbox.drivers', [])), $e);
        }
    }

    /** @param string $driver */
    protected function createDriver($driver): MailboxDriver
    {
        if (isset($this->customCreators[$driver])) {
            /** @var MailboxDriver $driverInstance */
            $driverInstance = $this->callCustomCreator($driver);

            return $driverInstance;
        }

        $config = $this->resolveDriverConfig((string) $driver);

        if ($config === []) {
            throw new \InvalidArgumentException(sprintf('Driver [%s] is not configured.', $driver));
        }

        $canonical = strtolower(trim((string) ($config['driver'] ?? $driver)));
        $classMap = (array) $this->config->get('mailbox.driver_classes', []);
        $class = $classMap[$canonical] ?? null;

        if (! is_string($class) || $class === '' || ! class_exists($class)) {
            throw new \InvalidArgumentException(sprintf('Driver class mapping for [%s] is missing or invalid.', $canonical));
        }

        if (! is_a($class, MailboxDriver::class, true)) {
            throw new \InvalidArgumentException(sprintf('Driver class [%s] must implement %s.', $class, MailboxDriver::class));
        }

        /** @var MailboxDriver $resolved */
        $resolved = new $class($config);

        return $resolved;
    }

    /** @return array<string, mixed> */
    private function resolveDriverConfig(string $driver): array
    {
        $requested = strtolower(trim($driver));

        if ($requested === 'google-workspace') {
            $canonical = (array) $this->config->get('mailbox.drivers.gmail', []);
            $alias = (array) $this->config->get('mailbox.drivers.google-workspace', []);

            $merged = array_merge($canonical, $alias);

            if ($merged !== []) {
                $merged['driver'] = 'gmail';
            }

            return $merged;
        }

        return (array) $this->config->get('mailbox.drivers.'.$requested, []);
    }
}

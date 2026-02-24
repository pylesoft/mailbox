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
use Pyle\Mailbox\Drivers\MsGraph\MsGraphDriver;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Exceptions\DriverNotConfiguredException;
use Pyle\Mailbox\Models\MonitoredFolder;
use Pyle\Mailbox\Models\MonitoredMailbox;
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
        $driver = $mailbox->connection->driver;

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

    protected function createMsGraphDriver(): MsGraphDriver
    {
        $config = (array) $this->config->get('mailbox.drivers.ms-graph', []);

        if ($config === []) {
            throw DriverNotConfiguredException::forDriver('ms-graph', array_keys((array) config('mailbox.drivers', [])));
        }

        return new MsGraphDriver($config);
    }
}

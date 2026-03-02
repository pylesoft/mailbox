<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Commands;

use Illuminate\Console\Command;
use Pyle\Mailbox\Models\Mailbox;
use Pyle\Mailbox\Models\MailboxConnection;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;

class StatusCommand extends Command
{
    protected $signature = 'mailbox:status';

    protected $description = 'Show mailbox connection and sync status';

    public function handle(): int
    {
        $connections = MailboxConnection::query()->with(['mailboxes.activeFolders'])->get();

        if ($connections->isEmpty()) {
            info('No mailbox connections configured.');

            return self::SUCCESS;
        }

        foreach ($connections as $connection) {
            info(sprintf('Connection: %s (%s) - %s', $connection->name, $connection->driver, $connection->status->value));

            table(
                ['Mailbox', 'Active Folders', 'Last Sync', 'Status'],
                $connection->mailboxes->map(fn (Mailbox $mailbox): array => [
                    $mailbox->email_address,
                    (string) $mailbox->activeFolders->count(),
                    $mailbox->last_synced_at?->diffForHumans() ?? 'Never',
                    $mailbox->is_active ? 'Active' : 'Disabled',
                ])->all(),
            );
        }

        return self::SUCCESS;
    }
}

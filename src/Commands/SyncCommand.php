<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use Pyle\Mailbox\Enums\SyncStatus;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Models\MonitoredFolder;
use Pyle\Mailbox\Models\MonitoredMailbox;

class SyncCommand extends Command
{
    protected $signature = 'mailbox:sync {--mailbox=} {--folder=}';

    protected $description = 'Run delta sync for monitored folders';

    public function handle(): int
    {
        $query = MonitoredFolder::query()->active()->with('mailbox.connection');

        if (is_string($this->option('mailbox')) && $this->option('mailbox') !== '') {
            $mailboxEmail = (string) $this->option('mailbox');
            $query->whereHas('mailbox', fn ($q) => $q->where('email_address', $mailboxEmail));
        }

        if (is_string($this->option('folder')) && $this->option('folder') !== '') {
            $query->whereKey((int) $this->option('folder'));
        }

        $folders = $query->get();

        if ($folders->isEmpty()) {
            info('No matching monitored folders found.');

            return self::SUCCESS;
        }

        foreach ($folders as $folder) {
            $folder->update(['sync_status' => SyncStatus::SYNCING]);

            try {
                $mailbox = $folder->mailbox;
                if (! $mailbox instanceof MonitoredMailbox) {
                    throw new \RuntimeException('Monitored folder does not have an associated mailbox.');
                }
                $mailboxEmail = $mailbox->email_address;
                $folderName = $folder->display_name;
                $result = spin(
                    fn () => Mailbox::forFolder($folder)->delta($folder->delta_token),
                    sprintf('Syncing %s (%s)...', $folderName, $mailboxEmail),
                );

                $folder->update([
                    'delta_token' => $result->deltaLink,
                    'last_synced_at' => now(),
                    'sync_status' => SyncStatus::IDLE,
                    'last_sync_error' => null,
                ]);

                info(sprintf('%s: %d new, %d updated, %d deleted', $folderName, $result->created->count(), $result->updated->count(), $result->deleted->count()));
            } catch (\Throwable $e) {
                $folder->update([
                    'sync_status' => SyncStatus::ERROR,
                    'last_sync_error' => Str::limit($e->getMessage(), 500),
                ]);

                error(sprintf('Failed syncing %s: %s', $folder->display_name, $e->getMessage()));

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}

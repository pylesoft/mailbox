<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Commands;

use Illuminate\Console\Command;
use Pyle\Mailbox\DTOs\FolderDto;
use Pyle\Mailbox\Facades\Mailbox;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;

class ListFoldersCommand extends Command
{
    protected $signature = 'mailbox:folders {email} {--driver=} {--tree} {--max-depth=5}';

    protected $description = 'List mailbox folders';

    public function handle(): int
    {
        $driverOption = $this->option('driver');
        $driver = is_string($driverOption) && $driverOption !== '' ? $driverOption : (string) config('mailbox.default', 'ms-graph');
        $emailArg = $this->argument('email');
        $email = is_string($emailArg) ? $emailArg : '';

        if ($email === '') {
            $this->error('Email argument is required.');

            return self::FAILURE;
        }

        $maxDepth = max(1, (int) $this->option('max-depth'));

        $query = Mailbox::driver($driver)->mailbox($email)->folders();
        $folders = $this->option('tree') ? $query->tree($maxDepth) : $query->get();

        if ($this->option('tree')) {
            info(sprintf('Folder Tree for %s', $email));
            foreach ($folders as $folder) {
                $this->printTree($folder);
            }

            return self::SUCCESS;
        }

        table(
            ['ID', 'Name', 'Path', 'Unread', 'Total'],
            $folders->map(fn (FolderDto $folder): array => [
                $folder->id,
                $folder->displayName,
                $folder->path ?? '',
                (string) $folder->unreadItemCount,
                (string) $folder->totalItemCount,
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function printTree(FolderDto $folder, int $depth = 0): void
    {
        $prefix = str_repeat('  ', $depth);
        $this->line(sprintf('%s- %s (%d unread / %d total)', $prefix, $folder->displayName, $folder->unreadItemCount, $folder->totalItemCount));

        foreach ($folder->children as $child) {
            $this->printTree($child, $depth + 1);
        }
    }
}

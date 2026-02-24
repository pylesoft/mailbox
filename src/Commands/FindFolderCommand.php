<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Commands;

use Illuminate\Console\Command;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use Pyle\Mailbox\Facades\Mailbox;

class FindFolderCommand extends Command
{
    protected $signature = 'mailbox:find-folder {email} {name} {--driver=} {--root=}';

    protected $description = 'Find a folder by name';

    public function handle(): int
    {
        $driverOption = $this->option('driver');
        $driver = is_string($driverOption) && $driverOption !== '' ? $driverOption : (string) config('mailbox.default', 'ms-graph');
        $emailArg = $this->argument('email');
        $nameArg = $this->argument('name');

        $email = is_string($emailArg) ? $emailArg : '';
        $name = is_string($nameArg) ? $nameArg : '';

        if ($email === '' || $name === '') {
            error('Email and folder name are required.');

            return self::FAILURE;
        }

        $root = $this->option('root');

        $folder = Mailbox::driver($driver)
            ->mailbox($email)
            ->folders()
            ->find($name, is_string($root) && $root !== '' ? $root : null);

        if ($folder === null) {
            error(sprintf('Folder "%s" was not found.', $name));

            return self::FAILURE;
        }

        info(sprintf('Found folder: %s (%s)', $folder->displayName, $folder->id));
        $this->line(sprintf('Path: %s', $folder->path ?? $folder->displayName));

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Commands;

use Illuminate\Console\Command;
use Pyle\Mailbox\Facades\Mailbox;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

class TestAccessCommand extends Command
{
    protected $signature = 'mailbox:test-access {email?} {--driver=}';

    protected $description = 'Test mailbox authentication and mailbox access';

    public function handle(): int
    {
        $driver = $this->option('driver');

        if (! is_string($driver) || $driver === '') {
            $drivers = array_keys((array) config('mailbox.drivers', ['ms-graph' => []]));
            $driver = select('Which driver?', $drivers, (string) config('mailbox.default', 'ms-graph'));
        }
        $driver = (string) $driver;

        $email = $this->argument('email');

        if (! is_string($email) || $email === '') {
            $email = text('Email address to test?', required: true);
        }
        $email = (string) $email;

        $result = spin(fn () => Mailbox::driver($driver)->testConnection($email), 'Testing connection...');

        if ($result->success) {
            info(sprintf('Connected successfully (%dms)', $result->latencyMs ?? 0));
            info(sprintf('Access to %s: Granted', $email));

            return self::SUCCESS;
        }

        error($result->error ?? 'Connection failed.');

        return self::FAILURE;
    }
}

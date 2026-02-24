<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Commands;

use Illuminate\Console\Command;
use Pyle\Mailbox\Facades\Mailbox;

use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;

class HealthCheckCommand extends Command
{
    protected $signature = 'mailbox:health {--driver=}';

    protected $description = 'Run mailbox health check';

    public function handle(): int
    {
        $driver = $this->option('driver');

        if (! is_string($driver) || $driver === '') {
            $drivers = array_keys((array) config('mailbox.drivers', ['ms-graph' => []]));
            $driver = select('Which driver?', $drivers, (string) config('mailbox.default', 'ms-graph'));
        }
        $driver = (string) $driver;

        $health = Mailbox::driver($driver)->healthCheck();

        info('Mailbox Health Check');

        table(
            ['Metric', 'Value'],
            [
                ['Driver', $driver],
                ['Token', $health->tokenValid ? 'Valid' : 'Invalid'],
                ['Token Expires In', $health->tokenExpiresIn !== null ? (string) $health->tokenExpiresIn.'s' : 'N/A'],
                ['API', $health->apiReachable ? 'Reachable' : 'Unreachable'],
                ['Latency', $health->latencyMs !== null ? (string) $health->latencyMs.'ms' : 'N/A'],
                ['Secret Expiration', $health->secretExpiresAt?->toDateTimeString() ?? 'Unknown'],
                ['Warning', $health->secretExpirationWarning ? 'Yes' : 'No'],
                ['Overall Status', $health->healthy ? 'Healthy' : 'Unhealthy'],
            ],
        );

        return $health->healthy ? self::SUCCESS : self::FAILURE;
    }
}

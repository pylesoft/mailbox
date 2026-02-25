<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Tests\Support;

final class ParallelTestingDatabase
{
    public static function resolve(string $basePath, ?string $token = null): string
    {
        $resolvedToken = is_string($token) ? $token : getenv('TEST_TOKEN');

        if (! is_string($resolvedToken) || trim($resolvedToken) === '') {
            return ':memory:';
        }

        $safeToken = preg_replace('/[^A-Za-z0-9_-]/', '', $resolvedToken);

        if (! is_string($safeToken) || $safeToken === '') {
            return ':memory:';
        }

        return $basePath.'/storage/framework/testing/test-'.$safeToken.'.sqlite';
    }

    public static function prepare(string $databasePath): void
    {
        if ($databasePath === ':memory:') {
            return;
        }

        $directory = dirname($databasePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if (! file_exists($databasePath)) {
            touch($databasePath);
        }
    }

    public static function cleanup(string $databasePath): void
    {
        if ($databasePath === ':memory:') {
            return;
        }

        if ((string) getenv('MAILBOX_KEEP_PARALLEL_DB') === '1') {
            return;
        }

        if (is_file($databasePath)) {
            @unlink($databasePath);
        }
    }
}

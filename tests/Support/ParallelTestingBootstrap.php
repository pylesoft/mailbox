<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Tests\Support;

use Illuminate\Foundation\Application;

final class ParallelTestingBootstrap
{
    public static function configure(Application $app): void
    {
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string) getenv('TEST_TOKEN'));
        if ($token === null || $token === '') {
            return;
        }
        $relativePath = 'storage/framework/testing/bootstrap-'.$token;
        $directory = $app->basePath($relativePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        foreach (['APP_SERVICES_CACHE' => 'services.php', 'APP_PACKAGES_CACHE' => 'packages.php'] as $key => $filename) {
            $_ENV[$key] = $_SERVER[$key] = $relativePath.'/'.$filename;
        }
    }
}

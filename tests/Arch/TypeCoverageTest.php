<?php

declare(strict_types=1);

test('phpstan level is set to 8', function (): void {
    $config = file_get_contents(dirname(__DIR__, 2).'/phpstan.neon.dist');

    expect($config)->not->toBeFalse();
    expect((string) $config)->toContain('level: 8');
});

test('all source files use strict types', function (): void {
    $srcRoot = dirname(__DIR__, 2).'/src';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot));

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        expect($contents)->not->toBeFalse();
        expect((string) $contents)->toContain('declare(strict_types=1);');
    }
});

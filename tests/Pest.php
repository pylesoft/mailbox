<?php

declare(strict_types=1);

use Pest\Expectation;
use Pyle\Mailbox\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit', 'Arch');

expect()->extend('toHaveReturnTypes', function (): Expectation {
    /** @var string $namespace */
    $namespace = $this->value;
    $missing = [];
    $srcRoot = dirname(__DIR__).'/src';

    foreach (mailboxClassesInNamespace($namespace) as $class) {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods() as $method) {
            if (shouldSkipMethodForCoverage($method, $class, $srcRoot)) {
                continue;
            }

            if ($method->getReturnType() === null) {
                $missing[] = sprintf('%s::%s', $class, $method->getName());
            }
        }
    }

    return expect($missing)->toBe([]);
});

expect()->extend('toHaveParameterTypes', function (): Expectation {
    /** @var string $namespace */
    $namespace = $this->value;
    $missing = [];
    $srcRoot = dirname(__DIR__).'/src';

    foreach (mailboxClassesInNamespace($namespace) as $class) {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods() as $method) {
            if (shouldSkipMethodForCoverage($method, $class, $srcRoot)) {
                continue;
            }

            foreach ($method->getParameters() as $parameter) {
                if ($parameter->getType() === null) {
                    $missing[] = sprintf('%s::%s($%s)', $class, $method->getName(), $parameter->getName());
                }
            }
        }
    }

    return expect($missing)->toBe([]);
});

/** @return array<int, class-string> */
function mailboxClassesInNamespace(string $namespace): array
{
    $srcRoot = dirname(__DIR__).'/src';
    $classes = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot));

    foreach ($iterator as $file) {
        if (! ($file instanceof SplFileInfo) || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($srcRoot) + 1);
        if (! is_string($relative)) {
            continue;
        }

        $class = $namespace.'\\'.str_replace(['/', '.php'], ['\\', ''], $relative);

        if (class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return array_values(array_unique($classes));
}

function shouldSkipMethodForCoverage(ReflectionMethod $method, string $class, string $srcRoot): bool
{
    if ($method->getDeclaringClass()->getName() !== $class) {
        return true;
    }

    if ($method->isConstructor() || $method->isDestructor()) {
        return true;
    }

    $methodFile = $method->getFileName();
    if (! is_string($methodFile) || ! str_starts_with($methodFile, $srcRoot)) {
        return true;
    }

    try {
        $prototype = $method->getPrototype();
        $prototypeFile = $prototype->getFileName();

        if (is_string($prototypeFile) && ! str_starts_with($prototypeFile, $srcRoot)) {
            return true;
        }
    } catch (ReflectionException) {
        // No prototype means this method originates in the current class contract.
    }

    return false;
}

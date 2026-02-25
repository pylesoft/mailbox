<?php

declare(strict_types=1);

test('return types', function (): void {
    expect('Pyle\\Mailbox')
        ->toHaveReturnTypes();
});

test('parameter types', function (): void {
    expect('Pyle\\Mailbox')
        ->toHaveParameterTypes();
});

<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\MsGraph\ODataFilterCompiler;

it('compiles where clauses to odata filter', function (): void {
    $compiler = new ODataFilterCompiler();
    $compiler->where('isRead', '=', false);
    $compiler->where('receivedDateTime', '>=', '2026-01-01T00:00:00Z');

    expect($compiler->compile())->toBe("isRead eq false and receivedDateTime ge '2026-01-01T00:00:00Z'");
});

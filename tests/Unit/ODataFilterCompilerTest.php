<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\MsGraph\ODataFilterCompiler;

it('compiles where clauses to odata filter', function (): void {
    $compiler = new ODataFilterCompiler;
    $compiler->where('isRead', '=', false);
    $compiler->where('receivedDateTime', '>=', '2026-01-01T00:00:00Z');

    expect($compiler->compile())->toBe("isRead eq false and receivedDateTime ge '2026-01-01T00:00:00Z'");
});

it('compiles whereAny clauses to grouped odata OR filters', function (): void {
    $compiler = new ODataFilterCompiler;
    $compiler->whereAny('from.address', 'eq', ['first@example.com', 'second@example.com']);
    $compiler->where('isRead', '=', false);

    expect($compiler->compile())
        ->toBe("(from/emailAddress/address eq 'first@example.com' or from/emailAddress/address eq 'second@example.com') and isRead eq false");
});

it('deduplicates duplicate single odata clauses', function (): void {
    $compiler = new ODataFilterCompiler;
    $compiler->where('hasAttachments', 'eq', true);
    $compiler->where('subject', 'eq', 'Invoice(s)');
    $compiler->where('hasAttachments', 'eq', true);

    $filter = $compiler->compile();

    expect(substr_count($filter, 'hasAttachments eq true'))->toBe(1);
    expect($filter)->toContain("subject eq 'Invoice(s)'");
});

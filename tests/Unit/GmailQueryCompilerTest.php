<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\Gmail\GmailQueryCompiler;

it('compiles supported gmail search clauses', function (): void {
    $compiler = (new GmailQueryCompiler)
        ->where('subject', 'contains', 'invoice')
        ->where('from.address', '=', 'vendor@example.com')
        ->where('isRead', false)
        ->where('hasAttachments', true)
        ->where('receivedAt', 'after', '2026-01-01');

    $query = $compiler->compile();

    expect($query)->toContain('subject:"invoice"');
    expect($query)->toContain('from:"vendor@example.com"');
    expect($query)->toContain('is:unread');
    expect($query)->toContain('has:attachment');
    expect($query)->toContain('after:2026/01/01');
    expect($compiler->hasUnsupportedClauses())->toBeFalse();
});

it('marks unsupported clauses', function (): void {
    $compiler = (new GmailQueryCompiler)
        ->where('attachmentSize', '>', 5);

    $compiler->compile();

    expect($compiler->hasUnsupportedClauses())->toBeTrue();
});

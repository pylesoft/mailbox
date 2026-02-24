<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\MsGraph\RateLimiter;

it('runs callback through mailbox lock', function (): void {
    $limiter = new RateLimiter(['concurrency_lock_timeout' => 1]);

    $result = $limiter->forMailbox('invoices@example.com', fn () => 'ok');

    expect($result)->toBe('ok');
});

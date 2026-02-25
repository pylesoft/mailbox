<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Pyle\Mailbox\Drivers\MsGraph\RateLimiter;
use Pyle\Mailbox\Exceptions\RateLimitException;

it('runs callback through mailbox lock', function (): void {
    $limiter = new RateLimiter([
        'max_concurrent_per_mailbox' => 4,
        'concurrency_lock_timeout' => 1,
    ]);

    $result = $limiter->forMailbox('ms-graph', 'invoices@example.com', fn (): Response => new Response(200));

    expect($result->getStatusCode())->toBe(200);
});

it('times out when all configured mailbox slots are occupied', function (): void {
    $limiter = new RateLimiter([
        'max_concurrent_per_mailbox' => 4,
        'concurrency_lock_timeout' => 1,
    ]);

    $driver = 'ms-graph';
    $mailbox = 'invoices@example.com';

    $locks = [];
    for ($slot = 1; $slot <= 4; $slot++) {
        $lock = Cache::lock(sprintf('mailbox:lock:%s:%s:slot:%d', $driver, $mailbox, $slot), 5);
        expect($lock->get())->toBeTrue();
        $locks[] = $lock;
    }

    try {
        expect(fn () => $limiter->forMailbox($driver, $mailbox, fn (): Response => new Response(200)))
            ->toThrow(RateLimitException::class);
    } finally {
        foreach ($locks as $lock) {
            $lock->release();
        }
    }
});

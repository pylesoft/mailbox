<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Illuminate\Support\Facades\Cache;

class RateLimiter
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function forMailbox(string $mailbox, callable $callback): mixed
    {
        $lockKey = sprintf('mailbox:lock:%s', sha1(strtolower($mailbox)));
        $timeout = (int) ($this->config['concurrency_lock_timeout'] ?? config('mailbox.concurrency_lock_timeout', 30));

        $lock = Cache::lock($lockKey, $timeout);
        $lock->block($timeout);

        try {
            return $callback();
        } finally {
            optional($lock)->release();
        }
    }
}

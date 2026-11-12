<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Pyle\Mailbox\Exceptions\RateLimitException;

class RateLimiter
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
    ) {}

    /** @param callable():ResponseInterface $callback */
    public function forMailbox(string $driver, string $mailbox, #[\SensitiveParameter] callable $callback): ResponseInterface
    {
        $normalizedDriver = strtolower(trim($driver)) !== '' ? strtolower(trim($driver)) : 'unknown';
        $normalizedMailbox = strtolower(trim($mailbox)) !== '' ? strtolower(trim($mailbox)) : 'global';

        $maxSlots = max(1, (int) ($this->config['max_concurrent_per_mailbox'] ?? config('mailbox.max_concurrent_per_mailbox', 4)));
        $timeout = (int) ($this->config['concurrency_lock_timeout'] ?? config('mailbox.concurrency_lock_timeout', 30));
        $lease = $callback instanceof MailboxSlotAttempt ? max($timeout, $callback->leaseSeconds) : $timeout;
        $wait = $callback instanceof MailboxSlotAttempt && ! $callback->waitForSlot ? 0 : $timeout;
        $pollIntervalMicros = 100_000;

        $startedAt = microtime(true);
        $lock = null;
        $slot = null;

        do {
            for ($index = 1; $index <= $maxSlots; $index++) {
                $candidate = Cache::lock(
                    sprintf('mailbox:lock:%s:%s:slot:%d', $normalizedDriver, $normalizedMailbox, $index),
                    $lease,
                );

                if ($candidate->get()) {
                    $lock = $candidate;
                    $slot = $index;

                    $this->logDebug('Mailbox concurrency slot acquired', [
                        'driver' => $normalizedDriver,
                        'mailbox' => $normalizedMailbox,
                        'slot' => $slot,
                        'wait_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    ]);

                    break 2;
                }
            }

            if ($wait <= 0) {
                break;
            }

            usleep($pollIntervalMicros);
        } while ($this->elapsedSeconds($startedAt) <= $wait);

        if (! $lock instanceof Lock || ! is_int($slot)) {
            $this->logInfo('Mailbox concurrency slots exhausted', [
                'driver' => $normalizedDriver,
                'mailbox' => $normalizedMailbox,
                'max_slots' => $maxSlots,
                'timeout_seconds' => $wait,
            ]);

            throw new RateLimitException(
                retryAfter: max(1, $timeout),
                mailbox: $normalizedMailbox,
                message: sprintf(
                    "Rate limit exceeded for mailbox '%s'. No concurrency slots available after %d seconds.",
                    $normalizedMailbox,
                    $wait,
                ),
                localContention: true,
            );
        }

        try {
            return $callback();
        } finally {
            $lock->release();

            $this->logDebug('Mailbox concurrency slot released', [
                'driver' => $normalizedDriver,
                'mailbox' => $normalizedMailbox,
                'slot' => $slot,
            ]);
        }
    }

    private function elapsedSeconds(float $startedAt): float
    {
        return microtime(true) - $startedAt;
    }

    /** @param array<string, mixed> $context */
    private function logDebug(string $message, array $context = []): void
    {
        Log::channel((string) config('mailbox.log_channel', 'stack'))->debug($message, $context);
    }

    /** @param array<string, mixed> $context */
    private function logInfo(string $message, array $context = []): void
    {
        Log::channel((string) config('mailbox.log_channel', 'stack'))->info($message, $context);
    }
}

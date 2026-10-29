<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FailoverStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Pyle\Mailbox\Contracts\GrantRefreshLock;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Exceptions\GrantRefreshException;
use Throwable;

final readonly class CacheGrantRefreshLock implements GrantRefreshLock
{
    public function __construct(private string $storeName) {}

    public function acquire(GrantContext $context): Lock
    {
        try {
            $store = $this->storeName === '' ? null : Cache::store($this->storeName)->getStore();
            if (! $store instanceof LockProvider || $store instanceof ArrayStore || $store instanceof FileStore
                || $store instanceof NullStore || $store instanceof FailoverStore) {
                throw new GrantRefreshException('Refresh requires a host-configured shared atomic lock store.', true, 1);
            }
            $key = 'mailbox:grant-refresh:'.hash('sha256', serialize([$context->connectionId, $context->grantId]));
            $lock = $store->lock($key, 60);
            if (! $lock->get()) {
                throw new GrantRefreshException('Refresh coordination is contended.', true, 1);
            }

            return $lock;
        } catch (GrantRefreshException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new GrantRefreshException('Refresh coordination is unavailable.', true, 1);
        }
    }
}

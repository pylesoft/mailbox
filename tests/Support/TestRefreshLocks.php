<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Tests\Support;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;
use Pyle\Mailbox\Contracts\GrantRefreshLock;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Exceptions\GrantRefreshException;

/** Deterministic host boundary double; never evidence of a deployed shared store. */
final class TestRefreshLocks implements GrantRefreshLock
{
    public ?Closure $beforeAcquire = null;

    private ArrayStore $store;

    public function __construct()
    {
        $this->store = new ArrayStore;
    }

    public function acquire(GrantContext $context): Lock
    {
        if ($this->beforeAcquire !== null) {
            ($this->beforeAcquire)();
        }
        $lock = $this->store->lock(hash('sha256', serialize([$context->connectionId, $context->grantId])), 60);
        if (! $lock->get()) {
            throw new GrantRefreshException('Refresh coordination is contended.', true, 1);
        }

        return $lock;
    }
}

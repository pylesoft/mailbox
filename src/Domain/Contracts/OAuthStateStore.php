<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\DTOs\OAuthState;

interface OAuthStateStore
{
    /** Store privately until expiresAt; fail on collision or unavailable storage. */
    public function put(string $state, OAuthState $payload): void;

    /** Atomically take once across all callback workers, returning null for missing/expired/consumed state. */
    public function consume(string $state): ?OAuthState;
}

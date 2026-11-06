<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Tests\Support;

use Pyle\Mailbox\Contracts\GrantStore;
use Pyle\Mailbox\Contracts\GrantTokenProvider;
use Pyle\Mailbox\Contracts\OAuthEnrollmentGuard;
use Pyle\Mailbox\Contracts\OAuthStateStore;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\OAuthState;
use Pyle\Mailbox\DTOs\StoredGrant;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;

final class TestEnrollmentHost implements GrantStore, GrantTokenProvider, OAuthEnrollmentGuard, OAuthStateStore
{
    /** @var array<string, array{connection: string, generation: string, host: string}> */
    public array $bindings = [];

    /** @var array<string, StoredGrant> */
    public array $grants = [];

    /** @var array<string, OAuthState> */
    public array $states = [];

    public bool $denied = false;

    public bool $rejectWrite = false;

    public function bind(GrantContext $context, string $hostContext): void
    {
        $this->bindings[$context->grantId] = ['connection' => $context->connectionId, 'generation' => $context->lifecycleGeneration, 'host' => $hostContext];
    }

    public function assertActive(GrantContext $context): void
    {
        $binding = $this->bindings[$context->grantId] ?? null;
        if ($this->denied || $binding === null || $binding['connection'] !== $context->connectionId || $binding['generation'] !== $context->lifecycleGeneration) {
            throw new GrantAccessDeniedException('The host denied the selected grant.');
        }
    }

    public function assertAllowed(GrantContext $context, string $hostContext): void
    {
        $this->assertActive($context);
        if ($this->bindings[$context->grantId]['host'] !== $hostContext) {
            throw new GrantAccessDeniedException('The host context cannot authorize this connection.');
        }
    }

    public function load(GrantContext $context): ?StoredGrant
    {
        $this->assertActive($context);

        return $this->grants[$context->grantId] ?? null;
    }

    public function replace(GrantContext $context, ?StoredGrant $expected, StoredGrant $replacement): bool
    {
        $this->assertActive($context);
        if ($this->rejectWrite || ($this->grants[$context->grantId] ?? null) !== $expected) {
            return false;
        }
        $this->grants[$context->grantId] = $replacement;

        return true;
    }

    public function accessToken(GrantContext $context): string
    {
        $grant = $this->load($context);
        if ($grant === null || $grant->expiresAt->getTimestamp() <= now()->getTimestamp()) {
            throw new GrantAccessDeniedException('A current grant is required.');
        }

        return $grant->accessToken();
    }

    public function put(string $state, OAuthState $payload): void
    {
        $this->states[$state] = $payload;
    }

    public function consume(string $state): ?OAuthState
    {
        $payload = $this->states[$state] ?? null;
        unset($this->states[$state]);

        return $payload;
    }
}

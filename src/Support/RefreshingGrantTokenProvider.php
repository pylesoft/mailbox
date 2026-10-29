<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

use Pyle\Mailbox\Contracts\GrantAccessGuard;
use Pyle\Mailbox\Contracts\GrantRefresher;
use Pyle\Mailbox\Contracts\GrantRefreshLock;
use Pyle\Mailbox\Contracts\GrantStore;
use Pyle\Mailbox\Contracts\GrantTokenProvider;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\StoredGrant;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\GrantRefreshException;
use Throwable;

final readonly class RefreshingGrantTokenProvider implements GrantTokenProvider
{
    public function __construct(
        private GrantAccessGuard $guard,
        private GrantStore $grants,
        private GrantRefreshLock $locks,
        private GrantRefresher $refresher,
    ) {}

    public function assertActive(GrantContext $context): void
    {
        $this->guard->assertActive($context);
    }

    public function accessToken(GrantContext $context): string
    {
        try {
            $grant = $this->load($context);
            if ($this->isCurrent($grant)) {
                return $grant->accessToken();
            }

            $lock = $this->locks->acquire($context);
            try {
                $reloaded = $this->load($context);
                if (! $grant->identity->matches($reloaded->identity)) {
                    throw new GrantAccessDeniedException('The selected grant identity changed before refresh.');
                }
                if ($this->isCurrent($reloaded)) {
                    return $reloaded->accessToken();
                }

                $replacement = $this->refresher->refresh($context, $reloaded);
                $this->assertActive($context);
                if (! $reloaded->identity->matches($replacement->identity) || $replacement->revision === $reloaded->revision) {
                    throw new GrantAccessDeniedException('Refresh must preserve the selected grant identity and advance its revision.');
                }
                if (! $this->grants->replace($context, $reloaded, $replacement)) {
                    throw new GrantRefreshException('The selected grant changed before refresh could be saved.', true, 1);
                }
                $this->assertActive($context);

                return $replacement->accessToken();
            } finally {
                $lock->release();
            }
        } catch (GrantAccessDeniedException|GrantRefreshException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new GrantRefreshException('The selected grant could not be refreshed safely.', true, 1);
        }
    }

    private function load(GrantContext $context): StoredGrant
    {
        $this->assertActive($context);
        $grant = $this->grants->load($context);
        $this->assertActive($context);
        $provider = $context->driver === 'google-workspace' ? 'gmail' : $context->driver;
        if ($grant === null || $grant->identity->provider !== $provider || trim($grant->revision) === '') {
            throw new GrantAccessDeniedException('A verified grant for the selected provider is required.');
        }

        return $grant;
    }

    private function isCurrent(StoredGrant $grant): bool
    {
        return $grant->expiresAt->getTimestamp() > now()->getTimestamp() + 30;
    }
}

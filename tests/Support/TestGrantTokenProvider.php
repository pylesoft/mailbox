<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Tests\Support;

use Pyle\Mailbox\Contracts\GrantTokenProvider;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;

final class TestGrantTokenProvider implements GrantTokenProvider
{
    public int $tokenReads = 0;

    /** @param array<string, array{connection: string, generation: string, mailboxes: list<string>, token: string}> $grants */
    public function __construct(public array $grants) {}

    public function assertActive(GrantContext $context): void
    {
        $grant = $this->grants[$context->grantId] ?? null;

        if ($grant === null || $grant['connection'] !== $context->connectionId || $grant['generation'] !== $context->lifecycleGeneration || ! in_array($context->mailbox, $grant['mailboxes'], true)) {
            throw new GrantAccessDeniedException('The host rejected an inactive or unauthorized grant context.');
        }
    }

    public function accessToken(GrantContext $context): string
    {
        $this->tokenReads++;

        return $this->grants[$context->grantId]['token'];
    }
}

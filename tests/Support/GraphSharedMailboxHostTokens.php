<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Tests\Support;

use Closure;
use Pyle\Mailbox\Contracts\GrantTokenProvider;
use Pyle\Mailbox\Drivers\MsGraph\Contracts\VerifiesGraphSharedMailboxLiveAccess;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\GraphSubscriptionConfiguration;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;

final class GraphSharedMailboxHostTokens implements GrantTokenProvider, VerifiesGraphSharedMailboxLiveAccess
{
    public TestGrantTokenProvider $tokens;

    public ?string $missingSetup = null;

    /** @var list<GrantContext> */
    public array $verifiedContexts = [];

    public ?Closure $afterVerification = null;

    public function __construct(private readonly GrantContext $context, private readonly GraphSubscriptionConfiguration $configuration)
    {
        $this->tokens = new TestGrantTokenProvider([
            $context->grantId => ['connection' => $context->connectionId, 'generation' => $context->lifecycleGeneration, 'mailboxes' => [$context->mailbox], 'token' => 'selected-token'],
        ]);
    }

    public function assertActive(GrantContext $context): void
    {
        $this->tokens->assertActive($context);
    }

    public function accessToken(GrantContext $context): string
    {
        return $this->tokens->accessToken($context);
    }

    public function assertSharedMailboxLive(GrantContext $context, GraphSubscriptionConfiguration $configuration): void
    {
        if (! $context->matches($this->context) || $configuration != $this->configuration) {
            throw new UnsupportedCapabilityException('The host has not verified this tenant, mailbox, scope or push setup.');
        }
        if ($this->missingSetup !== null) {
            throw new UnsupportedCapabilityException('The host requires '.$this->missingSetup.'.');
        }
        $this->verifiedContexts[] = $context;
        $this->afterVerification?->__invoke();
    }
}

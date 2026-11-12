<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use Closure;
use Psr\Http\Message\ResponseInterface;

/** @internal */
final readonly class MailboxSlotAttempt
{
    /** @param Closure():ResponseInterface $request */
    public function __construct(
        public int $leaseSeconds,
        #[\SensitiveParameter] private Closure $request,
        public bool $waitForSlot = true,
    ) {}

    public function __invoke(): ResponseInterface
    {
        return ($this->request)();
    }
}

<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use InvalidArgumentException;
use JsonSerializable;

final readonly class GraphSubscriptionLookup implements JsonSerializable
{
    /** @param list<GraphSubscriptionRegistration> $registrations */
    public function __construct(
        #[\SensitiveParameter] public array $registrations,
        public bool $complete,
        #[\SensitiveParameter] public ?SyncContinuation $continuation = null,
        public ?string $reason = null,
        public bool $conflict = false,
    ) {
        if ($complete === ($continuation !== null)) {
            throw new InvalidArgumentException('A subscription lookup requires either completion or an unfinished continuation.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['registrationCount' => count($this->registrations), 'complete' => $this->complete, 'reason' => $this->reason, 'conflict' => $this->conflict];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}

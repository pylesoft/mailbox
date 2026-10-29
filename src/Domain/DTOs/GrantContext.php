<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use InvalidArgumentException;

final readonly class GrantContext
{
    public function __construct(
        public string $connectionId,
        public string $grantId,
        public string $lifecycleGeneration,
        public string $driver,
        public string $mailbox,
    ) {
        foreach ([$connectionId, $grantId, $lifecycleGeneration, $driver, $mailbox] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('An explicit grant context requires non-empty connection, grant, generation, driver and mailbox identities.');
            }
        }
    }
}

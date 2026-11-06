<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use DateTimeImmutable;

final readonly class GrantEnrollmentResult
{
    public function __construct(
        public GrantContext $context,
        public GrantIdentity $identity,
        public ?string $email,
        public DateTimeImmutable $expiresAt,
        public ?string $returnTo,
        public ?string $userReference,
    ) {}
}

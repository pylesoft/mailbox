<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use DateTimeImmutable;
use SensitiveParameter;

final readonly class ServiceAccountAccessToken
{
    /** @param list<string> $scopes */
    public function __construct(
        #[SensitiveParameter] private string $accessToken,
        public DateTimeImmutable $expiresAt,
        public array $scopes,
    ) {}

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['expiresAt' => $this->expiresAt, 'scopes' => $this->scopes];
    }
}

<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use DateTimeImmutable;
use SensitiveParameter;

final readonly class StoredGrant
{
    /**
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $metadata  Non-secret provider account metadata, never raw tokens or responses.
     * @param  ?string  $nonceHash  SHA-256 of the nonce verified when this renewable authorization was issued.
     */
    public function __construct(
        public GrantIdentity $identity,
        #[SensitiveParameter] private string $accessToken,
        #[SensitiveParameter] private ?string $refreshToken,
        public DateTimeImmutable $expiresAt,
        public array $scopes,
        public string $revision,
        public ?string $email = null,
        public array $metadata = [],
        #[SensitiveParameter] private ?string $nonceHash = null,
    ) {}

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function refreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function nonceHash(): ?string
    {
        return $this->nonceHash;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['identity' => $this->identity, 'expiresAt' => $this->expiresAt, 'revision' => $this->revision];
    }
}

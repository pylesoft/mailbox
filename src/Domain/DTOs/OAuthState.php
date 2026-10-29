<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use DateTimeImmutable;
use SensitiveParameter;

final readonly class OAuthState
{
    public function __construct(
        public GrantContext $context,
        public string $hostContext,
        public string $provider,
        public string $clientId,
        public string $redirectUri,
        public DateTimeImmutable $expiresAt,
        public ?string $expectedRevision,
        #[SensitiveParameter] private string $nonce,
        #[SensitiveParameter] private string $codeVerifier,
        public ?string $returnTo = null,
        public ?string $userReference = null,
        public ?string $authority = null,
    ) {}

    public function nonce(): string
    {
        return $this->nonce;
    }

    public function codeVerifier(): string
    {
        return $this->codeVerifier;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['context' => $this->context, 'provider' => $this->provider, 'expiresAt' => $this->expiresAt];
    }
}

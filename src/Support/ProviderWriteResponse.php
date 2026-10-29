<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

final readonly class ProviderWriteResponse
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public ?int $status,
        public array $headers = [],
        #[\SensitiveParameter] private ?string $body = null,
    ) {}

    public function body(): ?string
    {
        return $this->body;
    }

    /** @return array{status: ?int, headers: array<string, list<string>>} */
    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'headers' => $this->headers];
    }
}

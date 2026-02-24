<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Pyle\Mailbox\DTOs\Concerns\ArraySerializable;

/** @implements Arrayable<string, mixed> */
final readonly class EmailAddressDto implements Arrayable, JsonSerializable
{
    use ArraySerializable;

    public function __construct(
        public string $name,
        public string $address,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromMsGraph(array $data): self
    {
        $emailAddress = $data['emailAddress'] ?? $data;

        return new self(
            name: (string) ($emailAddress['name'] ?? ''),
            address: (string) ($emailAddress['address'] ?? ''),
        );
    }
}

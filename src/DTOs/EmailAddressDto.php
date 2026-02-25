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

    public static function fromGmail(string $value): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return new self(name: '', address: '');
        }

        if (preg_match('/^(?:"?([^"]*)"?\s*)?<([^>]+)>$/', $trimmed, $matches) === 1) {
            return new self(
                name: trim((string) ($matches[1] ?? '')),
                address: trim((string) ($matches[2] ?? '')),
            );
        }

        if (str_contains($trimmed, '@')) {
            return new self(name: '', address: $trimmed);
        }

        return new self(name: $trimmed, address: '');
    }
}

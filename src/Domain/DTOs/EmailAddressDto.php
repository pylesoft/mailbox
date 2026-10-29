<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Pyle\Mailbox\DTOs\Concerns\ArraySerializable;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Support\ReceivedHeaders;

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
        if (! is_array($emailAddress) || ! is_string($emailAddress['address'] ?? null) || (isset($emailAddress['name']) && ! is_string($emailAddress['name']))) {
            throw new MailboxException('Malformed received Graph email address.');
        }
        $parsed = self::fromGmail($emailAddress['address']);
        if ($parsed->address !== trim($emailAddress['address'])) {
            throw new MailboxException('Expected a structured Graph email address.');
        }

        return new self(
            name: (string) ($emailAddress['name'] ?? ''),
            address: $parsed->address,
        );
    }

    public static function fromGmail(string $value): self
    {
        $addresses = ReceivedHeaders::addresses($value);
        if (count($addresses) !== 1) {
            throw new MailboxException('Expected one received email address.');
        }

        return $addresses[0];
    }
}

<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs\Concerns;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
trait ArraySerializable
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $values = [];

        foreach (get_object_vars($this) as $key => $value) {
            $values[$key] = $this->normalizeValue($value);
        }

        return $values;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        if ($value instanceof JsonSerializable) {
            return $value->jsonSerialize();
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum ? $value->value : $value->name;
        }

        if (is_array($value)) {
            return array_map(fn (mixed $entry): mixed => $this->normalizeValue($entry), $value);
        }

        return $value;
    }
}

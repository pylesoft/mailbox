<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

use Illuminate\Support\Collection;
use Pyle\Mailbox\Enums\FilterableField;

class FilterableFields
{
    /** @return Collection<int, FilterableField> */
    public static function all(): Collection
    {
        /** @var Collection<int, FilterableField> $fields */
        $fields = collect(FilterableField::cases());

        return $fields;
    }
}

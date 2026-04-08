<?php

declare(strict_types=1);

use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Support\FilterableFields;

it('returns the full filterable field list through the helper', function (): void {
    $fields = FilterableFields::all();

    expect($fields)->toHaveCount(count(FilterableField::cases()));
    expect($fields->first())->toBe(FilterableField::cases()[0]);
    expect($fields->contains(FilterableField::ATTACHMENT_NAME))->toBeTrue();
});

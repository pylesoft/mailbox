<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use JsonSerializable;
use Pyle\Mailbox\DTOs\Concerns\ArraySerializable;

/** @implements Arrayable<string, mixed> */
final readonly class DeltaResultDto implements Arrayable, JsonSerializable
{
    use ArraySerializable;

    /**
     * @param  Collection<int, MessageDto>  $created
     * @param  Collection<int, MessageDto>  $updated
     * @param  Collection<int, string>  $deleted
     */
    public function __construct(
        public Collection $created,
        public Collection $updated,
        public Collection $deleted,
        public ?string $deltaLink,
        public bool $fullSyncRequired,
    ) {}
}

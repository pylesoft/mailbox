<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Illuminate\Support\Collection;
use Pyle\Mailbox\DTOs\DeltaResultDto;
use Pyle\Mailbox\DTOs\FolderDto;

interface FolderResource
{
    public function get(): FolderDto;

    /** @return Collection<int, FolderDto> */
    public function children(): Collection;

    public function messages(): MessageQueryBuilder;

    public function delta(?string $deltaToken = null): DeltaResultDto;

    public function delete(): void;

    public function moveTo(string $destinationParentId): FolderDto;
}

<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\DTOs\MessageDto;

interface SupportsMessageMetadataRead
{
    public function metadata(): MessageDto;
}

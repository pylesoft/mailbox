<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Pyle\Mailbox\DTOs\MessageQueryResult;

interface SupportsMessageQueryResults
{
    public function getResult(): MessageQueryResult;
}

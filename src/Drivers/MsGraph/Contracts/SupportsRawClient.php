<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph\Contracts;

use Pyle\Mailbox\Drivers\MsGraph\GraphClient;

interface SupportsRawClient
{
    public function raw(): GraphClient;
}

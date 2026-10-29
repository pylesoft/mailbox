<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use GuzzleHttp\Client;
use Pyle\Mailbox\DTOs\GrantContext;

interface SupportsGrantMailbox
{
    public function forGrant(GrantContext $context, GrantTokenProvider $tokens, ?Client $httpClient = null): MailboxResource;
}

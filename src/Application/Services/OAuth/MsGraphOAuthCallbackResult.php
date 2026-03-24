<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\OAuth;

use Pyle\Mailbox\Models\MailboxOAuthToken;

final readonly class MsGraphOAuthCallbackResult
{
    public function __construct(
        public MailboxOAuthToken $token,
        public ?string $returnTo,
        public ?string $userReference,
    ) {}
}

<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

use Pyle\Mailbox\Contracts\GrantTokenProvider;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;

final readonly class GrantAuthorization
{
    public function __construct(
        private GrantContext $context,
        private GrantTokenProvider $tokens,
    ) {}

    public function accessToken(string $mailbox): string
    {
        if (strtolower(trim($mailbox)) !== strtolower(trim($this->context->mailbox))) {
            throw new GrantAccessDeniedException('The requested mailbox does not match the selected grant context.');
        }

        $this->tokens->assertActive($this->context);
        $token = $this->tokens->accessToken($this->context);
        $this->tokens->assertActive($this->context);

        if ($token === '' || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new GrantAccessDeniedException('The selected grant did not supply a valid access token.');
        }

        return $token;
    }
}

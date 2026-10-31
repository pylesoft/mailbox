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

    public function assertActive(GrantContext $context): void
    {
        if (! $this->context->matches($context)) {
            throw new GrantAccessDeniedException('The requested grant context does not match the captured authorization.');
        }

        $this->tokens->assertActive($context);
    }

    public function accessToken(string $mailbox): string
    {
        if (! self::mailboxMatches($mailbox, $this->context->mailbox)) {
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

    public static function mailboxMatches(string $target, string $selected): bool
    {
        if ($target === $selected) {
            return true;
        }
        $isAlias = filter_var($selected, FILTER_VALIDATE_EMAIL) !== false
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $selected) === 1;
        $target = trim($target);
        $selected = trim($selected);

        return $isAlias && strcasecmp($target, $selected) === 0;
    }
}

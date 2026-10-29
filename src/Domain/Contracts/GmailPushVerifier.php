<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Contracts;

use Psr\Http\Message\RequestInterface;

interface GmailPushVerifier
{
    /**
     * Verify the raw request's JWT signature, issuer, expiry, audience and service-account identity.
     * Return only cryptographically verified claims; throw on any verification failure.
     *
     * @return array<string, mixed>
     */
    public function verify(#[\SensitiveParameter] RequestInterface $request): array;
}

<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail\Contracts;

use Psr\Http\Message\RequestInterface;
use Pyle\Mailbox\Contracts\GmailPushVerifier;
use Pyle\Mailbox\DTOs\GmailChangeHint;
use Pyle\Mailbox\DTOs\GmailWatchConfiguration;
use Pyle\Mailbox\DTOs\GmailWatchRegistration;

interface SupportsGmailWatch
{
    /** @param list<string> $labelIds */
    public function watch(GmailWatchConfiguration $configuration, array $labelIds = []): GmailWatchRegistration;

    public function stopWatch(GmailWatchRegistration $registration): GmailWatchRegistration;

    public function watchCatchUpHint(GmailWatchRegistration $registration): GmailChangeHint;

    public function notification(#[\SensitiveParameter] RequestInterface $request, GmailWatchRegistration $registration, #[\SensitiveParameter] ?GmailPushVerifier $verifier = null, int $maxRequestBytes = 65_536): GmailChangeHint;
}

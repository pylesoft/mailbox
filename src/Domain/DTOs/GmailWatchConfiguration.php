<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use InvalidArgumentException;

final readonly class GmailWatchConfiguration
{
    public function __construct(
        public string $topic,
        public string $subscription,
        public string $audience,
        public string $serviceAccountEmail,
        public string $serviceAccountSubject,
    ) {
        if (preg_match('~\Aprojects/[^/\s]+/topics/[^/\s]+\z~', $topic) !== 1
            || preg_match('~\Aprojects/[^/\s]+/subscriptions/[^/\s]+\z~', $subscription) !== 1
            || trim($audience) === '' || preg_match('/[\x00-\x20\x7f]/', $audience)
            || filter_var($serviceAccountEmail, FILTER_VALIDATE_EMAIL) === false
            || ! str_ends_with($serviceAccountEmail, '.gserviceaccount.com')
            || preg_match('/\A[0-9]+\z/', $serviceAccountSubject) !== 1) {
            throw new InvalidArgumentException('Gmail watch setup requires a trusted topic, subscription, audience and service-account identity.');
        }
    }
}

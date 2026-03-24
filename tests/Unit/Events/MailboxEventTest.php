<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Pyle\Mailbox\Events\AccessDenied;
use Pyle\Mailbox\Events\ApiError;
use Pyle\Mailbox\Events\AttachmentDownloaded;
use Pyle\Mailbox\Events\AttachmentSkipped;
use Pyle\Mailbox\Events\ConnectionTestCompleted;
use Pyle\Mailbox\Events\DeltaSyncCompleted;
use Pyle\Mailbox\Events\DeltaSyncStarted;
use Pyle\Mailbox\Events\DeltaTokenExpired;
use Pyle\Mailbox\Events\RateLimitHit;
use Pyle\Mailbox\Events\SecretExpirationWarning;
use Pyle\Mailbox\Events\TokenAcquired;
use Pyle\Mailbox\Events\TokenRefreshFailed;

dataset('mailbox event payloads', [
    'access denied' => [
        fn (): AccessDenied => new AccessDenied('gmail', 'inbox@example.com', '/messages'),
        ['driver' => 'gmail', 'mailbox' => 'inbox@example.com', 'endpoint' => '/messages'],
    ],
    'api error' => [
        fn (): ApiError => new ApiError('ms-graph', 'ops@example.com', 429, 'Rate limited', '/delta'),
        ['driver' => 'ms-graph', 'mailbox' => 'ops@example.com', 'status' => 429, 'error' => 'Rate limited', 'endpoint' => '/delta'],
    ],
    'attachment downloaded' => [
        fn (): AttachmentDownloaded => new AttachmentDownloaded('gmail', 'ops@example.com', 'm-1', 'a-1', '/tmp/file.pdf', 'local'),
        ['driver' => 'gmail', 'mailbox' => 'ops@example.com', 'messageId' => 'm-1', 'attachmentId' => 'a-1', 'path' => '/tmp/file.pdf', 'disk' => 'local'],
    ],
    'attachment skipped' => [
        fn (): AttachmentSkipped => new AttachmentSkipped('gmail', 'ops@example.com', 'm-2', 'a-2', '/tmp/skip.pdf'),
        ['driver' => 'gmail', 'mailbox' => 'ops@example.com', 'messageId' => 'm-2', 'attachmentId' => 'a-2', 'path' => '/tmp/skip.pdf'],
    ],
    'connection test completed' => [
        fn (): ConnectionTestCompleted => new ConnectionTestCompleted('ms-graph', 'ops@example.com', true, 28),
        ['driver' => 'ms-graph', 'mailbox' => 'ops@example.com', 'success' => true, 'latencyMs' => 28],
    ],
    'delta sync completed' => [
        fn (): DeltaSyncCompleted => new DeltaSyncCompleted('gmail', 'ops@example.com', 'Inbox', 3, 4, 1),
        ['driver' => 'gmail', 'mailbox' => 'ops@example.com', 'folder' => 'Inbox', 'created' => 3, 'updated' => 4, 'deleted' => 1],
    ],
    'delta sync started' => [
        fn (): DeltaSyncStarted => new DeltaSyncStarted('gmail', 'ops@example.com', 'Inbox'),
        ['driver' => 'gmail', 'mailbox' => 'ops@example.com', 'folder' => 'Inbox'],
    ],
    'delta token expired' => [
        fn (): DeltaTokenExpired => new DeltaTokenExpired('ms-graph', 'ops@example.com', 'Archive'),
        ['driver' => 'ms-graph', 'mailbox' => 'ops@example.com', 'folder' => 'Archive'],
    ],
    'rate limit hit' => [
        fn (): RateLimitHit => new RateLimitHit('ms-graph', 'ops@example.com', 60, '/messages'),
        ['driver' => 'ms-graph', 'mailbox' => 'ops@example.com', 'retryAfter' => 60, 'endpoint' => '/messages'],
    ],
    'secret expiration warning' => [
        fn (): SecretExpirationWarning => new SecretExpirationWarning('gmail', CarbonImmutable::parse('2026-04-01T00:00:00Z'), 8),
        ['driver' => 'gmail', 'expiresAt' => CarbonImmutable::parse('2026-04-01T00:00:00Z'), 'daysRemaining' => 8],
    ],
    'token acquired' => [
        fn (): TokenAcquired => new TokenAcquired('gmail', 3600),
        ['driver' => 'gmail', 'expiresIn' => 3600],
    ],
    'token refresh failed' => [
        fn (): TokenRefreshFailed => new TokenRefreshFailed('ms-graph', 'invalid_grant', 'Re-authenticate the mailbox.'),
        ['driver' => 'ms-graph', 'error' => 'invalid_grant', 'guidance' => 'Re-authenticate the mailbox.'],
    ],
]);

it('stores mailbox event constructor payloads as readonly properties', function (Closure $factory, array $expected): void {
    $event = $factory();

    foreach ($expected as $property => $value) {
        expect($event->{$property})->toEqual($value);
    }
})->with('mailbox event payloads');

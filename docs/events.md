# Events

Mailbox dispatches events at every meaningful point in the mailbox lifecycle -- authentication, sync, attachment handling, and connection health. You can listen for these events to build alerting, metrics dashboards, audit logs, and self-healing workflows without modifying any package code. Every event is a `final readonly` class with public promoted properties, so your listeners always receive a clean, immutable snapshot of what happened.

## Authentication Events

These events fire during the OAuth / service-account token lifecycle. Use them to monitor credential health and respond to failures before they cascade into sync outages.

### `TokenAcquired`

Dispatched after Mailbox successfully obtains an access token from the provider (initial grant or refresh).

```php
use Pyle\Mailbox\Events\TokenAcquired;
```

| Property | Type | Description |
|---|---|---|
| `$driver` | `string` | The driver name (e.g. `ms-graph`, `gmail`). |
| `$expiresIn` | `int` | Seconds until the token expires. |

### `TokenRefreshFailed`

Dispatched when a token refresh attempt fails. This usually indicates expired credentials, revoked consent, or a provider outage.

```php
use Pyle\Mailbox\Events\TokenRefreshFailed;
```

| Property | Type | Description |
|---|---|---|
| `$driver` | `string` | The driver name. |
| `$error` | `string` | The error message from the provider. |
| `$guidance` | `?string` | A human-readable suggestion for resolving the issue, or `null`. |

### `SecretExpirationWarning`

Dispatched when a client secret or certificate is approaching its expiration date. The threshold is controlled by the `secret_expiry_warning_days` config value (default: 30 days).

```php
use Pyle\Mailbox\Events\SecretExpirationWarning;
use Carbon\CarbonImmutable;
```

| Property | Type | Description |
|---|---|---|
| `$driver` | `string` | The driver name. |
| `$expiresAt` | `CarbonImmutable` | The exact expiration timestamp. |
| `$daysRemaining` | `int` | Number of days until expiration. |

## Sync Events

Sync events bracket the delta sync lifecycle, giving you precise before/after hooks for every folder sync. See [delta sync](delta-sync.md) for the full sync pipeline.

### `DeltaSyncStarted`

Dispatched just before Mailbox begins fetching changes from the provider.

```php
use Pyle\Mailbox\Events\DeltaSyncStarted;
```

| Property | Type | Description |
|---|---|---|
| `$driver` | `string` | The driver name. |
| `$mailbox` | `string` | The mailbox email address. |
| `$folder` | `string` | The folder ID or well-known name being synced. |

### `DeltaSyncCompleted`

Dispatched after a delta sync finishes successfully. The counts reflect the number of messages in each change category.

```php
use Pyle\Mailbox\Events\DeltaSyncCompleted;
```

| Property | Type | Description |
|---|---|---|
| `$driver` | `string` | The driver name. |
| `$mailbox` | `string` | The mailbox email address. |
| `$folder` | `string` | The folder that was synced. |
| `$created` | `int` | Number of new messages. |
| `$updated` | `int` | Number of modified messages. |
| `$deleted` | `int` | Number of deleted messages. |

### `DeltaTokenExpired`

Dispatched when the provider reports that a delta token (Microsoft Graph `deltaLink` or Gmail `historyId`) is no longer valid. Your application should clear its stored token and perform a full re-sync.

```php
use Pyle\Mailbox\Events\DeltaTokenExpired;
```

| Property | Type | Description |
|---|---|---|
| `$driver` | `string` | The driver name. |
| `$mailbox` | `string` | The mailbox email address. |
| `$folder` | `string` | The folder whose delta token expired. |

## Attachment Events

These events fire during attachment download operations. Use them to track storage usage, trigger post-processing pipelines, or monitor for skipped files. See [attachments](attachments.md) for download details.

### `AttachmentDownloaded`

Dispatched after an attachment is successfully saved to disk.

```php
use Pyle\Mailbox\Events\AttachmentDownloaded;
```

| Property | Type | Description |
|---|---|---|
| `$driver` | `string` | The driver name. |
| `$mailbox` | `string` | The mailbox email address. |
| `$messageId` | `string` | The parent message ID. |
| `$attachmentId` | `string` | The attachment ID. |
| `$path` | `string` | The storage path where the file was saved. |
| `$disk` | `string` | The filesystem disk used (e.g. `local`, `s3`). |

### `AttachmentSkipped`

Dispatched when an attachment download is skipped because the file already exists at the target path.

```php
use Pyle\Mailbox\Events\AttachmentSkipped;
```

| Property | Type | Description |
|---|---|---|
| `$driver` | `string` | The driver name. |
| `$mailbox` | `string` | The mailbox email address. |
| `$messageId` | `string` | The parent message ID. |
| `$attachmentId` | `string` | The attachment ID. |
| `$path` | `string` | The storage path that already contained the file. |

## Connection Health Events

Connection health events help you monitor API reliability and respond to provider-side issues in real time.

### `ConnectionTestCompleted`

Dispatched after a connection test finishes (via `Mailbox::testConnection()` or the `mailbox:test-connection` Artisan command).

```php
use Pyle\Mailbox\Events\ConnectionTestCompleted;
```

| Property | Type | Description |
|---|---|---|
| `$driver` | `string` | The driver name. |
| `$mailbox` | `?string` | The mailbox email address tested, or `null` for driver-level tests. |
| `$success` | `bool` | Whether the connection succeeded. |
| `$latencyMs` | `?int` | Round-trip latency in milliseconds, or `null` on failure. |

### `RateLimitHit`

Dispatched when a provider returns a 429 Too Many Requests response. Mailbox handles the retry automatically, but this event lets you track how often you are hitting limits.

```php
use Pyle\Mailbox\Events\RateLimitHit;
```

| Property | Type | Description |
|---|---|---|
| `$driver` | `string` | The driver name. |
| `$mailbox` | `string` | The mailbox email address. |
| `$retryAfter` | `int` | Seconds the provider asked to wait before retrying. |
| `$endpoint` | `string` | The API endpoint that was throttled. |

### `AccessDenied`

Dispatched when a provider returns a 403 Forbidden response, indicating insufficient permissions for the requested operation.

```php
use Pyle\Mailbox\Events\AccessDenied;
```

| Property | Type | Description |
|---|---|---|
| `$driver` | `string` | The driver name. |
| `$mailbox` | `string` | The mailbox email address. |
| `$endpoint` | `string` | The API endpoint that returned 403. |

### `ApiError`

Dispatched when any non-retryable API error occurs that does not fall into a more specific event category (not 401, 403, or 429).

```php
use Pyle\Mailbox\Events\ApiError;
```

| Property | Type | Description |
|---|---|---|
| `$driver` | `string` | The driver name. |
| `$mailbox` | `string` | The mailbox email address. |
| `$status` | `int` | The HTTP status code. |
| `$error` | `string` | The error message from the provider. |
| `$endpoint` | `string` | The API endpoint that returned the error. |

## Listening for Events

You can register listeners in your application's `EventServiceProvider` just like any other Laravel event. Mailbox events are plain PHP classes -- no special interfaces or traits required.

### Registering Listeners

```php
use Pyle\Mailbox\Events\AccessDenied;
use Pyle\Mailbox\Events\DeltaSyncCompleted;
use Pyle\Mailbox\Events\RateLimitHit;
use Pyle\Mailbox\Events\SecretExpirationWarning;
use Pyle\Mailbox\Events\TokenRefreshFailed;

// In EventServiceProvider::$listen
protected $listen = [
    TokenRefreshFailed::class => [
        \App\Listeners\AlertOnAuthFailure::class,
    ],
    DeltaSyncCompleted::class => [
        \App\Listeners\RecordSyncMetrics::class,
    ],
    RateLimitHit::class => [
        \App\Listeners\MonitorRateLimits::class,
    ],
    SecretExpirationWarning::class => [
        \App\Listeners\WarnOnSecretExpiration::class,
    ],
    AccessDenied::class => [
        \App\Listeners\AlertOnAccessDenied::class,
    ],
];
```

> **Tip** You can also use [Laravel's event discovery](https://laravel.com/docs/events#event-discovery) instead of manual registration. Name your listener methods `handle` and type-hint the event class -- Laravel will wire them up automatically.

## Listener Examples

### Auth Failure Alerting

When a token refresh fails, you want to know immediately. This listener sends a Slack notification and logs the failure so your team can rotate credentials before sync stops.

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Pyle\Mailbox\Events\TokenRefreshFailed;

class AlertOnAuthFailure
{
    public function handle(TokenRefreshFailed $event): void
    {
        Log::channel('mailbox')->critical('Token refresh failed', [
            'driver' => $event->driver,
            'error' => $event->error,
            'guidance' => $event->guidance,
        ]);

        Notification::route('slack', config('services.slack.alerts_webhook'))
            ->notify(new \App\Notifications\MailboxAuthFailed(
                driver: $event->driver,
                error: $event->error,
                guidance: $event->guidance,
            ));
    }
}
```

### Sync Metrics Recording

Tracking sync throughput over time gives you visibility into mailbox activity and helps you spot anomalies. This listener pushes counters to your metrics backend after every delta sync.

```php
<?php

namespace App\Listeners;

use Pyle\Mailbox\Events\DeltaSyncCompleted;

class RecordSyncMetrics
{
    public function handle(DeltaSyncCompleted $event): void
    {
        $tags = [
            'driver' => $event->driver,
            'mailbox' => $event->mailbox,
            'folder' => $event->folder,
        ];

        app('metrics')->counter('mailbox.sync.created', $event->created, $tags);
        app('metrics')->counter('mailbox.sync.updated', $event->updated, $tags);
        app('metrics')->counter('mailbox.sync.deleted', $event->deleted, $tags);
        app('metrics')->counter('mailbox.sync.total', $event->created + $event->updated + $event->deleted, $tags);
    }
}
```

### Rate Limit Monitoring

Rate limits are normal in production, but a sudden spike often means a runaway job or a misconfigured concurrency setting. This listener tracks the frequency and alerts when it crosses a threshold.

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Pyle\Mailbox\Events\RateLimitHit;

class MonitorRateLimits
{
    public function handle(RateLimitHit $event): void
    {
        $cacheKey = "mailbox:rate_limits:{$event->driver}:{$event->mailbox}";

        $hits = Cache::increment($cacheKey);

        // Set a 15-minute sliding window on first hit
        if ($hits === 1) {
            Cache::put($cacheKey, 1, now()->addMinutes(15));
        }

        Log::channel('mailbox')->warning('Rate limit hit', [
            'driver' => $event->driver,
            'mailbox' => $event->mailbox,
            'endpoint' => $event->endpoint,
            'retry_after' => $event->retryAfter,
            'hits_in_window' => $hits,
        ]);

        if ($hits >= 10) {
            Notification::route('slack', config('services.slack.alerts_webhook'))
                ->notify(new \App\Notifications\RateLimitThresholdReached(
                    driver: $event->driver,
                    mailbox: $event->mailbox,
                    hitsInWindow: $hits,
                ));
        }
    }
}
```

### Secret Expiration Warning

Client secrets and certificates expire silently. This listener watches for the warning event and creates an actionable notification well before the deadline.

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Pyle\Mailbox\Events\SecretExpirationWarning;

class WarnOnSecretExpiration
{
    public function handle(SecretExpirationWarning $event): void
    {
        Log::channel('mailbox')->warning('Client secret expiring soon', [
            'driver' => $event->driver,
            'expires_at' => $event->expiresAt->toIso8601String(),
            'days_remaining' => $event->daysRemaining,
        ]);

        if ($event->daysRemaining <= 7) {
            Notification::route('mail', config('mailbox.admin_email'))
                ->notify(new \App\Notifications\SecretExpiringUrgent(
                    driver: $event->driver,
                    expiresAt: $event->expiresAt,
                    daysRemaining: $event->daysRemaining,
                ));
        }
    }
}
```

> **Warning** The `SecretExpirationWarning` event only fires when Mailbox can read the secret's metadata from the provider. If your credentials are already invalid, you will receive a `TokenRefreshFailed` event instead.

## What's Next

- [Error Handling](error-handling.md) -- catch and recover from exceptions Mailbox throws
- [Logging](logging.md) -- configure the dedicated `mailbox` log channel
- [Delta Sync](delta-sync.md) -- build sync pipelines that emit these events

# Error Handling

Every API call to a mail provider can fail -- tokens expire, rate limits hit, networks hiccup, and permissions change without warning. Mailbox provides a clear exception hierarchy so you can catch exactly the failures you care about and let the rest bubble up. Every exception extends a single base class, carries structured properties for programmatic handling, and pairs with an [event](events.md) for observability.

This page walks through the full exception tree, explains when each exception is thrown and what properties it carries, and shows you how to build resilient queue jobs that handle failures gracefully.

## Exception Hierarchy

All exceptions thrown by Mailbox extend `MailboxException`, which itself extends PHP's `RuntimeException`. You can catch the base class to handle any Mailbox failure, or catch a specific subclass when you need targeted recovery logic.

```
RuntimeException
  └── MailboxException
        ├── AuthenticationException
        ├── MailboxAccessDeniedException
        ├── RateLimitException
        ├── ApiRequestException
        ├── ProviderServerException
        ├── ProviderTransportException
        ├── DeltaTokenExpiredException
        ├── ResourceNotFoundException
        └── DriverNotConfiguredException
```

## Base Exception

### `MailboxException`

The base class for every exception Mailbox throws. Catching this class is the broadest possible catch for Mailbox errors. It adds no properties beyond what `RuntimeException` provides.

```php
use Pyle\Mailbox\Exceptions\MailboxException;

try {
    $messages = Mailbox::mailbox('invoices@acme.com')
        ->folder('inbox')
        ->messages()
        ->list();
} catch (MailboxException $e) {
    Log::error('Mailbox operation failed', [
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
    ]);
}
```

## Authentication Exceptions

### `AuthenticationException`

Thrown when Mailbox cannot authenticate with the provider. Common causes include expired client secrets, revoked admin consent, invalid service account credentials, or a disabled Azure AD application.

```php
use Pyle\Mailbox\Exceptions\AuthenticationException;
```

| Property | Type | Description |
|---|---|---|
| `$guidance` | `?string` | A human-readable suggestion for resolution, or `null`. |

The `$guidance` property gives you an actionable hint when the provider returns enough information to diagnose the problem. For example, Microsoft Graph may include "The client secret has expired" while Gmail may report "Service account does not have domain-wide delegation."

```php
try {
    $messages = Mailbox::mailbox('invoices@acme.com')
        ->folder('inbox')
        ->messages()
        ->list();
} catch (AuthenticationException $e) {
    Log::critical('Authentication failed', [
        'error' => $e->getMessage(),
        'guidance' => $e->guidance,
    ]);

    // Notify your team immediately
    Notification::route('slack', config('services.slack.alerts_webhook'))
        ->notify(new MailboxAuthFailed($e->getMessage(), $e->guidance));
}
```

> **Tip** Pair this catch with a listener on the `TokenRefreshFailed` [event](events.md) for belt-and-suspenders alerting. The event fires on refresh failures specifically, while the exception catches all authentication problems.

### `MailboxAccessDeniedException`

Thrown when authentication succeeds but the authenticated identity lacks permission to access the requested mailbox. This typically means the Azure AD app is missing `Mail.ReadWrite` for the target mailbox, or the Gmail service account is not authorized for the target user via domain-wide delegation.

```php
use Pyle\Mailbox\Exceptions\MailboxAccessDeniedException;
```

| Property | Type | Description |
|---|---|---|
| `$mailbox` | `string` | The email address that was denied. |
| `$guidance` | `?string` | A human-readable suggestion for resolution, or `null`. |

```php
try {
    $messages = Mailbox::mailbox('billing@vendor.com')
        ->folder('inbox')
        ->messages()
        ->list();
} catch (MailboxAccessDeniedException $e) {
    Log::warning('Access denied to mailbox', [
        'mailbox' => $e->mailbox,
        'guidance' => $e->guidance,
    ]);

    // Disable sync for this mailbox until permissions are fixed
    ConnectedMailbox::where('email', $e->mailbox)
        ->update(['sync_enabled' => false, 'disabled_reason' => $e->getMessage()]);
}
```

## Rate Limiting

### `RateLimitException`

Thrown when the provider returns a 429 Too Many Requests response and Mailbox has exhausted its retry budget (controlled by the `max_retries` [config](configuration.md) value). The `retryAfter` property tells you how long the provider asked to wait.

```php
use Pyle\Mailbox\Exceptions\RateLimitException;
```

| Property | Type | Description |
|---|---|---|
| `$retryAfter` | `int` | Seconds the provider requested before retrying. |
| `$mailbox` | `string` | The mailbox email address that was throttled. |

```php
try {
    $result = Mailbox::mailbox('invoices@acme.com')
        ->folder('inbox')
        ->delta($storedDeltaLink);
} catch (RateLimitException $e) {
    Log::warning('Rate limited, scheduling retry', [
        'mailbox' => $e->mailbox,
        'retry_after' => $e->retryAfter,
    ]);

    // Re-dispatch the job with the provider's requested delay
    SyncMailboxJob::dispatch($e->mailbox)
        ->delay(now()->addSeconds($e->retryAfter));
}
```

> **Note** Mailbox automatically retries rate-limited requests using exponential backoff before throwing this exception. You only see `RateLimitException` when all retries are exhausted. The `RateLimitHit` [event](events.md) fires on every 429 response, including those that are retried successfully.

## API Exceptions

### `ApiRequestException`

Thrown for API errors that do not fit into the more specific categories above -- for example, a 400 Bad Request caused by an invalid filter or a malformed message ID.

```php
use Pyle\Mailbox\Exceptions\ApiRequestException;
```

| Property | Type | Description |
|---|---|---|
| `$status` | `?int` | The HTTP status code, or `null` if the request did not complete. |
| `$endpoint` | `?string` | The API endpoint that returned the error, or `null`. |

```php
try {
    $message = Mailbox::mailbox('invoices@acme.com')
        ->messages()
        ->find($messageId);
} catch (ApiRequestException $e) {
    Log::error('API request failed', [
        'status' => $e->status,
        'endpoint' => $e->endpoint,
        'error' => $e->getMessage(),
    ]);
}
```

### `ProviderServerException`

Thrown when the provider returns a 5xx server error and Mailbox has exhausted its retry budget. Provider outages are transient by nature, so this exception is always safe to retry after a delay.

```php
use Pyle\Mailbox\Exceptions\ProviderServerException;
```

| Property | Type | Description |
|---|---|---|
| `$statusCode` | `int` | The HTTP 5xx status code (e.g. 500, 502, 503). |
| `$attemptsExhausted` | `int` | The total number of attempts made before giving up. |

```php
try {
    $result = Mailbox::mailbox('invoices@acme.com')
        ->folder('inbox')
        ->delta($storedDeltaLink);
} catch (ProviderServerException $e) {
    Log::error('Provider server error after retries', [
        'status' => $e->statusCode,
        'attempts' => $e->attemptsExhausted,
        'error' => $e->getMessage(),
    ]);

    // Safe to retry later -- the provider is having a bad day
    SyncMailboxJob::dispatch('invoices@acme.com')
        ->delay(now()->addMinutes(5));
}
```

### `ProviderTransportException`

Thrown when the provider request fails before an HTTP response is available, such as a connection reset, DNS failure, or cURL timeout, and Mailbox has released the current queue job or exhausted its retry budget. This behavior is controlled by the `retry_transport_failures` [config](configuration.md) value and is enabled by default.

```php
use Pyle\Mailbox\Exceptions\ProviderTransportException;
```

| Property | Type | Description |
|---|---|---|
| `$endpoint` | `?string` | The API endpoint that was being called. |
| `$mailbox` | `?string` | The mailbox email address or mailbox key. |
| `$attemptsExhausted` | `int` | The attempt number that triggered the exception. |
| `$retryDelay` | `?int` | Seconds before retrying when Mailbox released a queued job, or `null` after retries are exhausted. |

```php
try {
    $messages = Mailbox::mailbox('invoices@acme.com')
        ->folder('inbox')
        ->messages()
        ->list();
} catch (ProviderTransportException $e) {
    Log::warning('Provider transport failure', [
        'mailbox' => $e->mailbox,
        'endpoint' => $e->endpoint,
        'attempts' => $e->attemptsExhausted,
        'retry_delay' => $e->retryDelay,
    ]);
}
```

## Sync Exceptions

### `DeltaTokenExpiredException`

Thrown when the provider reports that a delta token is no longer valid. For Microsoft Graph, this happens when a `deltaLink` returns HTTP 410 Gone. For Gmail, this happens when a `historyId` returns HTTP 404 Not Found. In both cases, the only remedy is a full re-sync -- clear the stored token and call `delta()` without a token to re-baseline. See [delta sync](delta-sync.md) for the complete recovery pattern.

```php
use Pyle\Mailbox\Exceptions\DeltaTokenExpiredException;
```

| Property | Type | Description |
|---|---|---|
| `$mailbox` | `string` | The mailbox email address. |
| `$folderId` | `string` | The folder whose delta token expired. |

```php
try {
    $result = Mailbox::mailbox('invoices@acme.com')
        ->folder('inbox')
        ->delta($storedDeltaLink);
} catch (DeltaTokenExpiredException $e) {
    Log::info('Delta token expired, performing full re-sync', [
        'mailbox' => $e->mailbox,
        'folder' => $e->folderId,
    ]);

    // Clear the stored token and re-sync from scratch
    SyncState::where('mailbox', $e->mailbox)
        ->where('folder_id', $e->folderId)
        ->update(['delta_link' => null]);

    $result = Mailbox::mailbox($e->mailbox)
        ->folder($e->folderId)
        ->delta(); // Full re-sync
}
```

## Resource Exceptions

### `ResourceNotFoundException`

Thrown when a requested resource (message, folder, attachment) does not exist or has been permanently deleted by the provider.

```php
use Pyle\Mailbox\Exceptions\ResourceNotFoundException;
```

| Property | Type | Description |
|---|---|---|
| `$resourceType` | `string` | The type of resource (e.g. `message`, `folder`, `attachment`). |
| `$resourceId` | `string` | The ID of the resource that was not found. |

```php
try {
    $message = Mailbox::mailbox('invoices@acme.com')
        ->messages()
        ->find($messageId);
} catch (ResourceNotFoundException $e) {
    Log::info('Resource no longer exists', [
        'type' => $e->resourceType,
        'id' => $e->resourceId,
    ]);

    // Clean up your local reference
    LocalMessage::where('provider_id', $e->resourceId)->delete();
}
```

## Configuration Exceptions

### `DriverNotConfiguredException`

Thrown when you request a driver that has no configuration in `config/mailbox.php`. This is always a developer error -- it means the driver name was misspelled or the configuration block is missing. This exception uses a static factory method rather than a constructor.

```php
use Pyle\Mailbox\Exceptions\DriverNotConfiguredException;
```

This exception is created via `DriverNotConfiguredException::forDriver(string $driver, array $available)` and produces a message like:

> No configuration found for driver 'outlook'. Add it to config/mailbox.php. Available drivers: ms-graph, gmail.

```php
// This will throw if 'outlook' is not configured:
$driver = Mailbox::driver('outlook');

// To guard against this in environments with conditional drivers:
$driverName = config('mailbox.default', 'ms-graph');

try {
    $driver = Mailbox::driver($driverName);
} catch (DriverNotConfiguredException $e) {
    Log::critical($e->getMessage());
    // Check your config/mailbox.php drivers array
}
```

> **Warning** Unlike other Mailbox exceptions, `DriverNotConfiguredException` indicates a configuration problem, not a runtime failure. If you see this in production, your deployment is missing environment variables or config entries.

## Queue Job Error Handling

Most Mailbox operations run inside queue jobs. Laravel's job retry and failure handling pairs naturally with Mailbox exceptions. The key insight is that some exceptions are retryable (rate limits, server errors) while others are permanent (auth failures, missing resources).

### Categorizing Failures

A useful pattern is to catch specific exceptions and choose whether to retry, delay, or give up:

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Pyle\Mailbox\Exceptions\AuthenticationException;
use Pyle\Mailbox\Exceptions\MailboxAccessDeniedException;
use Pyle\Mailbox\Exceptions\ProviderServerException;
use Pyle\Mailbox\Exceptions\RateLimitException;
use Pyle\Mailbox\Facades\Mailbox;

class SyncMailboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 60;

    public function __construct(
        public readonly string $mailbox,
        public readonly ?string $deltaLink = null,
    ) {}

    public function handle(): void
    {
        $result = Mailbox::mailbox($this->mailbox)
            ->folder('inbox')
            ->delta($this->deltaLink);

        // Process $result->created, $result->updated, $result->deleted ...

        // Store the new delta link for next run
        SyncState::updateOrCreate(
            ['mailbox' => $this->mailbox],
            ['delta_link' => $result->deltaLink],
        );
    }

    public function failed(\Throwable $e): void
    {
        // Permanent failures -- do not retry
        if ($e instanceof AuthenticationException) {
            ConnectedMailbox::where('email', $this->mailbox)
                ->update(['sync_enabled' => false, 'error' => $e->getMessage()]);

            return;
        }

        if ($e instanceof MailboxAccessDeniedException) {
            ConnectedMailbox::where('email', $this->mailbox)
                ->update(['sync_enabled' => false, 'error' => 'Access denied']);

            return;
        }

        Log::error('Mailbox sync job failed permanently', [
            'mailbox' => $this->mailbox,
            'exception' => $e->getMessage(),
        ]);
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(2);
    }
}
```

### Release-Based Retry Strategy

When the `queue_retry_strategy` config is set to `release` (the default), Mailbox avoids blocking your queue workers by releasing the job back to the queue when a retryable response is received. You can implement the same pattern in your own jobs for exceptions that Mailbox throws after retries are exhausted:

```php
public function handle(): void
{
    try {
        $result = Mailbox::mailbox($this->mailbox)
            ->folder('inbox')
            ->delta($this->deltaLink);

        $this->processResults($result);
    } catch (RateLimitException $e) {
        // Release back to queue with the provider's delay
        $this->release($e->retryAfter);
    } catch (ProviderServerException $e) {
        // Provider is down -- wait 5 minutes and try again
        $this->release(300);
    }
}
```

> **Tip** The `release()` approach is better than inline `sleep()` for rate limits because it frees the queue worker to process other jobs while waiting.

### Complete Resilient Sync Job

This example combines everything: delta token recovery, exception categorization, structured logging, and intelligent retry logic.

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Pyle\Mailbox\DTOs\DeltaResultDto;
use Pyle\Mailbox\Exceptions\AuthenticationException;
use Pyle\Mailbox\Exceptions\DeltaTokenExpiredException;
use Pyle\Mailbox\Exceptions\MailboxAccessDeniedException;
use Pyle\Mailbox\Exceptions\ProviderServerException;
use Pyle\Mailbox\Exceptions\RateLimitException;
use Pyle\Mailbox\Facades\Mailbox;

class ResilientSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly string $mailbox,
        public readonly string $folderId = 'inbox',
    ) {}

    public function handle(): void
    {
        $syncState = SyncState::firstOrNew([
            'mailbox' => $this->mailbox,
            'folder_id' => $this->folderId,
        ]);

        try {
            $result = Mailbox::mailbox($this->mailbox)
                ->folder($this->folderId)
                ->delta($syncState->delta_link);

            $this->processResults($result);

            $syncState->delta_link = $result->deltaLink;
            $syncState->last_synced_at = now();
            $syncState->save();

        } catch (DeltaTokenExpiredException $e) {
            Log::info('Delta token expired, re-syncing from scratch', [
                'mailbox' => $this->mailbox,
                'folder' => $this->folderId,
            ]);

            $syncState->delta_link = null;
            $syncState->save();

            // Re-dispatch immediately for a full sync
            self::dispatch($this->mailbox, $this->folderId);

        } catch (RateLimitException $e) {
            Log::warning('Rate limited during sync', [
                'mailbox' => $this->mailbox,
                'retry_after' => $e->retryAfter,
            ]);

            $this->release($e->retryAfter);

        } catch (ProviderServerException $e) {
            Log::warning('Provider error during sync', [
                'mailbox' => $this->mailbox,
                'status' => $e->statusCode,
                'attempts' => $e->attemptsExhausted,
            ]);

            $this->release(300);

        } catch (AuthenticationException|MailboxAccessDeniedException $e) {
            Log::error('Permanent auth failure, disabling sync', [
                'mailbox' => $this->mailbox,
                'error' => $e->getMessage(),
            ]);

            ConnectedMailbox::where('email', $this->mailbox)
                ->update(['sync_enabled' => false, 'error' => $e->getMessage()]);

            $this->fail($e);
        }
    }

    private function processResults(DeltaResultDto $result): void
    {
        $result->created->each(function ($message) {
            LocalMessage::updateOrCreate(
                ['provider_id' => $message->id],
                ['subject' => $message->subject, 'received_at' => $message->receivedAt],
            );
        });

        $result->updated->each(function ($message) {
            LocalMessage::where('provider_id', $message->id)
                ->update(['subject' => $message->subject, 'is_read' => $message->isRead]);
        });

        $result->deleted->each(function ($messageId) {
            LocalMessage::where('provider_id', $messageId)->delete();
        });

        Log::info('Sync completed', [
            'mailbox' => $this->mailbox,
            'folder' => $this->folderId,
            'created' => $result->created->count(),
            'updated' => $result->updated->count(),
            'deleted' => $result->deleted->count(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::critical('Sync job failed permanently', [
            'mailbox' => $this->mailbox,
            'folder' => $this->folderId,
            'exception' => $e->getMessage(),
        ]);
    }
}
```

## Quick Reference

This table summarizes every exception, whether it is retryable, and the matching event.

| Exception | Retryable | Paired Event | Typical Cause |
|---|---|---|---|
| `AuthenticationException` | No | `TokenRefreshFailed` | Expired secret, revoked consent |
| `MailboxAccessDeniedException` | No | `AccessDenied` | Missing mailbox permissions |
| `RateLimitException` | Yes | `RateLimitHit` | Too many API calls |
| `ApiRequestException` | No | `ApiError` | Bad request, invalid parameters |
| `ProviderServerException` | Yes | `ApiError` | Provider outage (5xx) |
| `ProviderTransportException` | Yes | -- | Connection reset, DNS failure, or timeout before an HTTP response |
| `DeltaTokenExpiredException` | Full re-sync | `DeltaTokenExpired` | Stale delta link / history ID |
| `ResourceNotFoundException` | No | -- | Deleted message, folder, or attachment |
| `DriverNotConfiguredException` | No | -- | Missing config entry |

## What's Next

- [Events](events.md) -- listen for the events paired with these exceptions
- [Configuration](configuration.md) -- tune retry budgets, backoff, and concurrency limits
- [Delta Sync](delta-sync.md) -- build the sync pipelines these exceptions protect

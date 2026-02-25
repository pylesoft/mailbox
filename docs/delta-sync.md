# Delta Sync

Most mail integrations need to stay current with a mailbox over time. Polling for every message on every request is wasteful and slow. Delta sync solves this by asking the provider "what changed since I last checked?" and receiving only the new, modified, and deleted messages. Mailbox provides a single, unified API for delta sync that works identically across Microsoft Graph and Gmail, even though the underlying mechanisms are fundamentally different.

This page explains how delta sync works, walks through the sync lifecycle from first run to steady state, and shows you how to build a production-ready sync pipeline with queue jobs and event listeners.

## How Delta Sync Works

Under the hood, each provider tracks changes differently. Mailbox abstracts these differences behind the `FolderResource::delta()` method, so your application code never needs to think about which provider is running.

**Microsoft Graph** has native delta query support. When you call `delta()`, Graph returns a set of changed messages along with a `deltaLink` -- an opaque URL that encodes your position in the change stream. On subsequent calls, Mailbox passes that `deltaLink` directly to Graph, which returns only the changes since that point. If the token expires (HTTP 410), Graph tells Mailbox that a full re-sync is required.

**Gmail** does not have a delta query API. Instead, it uses a `historyId` -- an incrementing integer that marks a point in the mailbox's history timeline. On the first sync, Mailbox fetches all messages in the folder and captures the current `historyId` from the user's profile. On subsequent calls, it queries the Gmail History API with the stored `historyId` to discover which messages were added, deleted, or had labels changed. If the `historyId` is too old (HTTP 404), Mailbox signals that a full re-sync is needed.

In both cases, the result is the same: a `DeltaResultDto` containing three collections and a token to use next time.

## Your First Sync

To run a delta sync, call `delta()` on a folder resource. On the very first call, pass `null` (or omit the argument entirely) to perform a full baseline sync:

```php
use Pyle\Mailbox\Facades\Mailbox;

$result = Mailbox::mailbox('invoices@acme.com')
    ->folder('inbox')
    ->delta(); // DeltaResultDto
```

The returned `DeltaResultDto` contains every message currently in the folder inside the `created` collection, an empty `updated` collection, an empty `deleted` collection, and a `deltaLink` you must store for next time. The `fullSyncRequired` flag will be `false`.

Store the `deltaLink` somewhere durable -- a database column, a cache key, or a model attribute. You will need it for every subsequent sync.

## Subsequent Syncs

On every sync after the first, pass the stored `deltaLink` back to `delta()`:

```php
$result = Mailbox::mailbox('invoices@acme.com')
    ->folder('inbox')
    ->delta($storedDeltaToken); // DeltaResultDto

// Process the changes
foreach ($result->created as $message) {
    // Handle new messages
}

foreach ($result->updated as $message) {
    // Handle modified messages (read status, moved, etc.)
}

foreach ($result->deleted as $messageId) {
    // Handle deleted message IDs
}

// Persist the new token for next time
$storedDeltaToken = $result->deltaLink;
```

This incremental call is fast -- it only returns what changed since the token was issued. On a quiet mailbox, the collections may all be empty with just an updated `deltaLink`.

## Handling Expired Tokens

Delta tokens do not last forever. Microsoft Graph tokens expire after roughly 30 days of inactivity. Gmail history IDs become unavailable once the underlying history records are purged. When this happens, the provider rejects the token and Mailbox sets `fullSyncRequired` to `true` on the returned `DeltaResultDto`.

```php
$result = Mailbox::mailbox('invoices@acme.com')
    ->folder('inbox')
    ->delta($storedDeltaToken);

if ($result->fullSyncRequired) {
    // The token is no longer valid -- run a full baseline sync
    $result = Mailbox::mailbox('invoices@acme.com')
        ->folder('inbox')
        ->delta(); // null token = full sync

    // Replace your stored token with the fresh one
    $storedDeltaToken = $result->deltaLink;
}
```

When `fullSyncRequired` is `true`, the `created`, `updated`, and `deleted` collections will all be empty -- Mailbox does not guess at what changed. You must re-sync from scratch by calling `delta()` with no token. A `DeltaTokenExpired` event is dispatched automatically so you can log or alert on this condition.

> **Tip** To avoid surprise full re-syncs, schedule your sync jobs to run frequently enough that tokens never expire. Every 5-15 minutes is typical for active mailboxes.

## DeltaResultDto Reference

The `DeltaResultDto` is a readonly data transfer object that implements `Arrayable` and `JsonSerializable`. Here is every property:

| Property | Type | Description |
|---|---|---|
| `created` | `Collection<int, MessageDto>` | Messages that appeared in the folder since the last sync. On a first sync, this contains every message in the folder. |
| `updated` | `Collection<int, MessageDto>` | Messages that were modified (read status changed, moved, labels updated, etc.). |
| `deleted` | `Collection<int, string>` | Message IDs that were removed from the folder. These are plain strings, not full `MessageDto` objects. |
| `deltaLink` | `?string` | The opaque token to pass on the next `delta()` call. Store this value. For MS Graph this is a URL; for Gmail it is a `historyId` string. `null` when `fullSyncRequired` is `true`. |
| `fullSyncRequired` | `bool` | `true` when the stored token has expired and you need to perform a fresh baseline sync with a `null` token. |

## The MonitoredFolder Model

Mailbox ships with a `MonitoredFolder` Eloquent model designed to track delta sync state in your database. It stores the `delta_token`, `last_synced_at` timestamp, and `sync_status` for each folder you monitor. The `SyncStatus` enum has three values:

| Value | Description |
|---|---|
| `SyncStatus::IDLE` | The folder is not currently syncing. |
| `SyncStatus::SYNCING` | A sync is in progress. |
| `SyncStatus::ERROR` | The last sync attempt failed. Check `last_sync_error` for details. |

The model provides several useful query scopes:

```php
use Pyle\Mailbox\Models\MonitoredFolder;

// All active folders
MonitoredFolder::active()->get();

// Folders that need syncing (not synced in the last 15 minutes)
MonitoredFolder::needsSync(15)->get();

// Folders with sync errors
MonitoredFolder::withErrors()->get();
```

When you use `MonitoredFolder` with the `Mailbox` facade, you can skip the manual mailbox and folder resolution entirely:

```php
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Models\MonitoredFolder;

$folder = MonitoredFolder::with('mailbox.connection')->first();

$result = Mailbox::forFolder($folder)->delta($folder->delta_token);

$folder->update([
    'delta_token' => $result->deltaLink,
    'last_synced_at' => now(),
    'sync_status' => SyncStatus::IDLE,
]);
```

The `Mailbox::forFolder()` method resolves the correct driver and email address from the folder's relationships automatically.

## Complete Queue Job Example

In production, you will almost always run delta sync inside a queue job. Here is a complete, production-ready example that handles the full lifecycle -- status tracking, token expiry, error recording, and event dispatching:

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Pyle\Mailbox\Enums\SyncStatus;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Models\MonitoredFolder;

class SyncMailboxFolder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public MonitoredFolder $folder,
    ) {}

    public function handle(): void
    {
        $this->folder->update(['sync_status' => SyncStatus::SYNCING]);

        try {
            $result = Mailbox::forFolder($this->folder)
                ->delta($this->folder->delta_token);

            // Handle expired tokens by running a full re-sync
            if ($result->fullSyncRequired) {
                $result = Mailbox::forFolder($this->folder)->delta();
            }

            // Process the changes
            foreach ($result->created as $message) {
                // Store the new message in your local database,
                // dispatch further processing jobs, etc.
            }

            foreach ($result->updated as $message) {
                // Update your local copy of the message
            }

            foreach ($result->deleted as $messageId) {
                // Remove or soft-delete the local record
            }

            // Persist the sync state
            $this->folder->update([
                'delta_token'     => $result->deltaLink,
                'last_synced_at'  => now(),
                'sync_status'     => SyncStatus::IDLE,
                'last_sync_error' => null,
            ]);
        } catch (\Throwable $e) {
            $this->folder->update([
                'sync_status'     => SyncStatus::ERROR,
                'last_sync_error' => Str::limit($e->getMessage(), 500),
            ]);

            throw $e;
        }
    }
}
```

Dispatch the job from a scheduled command or a controller:

```php
// In your scheduler (app/Console/Kernel.php or routes/console.php)
use Pyle\Mailbox\Models\MonitoredFolder;

Schedule::call(function () {
    MonitoredFolder::active()
        ->needsSync(minutes: 10)
        ->with('mailbox.connection')
        ->each(fn (MonitoredFolder $folder) => SyncMailboxFolder::dispatch($folder));
})->everyFiveMinutes();
```

## Artisan Sync Command

Mailbox ships with a built-in `mailbox:sync` command that syncs all active monitored folders (or a filtered subset). This is useful for development, debugging, and one-off manual syncs:

```bash
# Sync all active monitored folders
php artisan mailbox:sync

# Sync only folders belonging to a specific mailbox
php artisan mailbox:sync --mailbox=invoices@acme.com

# Sync a single folder by its database ID
php artisan mailbox:sync --folder=42
```

For the full Artisan command reference, see [Artisan Commands](artisan-commands.md).

## Events Dispatched During Sync

Mailbox dispatches three events during the delta sync lifecycle. You can listen for these in your `EventServiceProvider` or with closures to build logging, alerting, or post-processing pipelines.

### DeltaSyncStarted

Fired at the beginning of every `delta()` call, before any API requests are made.

```php
use Pyle\Mailbox\Events\DeltaSyncStarted;

// Properties:
// $event->driver   — string ("ms-graph" or "gmail")
// $event->mailbox  — string (email address)
// $event->folder   — string (folder ID)
```

### DeltaSyncCompleted

Fired after a successful `delta()` call, once all pages have been fetched and the result is assembled.

```php
use Pyle\Mailbox\Events\DeltaSyncCompleted;

// Properties:
// $event->driver   — string ("ms-graph" or "gmail")
// $event->mailbox  — string (email address)
// $event->folder   — string (folder ID)
// $event->created  — int (count of created messages)
// $event->updated  — int (count of updated messages)
// $event->deleted  — int (count of deleted messages)
```

### DeltaTokenExpired

Fired when the provider rejects the delta token, indicating that a full re-sync is required. This is dispatched automatically before the `DeltaResultDto` is returned with `fullSyncRequired: true`.

```php
use Pyle\Mailbox\Events\DeltaTokenExpired;

// Properties:
// $event->driver   — string ("ms-graph" or "gmail")
// $event->mailbox  — string (email address)
// $event->folder   — string (folder ID)
```

### Example Listener

```php
use Illuminate\Support\Facades\Event;
use Pyle\Mailbox\Events\DeltaSyncCompleted;
use Pyle\Mailbox\Events\DeltaTokenExpired;

Event::listen(DeltaSyncCompleted::class, function (DeltaSyncCompleted $event) {
    logger()->info('Delta sync finished', [
        'driver'  => $event->driver,
        'mailbox' => $event->mailbox,
        'folder'  => $event->folder,
        'created' => $event->created,
        'updated' => $event->updated,
        'deleted' => $event->deleted,
    ]);
});

Event::listen(DeltaTokenExpired::class, function (DeltaTokenExpired $event) {
    // Alert your team that a full re-sync will happen
    Notification::route('slack', '#ops')
        ->notify(new DeltaTokenExpiredAlert($event->mailbox, $event->folder));
});
```

> **Note** For the complete list of all events dispatched by Mailbox (not just sync-related ones), see [Events](events.md).

## What's Next

- [Artisan Commands](artisan-commands.md) -- the full reference for `mailbox:sync` and every other CLI command
- [Events](events.md) -- all events dispatched by Mailbox, with payload details and listener examples
- [Models & Traits](models-and-traits.md) -- the `MonitoredFolder`, `MonitoredMailbox`, and `MailboxConnection` models in depth

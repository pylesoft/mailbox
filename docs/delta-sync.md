# Delta Sync

Most mail integrations need to stay current with a mailbox over time. Delta sync asks the provider what changed since the previous synchronization. The legacy folder API returns eager collections. The optional bounded API below lets a host retain work and resume a Gmail initial scan without treating partial work as complete.

This page explains both interfaces. Each provider retains its own synchronization semantics; the package does not own the host's jobs, retention policy or durable progress storage.

## Bounded Gmail Initial Synchronization

Use `SupportsInitialSync` on a resource obtained through `Mailbox::forGrant()`. Existing custom drivers and resource contracts do not need new methods. Configured Gmail resources retain their eager methods; bounded synchronization requires an explicit grant context.

```php
use Pyle\Mailbox\Contracts\SupportsInitialSync;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Facades\Mailbox;

$mailbox = Mailbox::forGrant($context, $tokens);

if (! $mailbox instanceof SupportsInitialSync) {
    throw new LogicException('This mailbox does not support bounded initial synchronization.');
}

$scope = new SyncScope(
    folderId: 'INBOX',
    importSince: new DateTimeImmutable('2026-01-01T00:00:00Z'),
);

$page = $mailbox->initialSync(
    $scope,
    new SyncBudget(maxPages: 2, maxItems: 100, maxRequests: 3, maxResponseBytes: 1048576),
    $storedContinuation,
);
```

The host supplies `$context`, `$tokens` and a previously retained `$storedContinuation`, or `null` for a new scan. A scope selects one provider folder ID, or `null` for the whole mailbox. Gmail includes spam and trash when reading a whole mailbox. Its cutoff uses the provider's `after:<Unix seconds>` search. The shared scope requires whole-second precision and rejects fractional seconds instead of silently changing the cutoff. The SDK does not delete local history or choose a retention window.

`ChangePage::upserts` and `removals` contain provider message IDs as reconciliation evidence. They do not contain complete message bodies. Use the existing message resources for content acquisition. An initial listing emits upsert evidence; it does not infer removals from messages absent from the selected scope.

Every page has exactly one progress outcome:

| Outcome | Meaning |
| --- | --- |
| `continuation` | Work is unfinished. Resume with this `SyncContinuation` after retaining the page. |
| `checkpoint` | The initial listing round finished. Retain all page evidence before promoting this `SyncCheckpoint`. |
| `resetRequired` | The provider operation requires a reset of the original scope. This shared outcome is available for change capabilities; the initial Gmail listing does not infer a reset from a limit. |

The SDK reads the Gmail profile history ID **before** its first message listing. Every resumed page keeps that original baseline. The completed initial checkpoint contains this pre-scan ID, so later history reconciliation can observe arrivals and changes that occurred during the scan. It does not claim that the mailbox was unchanged while listing. Incremental Gmail history and Graph bounded delta capabilities are separate implementation slices.

### Retaining and resuming pages

Persist the returned evidence and its progress outcome in one host transaction, or use an equivalent durable ordering. Retain every ID as work to reconcile before promoting a continuation. Promote a completed checkpoint only after retaining the final page too. The package neither writes a cursor nor schedules content jobs.

If a process stops before committing a page, retry from the last committed continuation. The same IDs may return again; process them idempotently. Do not promote a checkpoint because an item count was reached, a page was empty, or duplicate evidence was observed. A continuation and a checkpoint are different types and cannot be substituted for one another.

State binds the provider, connection, selected grant, lifecycle generation, target mailbox, folder, cutoff and format version. Reuse against another identity or scope throws `InvalidSyncStateException` before provider I/O. Treat state as trusted, private host data: keep the DTO fields unchanged, and do not deserialize untrusted PHP objects. Debug output redacts provider positions; public properties remain available for private persistence.

### Limits and interrupted work

Budgets apply to each call. `maxPages` limits listing requests, `maxItems` limits returned evidence, and `maxRequests` includes the initial profile request. Gmail requests at most 500 IDs per provider page. If a response returns more IDs than requested, the SDK retains the previous position and reports `provider_item_limit`; it never trims a response and advances past discarded IDs.

`maxResponseBytes` limits the aggregate bytes read from actual response streams, including the profile and every listing in that call. The transport reads at most the remaining budget plus one detection byte, closes each stream, and checks the limit before JSON decoding. A `Content-Length` header cannot bypass the check. This is a response-byte bound, not a promise about exact PHP heap usage during decoding.

An unfinished page reports `item_limit`, `page_limit`, `request_limit`, `response_limit`, `provider_item_limit`, `provider_progress_missing` or `provider_progress_repeated`. Resume only after retaining its evidence. Raise an insufficient budget or resolve a stalled provider response as appropriate. A repeated or unusable token retains the position before that response and never becomes a checkpoint.

Gmail documents an absent `nextPageToken` as its terminal signal. A present null, blank or non-string token is unusable and remains unfinished. Empty pages and duplicate IDs do not determine completion, and `resultSizeEstimate` is not an exact count. The SDK cannot distinguish a provider incorrectly omitting a token from a legitimate terminal page.

Malformed responses, missing baselines, access denial and transport failures throw without returning a checkpoint. Retry from the last durably retained position. Bounded GETs make one HTTP attempt so hidden retries cannot exceed the request budget. Existing eager GET retry behavior is unchanged.

The same transport primitive is available as `getBounded($endpoint, $responseBudget, $query, $mailbox)` on the provider clients. Share a `Support\ResponseBudget` instance across related reads to enforce an aggregate byte bound. It throws `ResponseLimitExceededException` when the actual stream exceeds that bound.

## How Delta Sync Works

Under the hood, each provider tracks changes differently. Mailbox abstracts these differences behind the `FolderResource::delta()` method, so your application code never needs to think about which provider is running.

**Microsoft Graph** has native delta query support. When you call `delta()`, Graph returns a set of changed messages along with a `deltaLink` -- an opaque URL that encodes your position in the change stream. On subsequent calls, Mailbox passes that `deltaLink` directly to Graph, which returns only the changes since that point. If the token expires (HTTP 410), Graph tells Mailbox that a full re-sync is required.

**Gmail** uses a `historyId` that marks a position in mailbox history. On the first eager sync, Mailbox captures this baseline before fetching messages. Capped or cyclic eager listings throw instead of returning a partial collection with a completed checkpoint. On subsequent calls, the Gmail History API reports changes after the stored ID. If the ID is too old (HTTP 404), Mailbox signals that a full re-sync is needed.

In both cases, the result is the same: a `DeltaResultDto` containing three collections and a token to use next time.

### Graph continuations

Graph next and delta links contain opaque queries. Mailbox preserves their encoded bytes, query order and repeated parameters when following pages or resuming a folder. Absolute URLs, API-relative paths, root-relative paths and links relative to the current resource are supported. Do not decode, rebuild or append query parameters to these links.

Each link must stay on the configured Graph API origin and the exact mailbox, folder and operation being read. A link from another folder cannot resume the current folder. Missing or malformed links, conflicting next/delta links, repeated pages and missing collection data throw `ApiRequestException`; they do not produce a completed empty result. Graph message queries also throw when the configured page limit prevents completion.

Treat `deltaLink` as private checkpoint state. Store it unchanged in private application storage and reuse it only for the same mailbox and folder. Client and result debug dumps redact secrets, while the existing property and array/JSON serialization retain the checkpoint for persistence. This validation does not replace host authorization or the grant-bound checkpoint format planned for bounded synchronization.

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

## The Folder Model

Mailbox ships with a `Folder` Eloquent model designed to track delta sync state in your database. It stores the `delta_token`, `last_synced_at` timestamp, and `sync_status` for each folder you monitor. The `SyncStatus` enum has three values:

| Value | Description |
|---|---|
| `SyncStatus::IDLE` | The folder is not currently syncing. |
| `SyncStatus::SYNCING` | A sync is in progress. |
| `SyncStatus::ERROR` | The last sync attempt failed. Check `last_sync_error` for details. |

The model provides several useful query scopes:

```php
use Pyle\Mailbox\Models\Folder;

// All active folders
Folder::active()->get();

// Folders that need syncing (not synced in the last 15 minutes)
Folder::needsSync(15)->get();

// Folders with sync errors
Folder::withErrors()->get();
```

When you use `Folder` with the `Mailbox` facade, you can skip the manual mailbox and folder resolution entirely:

```php
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Models\Folder;

$folder = Folder::with('mailbox.connection')->first();

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
use Pyle\Mailbox\Models\Folder;

class SyncMailboxFolder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public Folder $folder,
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
use Pyle\Mailbox\Models\Folder;

Schedule::call(function () {
    Folder::active()
        ->needsSync(minutes: 10)
        ->with('mailbox.connection')
        ->each(fn (Folder $folder) => SyncMailboxFolder::dispatch($folder));
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
- [Eloquent Models](eloquent-models.md) -- the `Folder`, `Mailbox`, and `MailboxConnection` models in depth

# Quickstart

Imagine your application processes incoming invoices from a shared mailbox. Vendors email their invoices to `invoices@acme.com`, and your app needs to find unread messages, read their contents, download PDF attachments, organize messages into folders, and periodically sync for new arrivals. This page walks through that entire workflow in six steps.

By the end, you will have a working mental model of the Mailbox API and enough code to start building your own integration.

## Connect to a Mailbox

Everything starts with a `MailboxResource`. You obtain one by passing an email address to the facade. Mailbox resolves the default driver from your `config/mailbox.php` and authenticates behind the scenes.

```php
use Pyle\Mailbox\Facades\Mailbox;

$mailbox = Mailbox::mailbox('invoices@acme.com');
```

That single line handles driver resolution, token acquisition, and API client setup. The returned `$mailbox` is your entry point for everything that follows.

If your application manages multiple providers, you can select a driver explicitly:

```php
$mailbox = Mailbox::driver('gmail')->mailbox('billing@vendor.com');
```

> **Tip** When you store connections in the database using the `MailboxConnection` and `MonitoredMailbox` models, you can skip the string-based lookup entirely with `Mailbox::forMailbox($monitoredMailbox)`. The driver is resolved from the model's relationship automatically.

## List Messages

The message query builder gives you an Eloquent-like interface for fetching messages from the provider API. You can scope to a folder, filter by field, search by keyword, and control how many results come back.

```php
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Enums\FilterableField;

$messages = $mailbox->messages()
    ->inFolder(WellKnownFolder::INBOX)
    ->where(FilterableField::IS_READ, false)
    ->where(FilterableField::HAS_ATTACHMENTS, true)
    ->search('invoice')
    ->orderBy('receivedDateTime', 'desc')
    ->take(25)
    ->get(); // Collection<int, MessageDto>
```

Each item in the collection is a `MessageDto` -- a readonly object with properties like `$message->subject`, `$message->from->address`, `$message->receivedAt`, and `$message->hasAttachments`. You never deal with raw API arrays.

> **Note** The `WellKnownFolder` enum translates folder names across providers for you. `WellKnownFolder::INBOX` becomes `Inbox` on Microsoft Graph and `INBOX` on Gmail -- your code stays the same regardless of the driver.

## Read a Single Message

When you find a message worth processing, grab its `MessageResource` to access the full body and metadata:

```php
$messageResource = $mailbox->message($messages->first()->id);

$message = $messageResource->get();  // MessageDto
$body    = $messageResource->body(); // BodyDto

echo $body->contentType; // "html" or "text"
echo $body->content;     // The full message body
```

The `get()` method returns the same `MessageDto` you saw in the listing, but with the complete set of fields populated. The `body()` method returns a `BodyDto` containing the `contentType` (`html` or `text`) and the full `content` string.

Once you have processed the message, mark it as read so it does not appear in future queries:

```php
$messageResource->markAsRead();
```

## Download Attachments

For an invoice-processing pipeline, attachments are the payload. You can download every attachment on a message in a single call:

```php
$files = $messageResource->downloadAttachments(); // Collection<int, AttachmentFileDto>

foreach ($files as $file) {
    echo $file->name;        // "invoice-2024-003.pdf"
    echo $file->contentType;  // "application/pdf"
    echo $file->size;         // 48230
    echo $file->path;         // "mailbox-attachments/abc123/invoice-2024-003.pdf"
    echo $file->disk;         // "local"
}
```

Each `AttachmentFileDto` tells you where the file was saved (`path` and `disk`), so you can pass it straight to Laravel's `Storage` facade for further processing. Inline images (like email signatures) are excluded by default -- pass `true` to include them:

```php
$allFiles = $messageResource->downloadAttachments(includeInline: true);
```

If you need more control, you can work with individual attachments. List them first, then download or stream a specific one:

```php
$attachments = $messageResource->attachments(); // Collection<int, AttachmentDto>

$pdf = $attachments->first(fn ($a) => str_ends_with($a->name, '.pdf'));

$file   = $messageResource->attachment($pdf->id)->download(); // AttachmentFileDto
$stream = $messageResource->attachment($pdf->id)->stream();   // StreamInterface
```

> **Tip** The download disk and path prefix are configurable via the `attachment_disk` and `attachment_path` keys in `config/mailbox.php`.

## Browse Folders

Before you can file processed invoices into the right place, you need to see what folders exist. The folder query builder lists, searches, and creates folders:

```php
// Flat list of all folders
$folders = $mailbox->folders()->get(); // Collection<int, FolderDto>

// Nested tree structure
$tree = $mailbox->folders()->tree(); // Collection<int, FolderDto> (with children populated)

// Find a specific folder by name
$invoicesFolder = $mailbox->folders()->find('Processed Invoices');
```

Each `FolderDto` gives you `displayName`, `totalItemCount`, `unreadItemCount`, and a `wellKnownName` property that maps standard folders like Inbox and Sent to the `WellKnownFolder` enum.

If the folder does not exist yet, create it -- even as a nested path:

```php
$folder = $mailbox->folders()->createPath('Clients/Acme/Processed');
```

Now move the processed invoice into that folder:

```php
$messageResource->moveTo($folder->id);
```

## Run Delta Sync

Polling for all messages on every sync run is wasteful. Delta sync asks the provider "what changed since last time?" and returns only the new, updated, and deleted messages. This is the backbone of any background sync job.

```php
// First run -- no token yet, gets a full snapshot
$delta = $mailbox->folder(WellKnownFolder::INBOX)->delta();

// Process the results
foreach ($delta->created as $message) {
    // Handle new messages
}

foreach ($delta->updated as $message) {
    // Handle changes (read status, folder moves, etc.)
}

foreach ($delta->deleted as $messageId) {
    // Handle deletions
}

// Persist the token for next time
$deltaToken = $delta->deltaLink;
cache()->put('inbox_delta_token', $deltaToken);
```

On subsequent runs, pass the stored token to get only the changes:

```php
$savedToken = cache()->get('inbox_delta_token');

$delta = $mailbox->folder(WellKnownFolder::INBOX)->delta($savedToken);

// Process changes...

// Update the token
cache()->put('inbox_delta_token', $delta->deltaLink);
```

The `DeltaResultDto` contains three collections -- `created`, `updated`, and `deleted` -- plus a `deltaLink` string you persist for the next run. If the provider determines the token is too old, the `fullSyncRequired` flag will be `true`, signaling that you should discard your local state and start fresh.

> **Warning** Always check `$delta->fullSyncRequired` before processing results. When it is `true`, the `created` collection contains a full snapshot rather than an incremental diff, and your existing local data may be stale.

## The Complete Picture

Here is the full invoice-processing workflow assembled into a single snippet:

```php
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Enums\FilterableField;

$mailbox = Mailbox::mailbox('invoices@acme.com');

// Find unread messages with attachments
$messages = $mailbox->messages()
    ->inFolder(WellKnownFolder::INBOX)
    ->where(FilterableField::IS_READ, false)
    ->where(FilterableField::HAS_ATTACHMENTS, true)
    ->take(50)
    ->get();

// Ensure our target folder exists
$processed = $mailbox->folders()->createPath('Processed Invoices');

foreach ($messages as $message) {
    $resource = $mailbox->message($message->id);

    // Download attachments
    $files = $resource->downloadAttachments();

    // Process each PDF (your domain logic here)
    foreach ($files as $file) {
        // Storage::disk($file->disk)->path($file->path) ...
    }

    // Mark as read and file away
    $resource->markAsRead();
    $resource->moveTo($processed->id);
}
```

## What's Next

- [Architecture](architecture.md) -- understand the Manager, Driver, Resource, and DTO layers
- [Messages](messages.md) -- advanced filtering, bulk operations, and field selection
- [Delta Sync](delta-sync.md) -- build robust background sync jobs with incremental tracking

# Architecture

Mailbox follows the same Manager / Driver pattern used by Laravel's Cache, Queue, and Storage components. If you have worked with any of those, the structure will feel instantly familiar. Understanding the layers will help you navigate the source, write custom drivers, and debug issues faster.

This page walks through each layer -- from the top-level Manager down to the readonly DTOs your application code consumes.

## The Request Lifecycle

Every call flows through a predictable chain. The diagram below shows the path a typical `Mailbox::mailbox('invoices@acme.com')->messages()->get()` request takes:

```
Facade                Manager              Driver             Resource
  |                     |                    |                   |
  |  resolve accessor   |                    |                   |
  |-------------------->|                    |                   |
  |                     |  createDriver()    |                   |
  |                     |------------------->|                   |
  |                     |                    |  mailbox($email)  |
  |                     |                    |---------------->  |
  |                     |                    |                   |
  |                     |                    |            MailboxResource
  |                     |                    |                   |
  |                     |                    |           messages() / folder()
  |                     |                    |                   |
  |                     |                    |         QueryBuilder / FolderResource
  |                     |                    |                   |
  |                     |                    |              get() / delta()
  |                     |                    |                   |
  |                     |                    |           Collection<DTO> | DTO
  |                     |                    |                   |
```

In short: **Facade --> Manager --> Driver --> Resource --> Query Builder --> DTO**.

## Manager

The `Pyle\Mailbox\MailboxManager` extends `Illuminate\Support\Manager`. It resolves and caches driver instances, reads the `mailbox.default` config key, and provides convenience methods that proxy straight through to the active driver.

```php
use Pyle\Mailbox\Facades\Mailbox;

// Uses the default driver from config/mailbox.php
$resource = Mailbox::mailbox('invoices@acme.com');

// Explicitly selects a driver at runtime
$resource = Mailbox::driver('gmail')->mailbox('billing@vendor.com');
```

The Manager also accepts Eloquent models directly. When your application tracks connections and mailboxes in the database, you can skip the string-based lookup entirely:

```php
use Pyle\Mailbox\Facades\Mailbox as MailboxFacade;
use Pyle\Mailbox\Models\Mailbox;

$mailbox = Mailbox::where('email_address', 'invoices@acme.com')->first();

$resource = MailboxFacade::forMailbox($mailbox); // MailboxResource
```

The `forMailbox` method reads the `driver` column from the related `MailboxConnection` model and resolves the correct driver automatically. A similar `forFolder` method exists for `Folder` models and returns a `FolderResource` directly.

## Drivers

A driver is any class that implements the `Pyle\Mailbox\Contracts\MailboxDriver` interface. The contract is intentionally small:

```php
namespace Pyle\Mailbox\Contracts;

interface MailboxDriver
{
    public function mailbox(string $emailAddress): MailboxResource;

    public function testConnection(?string $emailAddress = null): ConnectionTestResult;

    public function healthCheck(): HealthCheckResult;
}
```

Mailbox ships with two drivers out of the box:

| Driver | Class | Config key |
|---|---|---|
| Microsoft Graph | `Pyle\Mailbox\Drivers\MsGraph\MsGraphDriver` | `ms-graph` |
| Gmail (Google Workspace) | `Pyle\Mailbox\Drivers\Gmail\GmailDriver` | `gmail` |

The `google-workspace` config key is an alias for `gmail` -- both resolve to the same driver class.

Drivers are registered in the `driver_classes` map inside `config/mailbox.php`. To add your own driver, publish the config and add an entry pointing to a class that implements `MailboxDriver`. See [Extending Mailbox](extending/custom-drivers.md) for a full walkthrough.

## Resources

Once a driver creates a connection, the resource layer provides a fluent interface for navigating the mailbox. Resources are the objects you interact with most in application code.

### MailboxResource

`Pyle\Mailbox\Contracts\MailboxResource` is the entry point for a single email account. It exposes four methods:

```php
interface MailboxResource
{
    public function messages(): MessageQueryBuilder;

    public function message(string $messageId): MessageResource;

    public function folders(): FolderQueryBuilder;

    public function folder(string|WellKnownFolder $folderId): FolderResource;
}
```

You always start here. From a `MailboxResource`, you branch into either the message path or the folder path.

### MessageResource

`Pyle\Mailbox\Contracts\MessageResource` represents a single message. It lets you read the full message, fetch its body, manage read state, move or copy across folders, and work with attachments:

```php
$msg = Mailbox::mailbox('invoices@acme.com')->message($messageId);

$dto  = $msg->get();                    // MessageDto
$body = $msg->body();                   // BodyDto

$msg->markAsRead();
$msg->moveTo(WellKnownFolder::ARCHIVE);

$files = $msg->downloadAttachments();   // Collection<int, AttachmentFileDto>
```

### FolderResource

`Pyle\Mailbox\Contracts\FolderResource` targets a specific folder. Beyond reading folder metadata and listing children, it provides its own `messages()` query builder scoped to that folder and a `delta()` method for incremental sync:

```php
$folder = Mailbox::mailbox('invoices@acme.com')->folder(WellKnownFolder::INBOX);

$info     = $folder->get();              // FolderDto
$children = $folder->children();         // Collection<int, FolderDto>
$messages = $folder->messages()->get();  // Collection<int, MessageDto>
$delta    = $folder->delta($token);      // DeltaResultDto
```

### AttachmentResource

`Pyle\Mailbox\Contracts\AttachmentResource` handles a single attachment on a message. You can fetch metadata, download to disk, or stream the raw bytes:

```php
$attachment = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->attachment($attachmentId);

$meta   = $attachment->metadata();  // AttachmentDto
$file   = $attachment->download();  // AttachmentFileDto
$stream = $attachment->stream();    // Psr\Http\Message\StreamInterface
```

## Query Builders

Query builders give you an Eloquent-like interface for filtering, searching, and paginating results from the provider API. They translate your fluent calls into the appropriate API query parameters for each driver.

### MessageQueryBuilder

`Pyle\Mailbox\Contracts\MessageQueryBuilder` supports folder scoping, field filters, full-text search, ordering, and pagination:

```php
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Enums\FilterableField;

$messages = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->inFolder(WellKnownFolder::INBOX)
    ->where(FilterableField::HAS_ATTACHMENTS, true)
    ->where(FilterableField::IS_READ, false)
    ->search('invoice')
    ->orderBy('receivedDateTime', 'desc')
    ->take(25)
    ->get(); // Collection<int, MessageDto>
```

The query builder also exposes bulk actions -- `markAsRead`, `markAsUnread`, and `moveTo` -- that accept an array of message IDs.

### FolderQueryBuilder

`Pyle\Mailbox\Contracts\FolderQueryBuilder` lists, searches, and creates folders:

```php
$folders = Mailbox::mailbox('invoices@acme.com')->folders()->get();   // Collection<int, FolderDto>
$tree    = Mailbox::mailbox('invoices@acme.com')->folders()->tree();  // Collection<int, FolderDto> (nested)

$folder  = Mailbox::mailbox('invoices@acme.com')->folders()->find('Invoices');
$created = Mailbox::mailbox('invoices@acme.com')->folders()->createPath('Clients/Acme/Invoices');
```

The `tree` method returns a nested structure where each `FolderDto` contains its `$children` array populated recursively up to the specified `$maxDepth`.

## DTOs

Every piece of data Mailbox returns to your application is a `final readonly` DTO. DTOs are plain PHP objects with public properties -- no magic, no mutation. They all implement `Arrayable` and `JsonSerializable`, so you can pass them directly to API responses or Blade views.

| DTO | Purpose | Key properties |
|---|---|---|
| `MessageDto` | A single email message | `id`, `subject`, `from`, `toRecipients`, `receivedAt`, `isRead`, `hasAttachments`, `importance` |
| `BodyDto` | Message body content | `contentType` (`html` or `text`), `content` |
| `FolderDto` | A mailbox folder | `id`, `displayName`, `totalItemCount`, `unreadItemCount`, `wellKnownName`, `children` |
| `AttachmentDto` | Attachment metadata | `id`, `name`, `contentType`, `size`, `isInline` |
| `AttachmentFileDto` | A downloaded attachment | `id`, `name`, `path`, `disk`, `alreadyExisted` |
| `DeltaResultDto` | Incremental sync result | `created`, `updated`, `deleted`, `deltaLink`, `fullSyncRequired` |
| `EmailAddressDto` | An email address | `name`, `address` |
| `ConnectionTestResult` | Connection test outcome | `success`, `error`, `latencyMs`, `authenticatedAs` |
| `HealthCheckResult` | Driver health check | `healthy`, `tokenValid`, `apiReachable`, `latencyMs` |

All DTOs live in the `Pyle\Mailbox\DTOs` namespace.

> **Tip** Because DTOs are readonly, you never have to worry about accidental mutation. They are safe to cache, serialize, or pass between jobs.

## Models

Mailbox provides three Eloquent models for persisting connection and sync state in your database. These are optional -- you can use the package without them -- but they become essential when you manage multiple connections or run background sync jobs.

| Model | Table | Purpose |
|---|---|---|
| `MailboxConnection` | `mailbox_connections` | Stores driver name, status, and encrypted config for a provider connection |
| `Mailbox` | `mailbox_mailboxes` | Links an email address to a connection, tracks sync timestamps |
| `Folder` | `mailbox_folders` | Tracks a folder within a monitored mailbox, stores delta tokens |

The relationships form a straightforward hierarchy:

```
MailboxConnection  --hasMany-->  Mailbox  --hasMany-->  Folder
```

Each model ships with useful query scopes. For example, `Mailbox::active()` filters to enabled mailboxes, and `Folder::needsSync(15)` finds folders that have not been synced in the last 15 minutes.

> **Note** The `MailboxConnection` model encrypts its `config` column using Laravel's `encrypted:array` cast. Sensitive credentials never touch the database in plain text.

## Enums

Mailbox uses backed enums to represent well-known values throughout the API.

| Enum | Purpose | Example values |
|---|---|---|
| `WellKnownFolder` | Provider-agnostic folder names | `INBOX`, `SENT`, `DRAFTS`, `DELETED`, `JUNK`, `ARCHIVE`, `OUTBOX` |
| `FilterableField` | Fields available in `where()` clauses | `SUBJECT`, `FROM_ADDRESS`, `IS_READ`, `HAS_ATTACHMENTS`, `RECEIVED_AT` |
| `Importance` | Message importance level | `LOW`, `NORMAL`, `HIGH` |
| `ConnectionStatus` | Connection health state | `CONNECTED`, `ERROR` |
| `SyncStatus` | Folder sync state | `SYNCING`, `ERROR` |

The `WellKnownFolder` enum is especially important. Instead of hardcoding provider-specific folder names like `SentItems` (Microsoft) or `SENT` (Gmail), you pass the enum and Mailbox translates it for the active driver:

```php
use Pyle\Mailbox\Enums\WellKnownFolder;

// Works identically on both Microsoft Graph and Gmail
$folder = Mailbox::mailbox('invoices@acme.com')->folder(WellKnownFolder::SENT);
```

## Putting It All Together

Here is a complete example that exercises every layer of the architecture -- from the facade, through the manager and driver, into resources and query builders, and back out as DTOs:

```php
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Enums\FilterableField;

// Manager resolves the default driver, driver creates a MailboxResource
$mailbox = Mailbox::mailbox('invoices@acme.com');

// FolderQueryBuilder lists all folders as FolderDto objects
$folders = $mailbox->folders()->get(); // Collection<int, FolderDto>

// MessageQueryBuilder filters and returns MessageDto objects
$unread = $mailbox->messages()
    ->inFolder(WellKnownFolder::INBOX)
    ->where(FilterableField::IS_READ, false)
    ->where(FilterableField::HAS_ATTACHMENTS, true)
    ->take(10)
    ->get(); // Collection<int, MessageDto>

// MessageResource fetches a single message and its attachments
$message = $mailbox->message($unread->first()->id);
$body    = $message->body();            // BodyDto
$files   = $message->downloadAttachments(); // Collection<int, AttachmentFileDto>

// FolderResource runs delta sync and returns a DeltaResultDto
$delta = $mailbox->folder(WellKnownFolder::INBOX)->delta($savedToken);
// $delta->created, $delta->updated, $delta->deleted
```

## What's Next

- [Quickstart](quickstart.md) -- a scenario-driven walkthrough building a real feature
- [Messages](messages.md) -- deep dive into querying, filtering, and managing messages
- [Extending Mailbox](extending/custom-drivers.md) -- build your own driver for any provider

# Folders

Every mailbox is organized into folders -- Inbox, Sent Items, Drafts, and any custom folders your users create. Mailbox gives you a unified API for discovering, creating, and operating on folders regardless of whether the underlying provider is Microsoft Graph or Gmail. You can list flat folder collections, build recursive trees, look up well-known system folders by name, and create deeply nested paths in a single call.

Mailbox exposes two main entry points for folder work: the **folder query builder** for listing, finding, and creating folders, and the **folder resource** for operations on an individual folder.

## Listing Folders

To retrieve all top-level folders in a mailbox, call `folders()` on a mailbox resource and terminate with `get()`:

```php
use Pyle\Mailbox\Facades\Mailbox;

$folders = Mailbox::mailbox('invoices@acme.com')
    ->folders()
    ->get(); // Collection<int, FolderDto>

foreach ($folders as $folder) {
    echo $folder->displayName; // "Inbox", "Sent Items", "Processed", ...
}
```

The returned collection contains `FolderDto` instances with metadata about each folder, including item counts and parent relationships. Each folder's `children` array is empty in a flat listing -- use the tree method described next when you need hierarchy.

## Building a Folder Tree

The `tree()` method returns the same folders but organized into a nested hierarchy. Each parent folder's `children` array is populated with its child folders, recursively:

```php
$tree = Mailbox::mailbox('invoices@acme.com')
    ->folders()
    ->tree(); // Collection<int, FolderDto>
```

By default, `tree()` recurses up to 10 levels deep. You can limit the recursion depth by passing a `maxDepth` argument:

```php
$shallow = Mailbox::mailbox('invoices@acme.com')
    ->folders()
    ->tree(maxDepth: 3);
```

Each node in the tree is a `FolderDto` whose `children` property contains an array of `FolderDto` instances. You can walk the tree recursively to build a sidebar, generate a select dropdown, or render any nested UI:

```php
function renderTree(array $folders, int $indent = 0): void
{
    foreach ($folders as $folder) {
        echo str_repeat('  ', $indent) . $folder->displayName . "\n";
        renderTree($folder->children, $indent + 1);
    }
}

renderTree($tree->all());
```

## Finding a Folder by Name

The `find()` method searches for a folder by its display name and returns the first match, or `null` if no folder is found:

```php
$folder = Mailbox::mailbox('invoices@acme.com')
    ->folders()
    ->find('Processed'); // ?FolderDto
```

By default the search is case-sensitive. Pass `false` as the third argument for a case-insensitive match:

```php
$folder = Mailbox::mailbox('invoices@acme.com')
    ->folders()
    ->find('processed', caseSensitive: false);
```

### Scoping to a Root Folder

You can narrow the search to children of a specific root folder. Pass a raw folder ID or a `WellKnownFolder` enum value as the second argument:

```php
use Pyle\Mailbox\Enums\WellKnownFolder;

// Search only within the Inbox
$subfolder = Mailbox::mailbox('invoices@acme.com')
    ->folders()
    ->find('Finance', WellKnownFolder::INBOX);

// Search within a specific folder by ID
$subfolder = Mailbox::mailbox('invoices@acme.com')
    ->folders()
    ->find('Q4 Reports', 'AAMkAGI2TG93AAA=');
```

## Creating Folders

The `create()` method creates a new folder and returns the resulting `FolderDto`. By default the folder is created at the top level:

```php
$folder = Mailbox::mailbox('invoices@acme.com')
    ->folders()
    ->create('Processed'); // FolderDto
```

To create a folder inside an existing parent, pass the parent folder's ID:

```php
$parent = Mailbox::mailbox('invoices@acme.com')
    ->folders()
    ->find('Inbox');

$child = Mailbox::mailbox('invoices@acme.com')
    ->folders()
    ->create('Finance', parentId: $parent->id);
```

### Creating a Full Path

When you need to create a deeply nested folder structure and some of the intermediate folders may not exist yet, use `createPath()`. It creates every folder in the path that does not already exist and returns the deepest (leaf) folder:

```php
$leaf = Mailbox::mailbox('invoices@acme.com')
    ->folders()
    ->createPath('Inbox/Finance/Processed'); // FolderDto
```

If `Inbox` and `Finance` already exist, only `Processed` is created. If the entire path already exists, the existing leaf folder is returned without modification. This makes `createPath()` safe to call repeatedly -- it is idempotent.

> **Tip**
> Use `createPath()` in setup scripts or queue workers to ensure the folder hierarchy exists before moving messages. You do not need to check for existence first.

## WellKnownFolder Enum

Mailbox provides a `WellKnownFolder` enum that maps logical folder names to their provider-specific identifiers. Use it anywhere a folder ID is accepted -- Mailbox translates the enum to the correct value for the active driver.

```php
use Pyle\Mailbox\Enums\WellKnownFolder;

$inbox = Mailbox::mailbox('invoices@acme.com')
    ->folder(WellKnownFolder::INBOX);
```

### Values

| Enum Case | Value | MS Graph Name | Gmail Label |
|---|---|---|---|
| `WellKnownFolder::INBOX` | `inbox` | `Inbox` | `INBOX` |
| `WellKnownFolder::DRAFTS` | `drafts` | `Drafts` | `DRAFT` |
| `WellKnownFolder::SENT` | `sent` | `SentItems` | `SENT` |
| `WellKnownFolder::DELETED` | `deleted` | `DeletedItems` | `TRASH` |
| `WellKnownFolder::JUNK` | `junk` | `JunkEmail` | `SPAM` |
| `WellKnownFolder::ARCHIVE` | `archive` | `Archive` | `ALL_MAIL` |
| `WellKnownFolder::OUTBOX` | `outbox` | `Outbox` | `OUTBOX` |

When a `FolderDto` is returned by the API, its `wellKnownName` property is automatically resolved so you can check whether a folder is one of the standard system folders:

```php
$folders = Mailbox::mailbox('invoices@acme.com')
    ->folders()
    ->get();

$systemFolders = $folders->filter(fn ($f) => $f->wellKnownName !== null);
$customFolders = $folders->filter(fn ($f) => $f->wellKnownName === null);
```

## Folder Resource Operations

Once you have a folder ID (or a `WellKnownFolder` enum), you can obtain a folder resource to perform operations on that specific folder:

```php
$resource = Mailbox::mailbox('invoices@acme.com')
    ->folder(WellKnownFolder::INBOX);
```

### Retrieving Folder Metadata

The `get()` method returns the full `FolderDto` for the folder:

```php
$folder = Mailbox::mailbox('invoices@acme.com')
    ->folder(WellKnownFolder::INBOX)
    ->get(); // FolderDto

echo $folder->totalItemCount;  // 1432
echo $folder->unreadItemCount; // 28
```

### Listing Child Folders

The `children()` method returns the immediate child folders:

```php
$children = Mailbox::mailbox('invoices@acme.com')
    ->folder(WellKnownFolder::INBOX)
    ->children(); // Collection<int, FolderDto>

foreach ($children as $child) {
    echo $child->displayName; // "Finance", "Legal", "Support"
}
```

### Querying Messages in a Folder

The `messages()` method returns a `MessageQueryBuilder` scoped to the folder. You can chain any of the standard [message query methods](messages.md) onto it:

```php
$unread = Mailbox::mailbox('invoices@acme.com')
    ->folder(WellKnownFolder::INBOX)
    ->messages()
    ->where('isRead', false)
    ->take(20)
    ->get(); // Collection<int, MessageDto>
```

This is equivalent to calling `messages()->inFolder()` on the mailbox resource, but saves you from passing the folder ID explicitly.

### Tracking Changes with Delta Sync

The `delta()` method returns a `DeltaResultDto` containing the messages that have been created, updated, or deleted since your last sync. On the first call, pass `null` (or omit the argument) to perform a full sync and receive a delta token:

```php
$result = Mailbox::mailbox('invoices@acme.com')
    ->folder(WellKnownFolder::INBOX)
    ->delta(); // DeltaResultDto

// Process the initial snapshot
foreach ($result->created as $message) {
    // Store each message...
}

// Persist the token for next time
$deltaToken = $result->deltaLink;
```

On subsequent calls, pass the stored token to receive only incremental changes:

```php
$result = Mailbox::mailbox('invoices@acme.com')
    ->folder(WellKnownFolder::INBOX)
    ->delta($deltaToken);

// Only messages changed since the last sync
$result->created;  // Collection<int, MessageDto> -- new messages
$result->updated;  // Collection<int, MessageDto> -- modified messages
$result->deleted;  // Collection<int, string> -- deleted message IDs
$result->deltaLink; // ?string -- the new token for next time

if ($result->fullSyncRequired) {
    // The token expired or was invalidated -- perform a full re-sync
}
```

> **Note**
> Delta sync is a powerful tool for keeping a local cache in sync with the remote mailbox. For a full walkthrough of sync strategies, see [Delta Sync](usage/delta-sync.md).

### Moving a Folder

The `moveTo()` method moves a folder (and all of its contents) under a different parent folder. It returns the updated `FolderDto`:

```php
$archive = Mailbox::mailbox('invoices@acme.com')
    ->folders()
    ->find('Archive');

$moved = Mailbox::mailbox('invoices@acme.com')
    ->folder($folderId)
    ->moveTo($archive->id); // FolderDto
```

### Deleting a Folder

The `delete()` method permanently removes a folder and all messages it contains:

```php
Mailbox::mailbox('invoices@acme.com')
    ->folder($folderId)
    ->delete();
```

> **Warning**
> Deleting a folder is irreversible on most providers. All messages within the folder are permanently deleted. Consider moving the folder's messages to another location first if you need to preserve them.

## FolderDto Properties

Every folder returned by Mailbox is a `FolderDto` -- a readonly data transfer object that implements `Arrayable` and `JsonSerializable`.

| Property | Type | Description |
|---|---|---|
| `id` | `string` | The provider-specific unique folder identifier |
| `displayName` | `string` | The human-readable folder name |
| `parentFolderId` | `?string` | The ID of the parent folder, or `null` for top-level folders |
| `childFolderCount` | `int` | The number of immediate child folders |
| `totalItemCount` | `int` | Total number of messages in the folder |
| `unreadItemCount` | `int` | Number of unread messages in the folder |
| `path` | `?string` | The full slash-separated path (e.g., `Inbox/Finance/Processed`) |
| `wellKnownName` | `?WellKnownFolder` | The well-known folder type, or `null` for custom folders |
| `children` | `array<FolderDto>` | Child folders (populated only when using `tree()`) |

### Serialization

Because `FolderDto` implements `Arrayable` and `JsonSerializable`, you can convert it for API responses or storage:

```php
$folder = Mailbox::mailbox('invoices@acme.com')
    ->folder(WellKnownFolder::INBOX)
    ->get();

$array = $folder->toArray();
$json = json_encode($folder);
```

## What's Next

- [Messages](messages.md) -- querying, filtering, and acting on individual messages
- [Delta Sync](usage/delta-sync.md) -- incremental synchronization strategies for keeping a local cache up to date
- [Attachments](attachments.md) -- downloading, streaming, and deduplicating file attachments

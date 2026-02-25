# Messages

Messages are the heart of any mail integration. Whether you are building an inbox viewer, processing incoming invoices, or archiving correspondence, nearly everything you do with Mailbox starts by querying, reading, or acting on messages. This page covers every way you can list, filter, retrieve, and manipulate messages through a single, driver-agnostic API.

Mailbox gives you two entry points for working with messages: a **query builder** for listing and bulk operations, and a **message resource** for single-message reads and actions. Both work identically across Microsoft Graph and Gmail.

## Listing Messages

To retrieve messages, call `messages()` on a mailbox resource. This returns a fluent query builder that you chain methods onto, then terminate with `get()`:

```php
use Pyle\Mailbox\Facades\Mailbox;

$messages = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->get(); // Collection<int, MessageDto>
```

By default, Mailbox fetches messages from the inbox sorted by `receivedAt` descending (newest first). Each item in the returned collection is a `MessageDto` instance containing the message metadata.

### Scoping to a Folder

Use `inFolder()` to query a specific folder. You can pass a `WellKnownFolder` enum value or a raw provider folder ID:

```php
use Pyle\Mailbox\Enums\WellKnownFolder;

// Using the enum (recommended — works across all drivers)
$sent = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->inFolder(WellKnownFolder::SENT)
    ->get();

// Using a raw provider folder ID
$custom = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->inFolder('AAMkAGI2TG93AAA=')
    ->get();
```

The `WellKnownFolder` enum provides these cross-driver constants:

| Enum Value | Description |
|---|---|
| `WellKnownFolder::INBOX` | Primary inbox |
| `WellKnownFolder::DRAFTS` | Draft messages |
| `WellKnownFolder::SENT` | Sent items |
| `WellKnownFolder::DELETED` | Trash / deleted items |
| `WellKnownFolder::JUNK` | Spam / junk email |
| `WellKnownFolder::ARCHIVE` | Archive |
| `WellKnownFolder::OUTBOX` | Outbox (queued for sending) |

Mailbox automatically translates these to the correct provider-specific identifier (e.g., `SentItems` for Microsoft Graph, `SENT` for Gmail).

## Filtering Messages

The `where()` method lets you filter messages by any supported field. It accepts a `FilterableField` enum (or its string value), an operator, and a value:

```php
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\MatchOperator;

$unread = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->where(FilterableField::IS_READ, MatchOperator::EQUALS, false)
    ->get();
```

You can chain multiple `where()` calls. All conditions are combined with AND logic:

```php
$results = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->where(FilterableField::IS_READ, MatchOperator::EQUALS, false)
    ->where(FilterableField::HAS_ATTACHMENTS, MatchOperator::EQUALS, true)
    ->where(FilterableField::SUBJECT, MatchOperator::CONTAINS, 'Invoice')
    ->get();
```

### Shorthand Syntax

When using the `EQUALS` operator, you can pass just the field and value. Mailbox infers the operator:

```php
// These two are equivalent
->where(FilterableField::IS_READ, MatchOperator::EQUALS, false)
->where(FilterableField::IS_READ, false)
```

You may also use raw string field names instead of the enum:

```php
->where('isRead', false)
->where('subject', 'contains', 'Invoice')
```

### Filterable Fields Reference

Every field you can filter on, with its allowed operators and expected value type:

| FilterableField | Value | Type | Allowed Operators |
|---|---|---|---|
| `SUBJECT` | `subject` | string | `EQUALS`, `CONTAINS`, `STARTS_WITH`, `ENDS_WITH`, `MATCHES_REGEX` |
| `FROM_ADDRESS` | `from.address` | string | `EQUALS`, `CONTAINS`, `ENDS_WITH` |
| `FROM_NAME` | `from.name` | string | `EQUALS`, `CONTAINS` |
| `SENDER_ADDRESS` | `sender.address` | string | `EQUALS`, `CONTAINS`, `ENDS_WITH` |
| `TO_ADDRESS` | `toRecipients.address` | string | `EQUALS`, `CONTAINS` |
| `CC_ADDRESS` | `ccRecipients.address` | string | `EQUALS`, `CONTAINS` |
| `RECEIVED_AT` | `receivedAt` | datetime | `BEFORE`, `AFTER`, `BETWEEN` |
| `IS_READ` | `isRead` | boolean | `EQUALS` |
| `IS_DRAFT` | `isDraft` | boolean | `EQUALS` |
| `HAS_ATTACHMENTS` | `hasAttachments` | boolean | `EQUALS` |
| `IMPORTANCE` | `importance` | enum | `EQUALS` |
| `BODY_PREVIEW` | `bodyPreview` | string | `CONTAINS`, `MATCHES_REGEX` |
| `ATTACHMENT_COUNT` | `attachmentCount` | integer | `EQUALS`, `GREATER_THAN`, `LESS_THAN`, `BETWEEN` |
| `ATTACHMENT_NAME` | `attachmentName` | string | `EQUALS`, `CONTAINS`, `STARTS_WITH`, `ENDS_WITH`, `MATCHES_REGEX` |
| `ATTACHMENT_CONTENT_TYPE` | `attachmentContentType` | string | `EQUALS`, `CONTAINS` |
| `ATTACHMENT_SIZE` | `attachmentSize` | integer | `EQUALS`, `GREATER_THAN`, `LESS_THAN`, `BETWEEN` |

### Match Operators Reference

| MatchOperator | Value | Description |
|---|---|---|
| `EQUALS` | `equals` | Exact match |
| `NOT_EQUALS` | `not_equals` | Negated exact match |
| `CONTAINS` | `contains` | Substring match (case-insensitive) |
| `NOT_CONTAINS` | `not_contains` | Negated substring match |
| `STARTS_WITH` | `starts_with` | Value begins with the given string |
| `ENDS_WITH` | `ends_with` | Value ends with the given string |
| `MATCHES_REGEX` | `matches_regex` | Regular expression match |
| `GREATER_THAN` | `greater_than` | Numeric greater-than comparison |
| `LESS_THAN` | `less_than` | Numeric less-than comparison |
| `BETWEEN` | `between` | Value falls within a range (pass a two-element array) |
| `BEFORE` | `before` | Datetime falls before the given value |
| `AFTER` | `after` | Datetime falls after the given value |

### Filtering by Date

Use the `RECEIVED_AT` field with `BEFORE`, `AFTER`, or `BETWEEN` operators. Pass a Carbon instance, a `DateTime` object, or a date string:

```php
use Carbon\Carbon;

// Messages received in the last 7 days
$recent = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->where(FilterableField::RECEIVED_AT, MatchOperator::AFTER, Carbon::now()->subDays(7))
    ->get();

// Messages received within a specific range
$range = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->where(FilterableField::RECEIVED_AT, MatchOperator::BETWEEN, [
        Carbon::parse('2025-01-01'),
        Carbon::parse('2025-01-31'),
    ])
    ->get();
```

### Filtering by Importance

The `Importance` enum has three values: `LOW`, `NORMAL`, and `HIGH`.

```php
use Pyle\Mailbox\Enums\Importance;

$urgent = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->where(FilterableField::IMPORTANCE, MatchOperator::EQUALS, Importance::HIGH)
    ->get();
```

### Filtering by Sender Domain

Use `ENDS_WITH` on the `FROM_ADDRESS` field to match an entire domain:

```php
$fromVendor = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->where(FilterableField::FROM_ADDRESS, MatchOperator::ENDS_WITH, '@vendor.com')
    ->get();
```

> **Note**
> Some filters can be pushed to the provider's server (faster), while others are applied client-side after fetching. Mailbox handles this transparently. When you combine `search()` with `where()`, the search runs server-side and filters are applied client-side to the results.

## Searching Messages

The `search()` method sends a full-text query to the provider's search engine. This is different from `where()` -- it leverages the provider's own search infrastructure (Microsoft's KQL, Gmail's search syntax) for fast, relevance-ranked results:

```php
$results = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->search('quarterly report Q4')
    ->get();
```

You can combine `search()` with `inFolder()` and `where()`. The search runs first on the provider, then Mailbox applies any `where()` filters client-side:

```php
$results = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->inFolder(WellKnownFolder::INBOX)
    ->search('invoice')
    ->where(FilterableField::HAS_ATTACHMENTS, true)
    ->get();
```

> **Tip**
> For simple field-level conditions, prefer `where()`. Reserve `search()` for natural-language or keyword queries where the provider's full-text index gives you better results than client-side filtering.

## Ordering and Pagination

### Ordering Results

Use `orderBy()` to control sort order. The default is `receivedAt` descending:

```php
// Oldest first
$oldest = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->orderBy('receivedAt', 'asc')
    ->get();

// Sort by subject
$alphabetical = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->orderBy('subject', 'asc')
    ->get();
```

### Limiting Results

Use `take()` to cap the total number of messages returned. Mailbox stops fetching from the provider once the limit is reached:

```php
$latest = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->take(25)
    ->get();
```

### Controlling Page Size

The `pageSize()` method controls how many messages Mailbox requests per API call. This does not limit the total results -- it controls the internal pagination batch size. The default page size is configured in `mailbox.default_page_size` (50 by default):

```php
$messages = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->take(200)
    ->pageSize(100)
    ->get();
```

> **Tip**
> Increasing `pageSize()` reduces the number of API calls but increases the payload per request. For most use cases, the default of 50 strikes a good balance.

### Getting a Count

Use `count()` to get the total number of messages matching your query:

```php
$unreadCount = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->where(FilterableField::IS_READ, false)
    ->count(); // int
```

### Getting the First Result

Use `first()` to retrieve a single message (or `null` if none match). This is equivalent to `take(1)->get()->first()`:

```php
$latest = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->first(); // ?MessageDto
```

### Selecting Specific Fields

The `select()` method lets you request only certain fields from the provider, reducing payload size. This is particularly useful with Microsoft Graph:

```php
$messages = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->select(['id', 'subject', 'receivedDateTime', 'from'])
    ->take(50)
    ->get();
```

> **Note**
> The `select()` method is a performance optimization. The fields available depend on the provider's API. On Gmail, this method is accepted but has no effect since Gmail always returns the full message payload.

## Getting a Single Message

When you already have a message ID, use the `message()` method on the mailbox resource to get a `MessageResource`. This gives you access to the full message and all its operations:

```php
$resource = Mailbox::mailbox('invoices@acme.com')
    ->message('AAMkAGI2TG93AAA=');

$message = $resource->get(); // MessageDto
```

The `get()` method returns a complete `MessageDto` with all properties populated, including the full body content.

## Reading the Message Body

For list queries, message bodies are not included by default (for performance). To read the full HTML or plain-text body of a specific message, use the `body()` method on a message resource:

```php
$body = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->body(); // BodyDto
```

The `BodyDto` has two properties:

| Property | Type | Description |
|---|---|---|
| `contentType` | `string` | Either `html` or `text` |
| `content` | `string` | The full message body |

```php
$body = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->body();

if ($body->contentType === 'html') {
    // Render the HTML body
    return view('emails.show', ['html' => $body->content]);
}

// Plain text fallback
echo $body->content;
```

> **Note**
> When a message was fetched via the single-message `get()` method, the `body` property on the `MessageDto` may already be populated. The dedicated `body()` method is guaranteed to return the full body content regardless of how the message was originally loaded.

## MessageDto Reference

Every message returned by Mailbox is a `MessageDto` -- a readonly data transfer object that implements `Arrayable` and `JsonSerializable`. Here is every property:

| Property | Type | Description |
|---|---|---|
| `id` | `string` | The provider-specific unique message identifier |
| `subject` | `string` | The message subject line |
| `bodyPreview` | `?string` | A short plain-text snippet of the message body |
| `body` | `?BodyDto` | The full message body (may be `null` on list queries) |
| `from` | `?EmailAddressDto` | The address in the From header |
| `sender` | `?EmailAddressDto` | The address in the Sender header (may differ from `from` for delegated sending) |
| `toRecipients` | `array<EmailAddressDto>` | All To recipients |
| `ccRecipients` | `array<EmailAddressDto>` | All CC recipients |
| `bccRecipients` | `array<EmailAddressDto>` | All BCC recipients |
| `receivedAt` | `?CarbonImmutable` | When the message was received by the server |
| `sentAt` | `?CarbonImmutable` | When the message was sent |
| `isRead` | `bool` | Whether the message has been read |
| `isDraft` | `bool` | Whether the message is a draft |
| `hasAttachments` | `bool` | Whether the message has any attachments |
| `importance` | `Importance` | Message importance: `LOW`, `NORMAL`, or `HIGH` |
| `conversationId` | `?string` | The conversation or thread ID grouping related messages |
| `internetMessageId` | `?string` | The RFC 2822 Message-ID header value |
| `parentFolderId` | `?string` | The ID of the folder containing this message |
| `raw` | `array<string, mixed>` | The complete raw response from the provider |

### EmailAddressDto

Each address field (`from`, `sender`, recipients) is an `EmailAddressDto` with two properties:

| Property | Type | Description |
|---|---|---|
| `name` | `string` | Display name (e.g., "Jane Smith") |
| `address` | `string` | Email address (e.g., "jane@acme.com") |

```php
$message = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->get();

echo $message->from->name;    // "Billing Department"
echo $message->from->address;  // "billing@vendor.com"

foreach ($message->toRecipients as $recipient) {
    echo "{$recipient->name} <{$recipient->address}>";
}
```

### Serialization

Because `MessageDto` implements `Arrayable` and `JsonSerializable`, you can easily convert it for API responses or storage:

```php
$message = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->get();

$array = $message->toArray();
$json = json_encode($message);
```

## Message Operations

The message resource provides methods for every common action. Each method operates on a single message identified by its ID.

### Marking as Read

```php
Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->markAsRead();
```

### Marking as Unread

```php
Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->markAsUnread();
```

### Moving to a Folder

The `moveTo()` method moves a message and returns the updated `MessageDto` (the message ID may change after a move, depending on the provider):

```php
$moved = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->moveTo(WellKnownFolder::ARCHIVE); // MessageDto

// Use a custom folder ID
$moved = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->moveTo('Label_8675309');
```

### Copying to a Folder

The `copyTo()` method creates a copy in the target folder and returns the new message's `MessageDto`:

```php
$copy = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->copyTo(WellKnownFolder::ARCHIVE); // MessageDto

echo $copy->id; // The new copy's ID
```

### Deleting a Message

The `delete()` method permanently deletes a message. On Microsoft Graph, this performs a hard delete. On Gmail, the message is trashed.

```php
Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->delete();
```

> **Warning**
> Deletion is irreversible on some providers. If you want to move messages to trash instead, use `moveTo(WellKnownFolder::DELETED)`.

### Listing Attachments

Retrieve metadata about all attachments on a message without downloading them:

```php
$attachments = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->attachments(); // Collection<int, AttachmentDto>

foreach ($attachments as $attachment) {
    echo $attachment->name;        // "invoice-2025-001.pdf"
    echo $attachment->contentType;  // "application/pdf"
    echo $attachment->size;         // 204800
}
```

The `AttachmentDto` has these properties:

| Property | Type | Description |
|---|---|---|
| `id` | `string` | Provider-specific attachment identifier |
| `name` | `string` | The filename |
| `contentType` | `string` | MIME type (e.g., `application/pdf`) |
| `size` | `int` | File size in bytes |
| `isInline` | `bool` | Whether this is an inline/embedded attachment |
| `contentId` | `?string` | Content-ID for inline attachments (used in HTML references) |

### Accessing a Single Attachment

Use `attachment()` to get an `AttachmentResource` for a specific attachment, which provides `metadata()`, `download()`, and `stream()` methods:

```php
$file = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->attachment($attachmentId)
    ->download(); // AttachmentFileDto
```

### Downloading All Attachments

The `downloadAttachments()` method downloads every non-inline attachment and returns a collection of `AttachmentFileDto` instances. Pass `true` to include inline attachments:

```php
// Non-inline attachments only
$files = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->downloadAttachments(); // Collection<int, AttachmentFileDto>

// Include inline attachments (embedded images, etc.)
$allFiles = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->downloadAttachments(includeInline: true);
```

Each `AttachmentFileDto` contains:

| Property | Type | Description |
|---|---|---|
| `id` | `string` | Provider-specific attachment identifier |
| `name` | `string` | The filename |
| `contentType` | `string` | MIME type |
| `size` | `int` | File size in bytes |
| `isInline` | `bool` | Whether this is an inline attachment |
| `contentId` | `?string` | Content-ID for inline attachments |
| `path` | `string` | The storage path where the file was saved |
| `disk` | `string` | The Laravel filesystem disk used |
| `alreadyExisted` | `bool` | Whether the file already existed at the path |

For more details, see [Attachments](attachments.md).

## Bulk Operations

The query builder provides bulk versions of common operations that are optimized for each provider. Microsoft Graph uses JSON batch requests, and Gmail uses batch modify endpoints -- both are handled automatically.

### Bulk Mark as Read

```php
$messageIds = ['AAMkAGI2TG93AAA=', 'AAMkAGI2TG94AAA=', 'AAMkAGI2TG95AAA='];

Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->markAsRead($messageIds);
```

### Bulk Mark as Unread

```php
Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->markAsUnread($messageIds);
```

### Bulk Move

```php
Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->moveTo(WellKnownFolder::ARCHIVE, $messageIds);
```

You can also build the ID list dynamically from a query, then pass those IDs to a bulk operation:

```php
$staleIds = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->where(FilterableField::IS_READ, true)
    ->where(FilterableField::RECEIVED_AT, MatchOperator::BEFORE, now()->subMonths(6))
    ->get()
    ->pluck('id')
    ->all();

Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->moveTo(WellKnownFolder::ARCHIVE, $staleIds);
```

> **Tip**
> Bulk operations handle large ID lists gracefully. Gmail batches are automatically chunked into groups of 1,000 (the provider's maximum per request). Microsoft Graph uses its JSON batch API. You do not need to manually chunk your arrays.

## Working with Raw Provider Data

Every `MessageDto` carries the complete, unmodified response from the provider in its `raw` property. This is useful when you need provider-specific fields that Mailbox does not map to a named property:

```php
$message = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->get();

// Microsoft Graph: access the full raw payload
$categories = $message->raw['categories'] ?? [];
$webLink = $message->raw['webLink'] ?? null;

// Gmail: access label IDs, thread metadata, etc.
$labelIds = $message->raw['labelIds'] ?? [];
$threadId = $message->raw['threadId'] ?? null;
$sizeEstimate = $message->raw['sizeEstimate'] ?? 0;
```

> **Tip**
> The `raw` property is especially helpful during development. Dump it to see exactly what the provider returns: `dd($message->raw)`.

## Practical Example: Processing an Invoice Queue

Here is a complete, real-world example that ties everything together. This command checks a shared mailbox for unread invoice emails, downloads the PDF attachments, and archives the processed messages:

```php
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\MatchOperator;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Facades\Mailbox;

$mailbox = Mailbox::mailbox('invoices@acme.com');

// 1. Find unread messages with attachments from our vendor
$invoices = $mailbox->messages()
    ->inFolder(WellKnownFolder::INBOX)
    ->where(FilterableField::IS_READ, false)
    ->where(FilterableField::HAS_ATTACHMENTS, true)
    ->where(FilterableField::FROM_ADDRESS, MatchOperator::ENDS_WITH, '@vendor.com')
    ->where(FilterableField::SUBJECT, MatchOperator::CONTAINS, 'Invoice')
    ->orderBy('receivedAt', 'asc')
    ->take(50)
    ->get();

$processedIds = [];

// 2. Process each invoice
foreach ($invoices as $message) {
    $body = $mailbox->message($message->id)->body();

    // Download only non-inline attachments
    $files = $mailbox->message($message->id)->downloadAttachments();

    foreach ($files as $file) {
        if ($file->contentType === 'application/pdf') {
            // Queue the PDF for processing
            ProcessInvoicePdf::dispatch($file->path, $file->disk, [
                'from' => $message->from->address,
                'subject' => $message->subject,
                'received' => $message->receivedAt->toDateTimeString(),
            ]);
        }
    }

    $processedIds[] = $message->id;
}

// 3. Mark all processed messages as read in one bulk call
if ($processedIds !== []) {
    $mailbox->messages()->markAsRead($processedIds);

    // 4. Archive them
    $mailbox->messages()->moveTo(WellKnownFolder::ARCHIVE, $processedIds);
}

echo sprintf('Processed %d invoices.', count($processedIds));
```

This example demonstrates folder scoping, multi-field filtering, body retrieval, attachment downloading, and bulk operations all working together in a cohesive workflow.

## What's Next

- [Attachments](attachments.md) -- downloading, streaming, and working with individual attachments in depth
- [Folders](folders.md) -- listing, creating, and managing mail folders
- [Delta Sync](delta-sync.md) -- efficiently tracking changes with incremental synchronization

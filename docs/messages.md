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

To read the HTML or plain-text body of a specific message, use the `body()` method on a message resource:

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
> A successful Gmail `get()` or `body()` read hydrates externalized text parts. Gmail list and legacy delta reads use the same body reader. Missing or malformed content throws an exception; `body()` does not replace missing text with an empty string. A valid zero-byte text part still returns an empty body.

### Gmail Body Limits

Gmail reads share an 8 MiB response budget across the message and its body fetches. They also allow up to 4 MiB of decoded body content, 32 external body parts and 256 examined MIME parts per message. The byte limit includes all text alternatives, checks wire bytes and UTF-8 output, and checks the combined body. The limits apply to actual content, including responses whose declared length is wrong. Safe read retries use the configured retry policy and consume the same response budget, without releasing the host's enclosing job.

You may pass different limits to the concrete Gmail message resource's `get()` or `body()` method:

```php
use Pyle\Mailbox\DTOs\BodyReadLimits;

$body = $gmailMailbox->message($messageId)->body(new BodyReadLimits(
    maxResponseBytes: 16 * 1024 * 1024,
    maxBodyBytes: 8 * 1024 * 1024,
    maxHydrations: 64,
    maxParts: 512,
));
```

Exceeding a limit throws `ResponseLimitExceededException`. No partial `MessageDto` or body is returned. The host may retry the read with suitable limits; these exceptions do not establish completed synchronization work.

Gmail body data is decoded from base64url and its declared charset is converted to UTF-8. Missing charset declarations use UTF-8. MIME transfer-encoding headers are not decoded a second time: Gmail supplies parsed part bytes. Multipart alternatives prefer HTML, and multipart fragments with the same format are joined in order. Mixed sibling HTML and plain-text fragments cannot fit the single-format `BodyDto` contract and throw explicitly. Named parts and files with Content-ID metadata remain attachments, including text files. Sanitization, rendering and application file rules remain the host's responsibility.

## Reading a Complete Conversation

Use the optional `SupportsConversationRead` capability when you already have a provider conversation ID. The host chooses which conversations to read, including its activity and import-cutoff policy.

```php
use Pyle\Mailbox\Contracts\SupportsConversationRead;
use Pyle\Mailbox\DTOs\BodyReadLimits;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;

$mailbox = Mailbox::forGrant($context, $tokens);

if (! $mailbox instanceof SupportsConversationRead) {
    throw new UnsupportedCapabilityException('This mailbox does not support conversation reads.');
}

$scope = new SyncScope(importSince: new DateTimeImmutable('2026-10-01T00:00:00Z'));
$budget = new SyncBudget(maxPages: 5, maxRequests: 10);
$limits = new BodyReadLimits;
$read = $mailbox->conversation($conversationId, $scope, $budget, limits: $limits);

foreach ($read->messages as $message) {
    // Retain this complete MessageDto in your host's projection.
}

foreach ($read->files as $file) {
    $messageId = $file['messageId'];
    $metadata = $file['attachment']; // AttachmentDto; no file bytes are downloaded.
}
```

This capability requires an explicit grant context. Existing custom drivers keep their basic resource contracts and may omit the capability.

Gmail reads the selected thread in the selected mailbox, including messages older than `importSince` and messages with different labels. A folder or cutoff in `SyncScope` describes the host's selection context; it does not trim the selected Gmail thread. Gmail's whole-thread endpoint has no page-size parameter, so a request count alone cannot bound its payload. Externalized text is hydrated through the same MIME reader as individual-message reads. Named and inline files remain file metadata.

Graph filters messages by `conversationId` in the requested mailbox or one requested folder. It follows every message page and attachment-metadata page within that scope, including inline files when `hasAttachments` is false. A folder scope covers that folder's message collection, not its child folders. A mailbox scope covers the provider's mailbox message collection; it does not claim access to another mailbox, archive store or inaccessible shared folders. Graph also retains older messages in the selected conversation rather than applying the selection cutoff again.

`ConversationRead::identity` declares the provider, selected grant/generation, mailbox and requested scope. `messages` contains complete message DTOs from this call; `files` associates complete file metadata with its message ID. The host still owns HTML sanitization, file authorization, retention and presentation. A completed conversation read does not create a synchronization checkpoint or establish that a synchronization round has completed.

### Retaining Unfinished Work

Each invocation has fresh work budgets. `SyncBudget` limits message items, provider collection/thread pages, all HTTP requests and aggregate response bytes. Graph attachment-metadata pages count as pages; individual body hydration requests count as requests. `BodyReadLimits` applies the tighter response-byte limit and bounds combined decoded/assembled body bytes, hydration and MIME parts or Graph file-metadata entries. All secondary requests share these budgets. Conversation reads do not retry provider requests or release the host's queue job.

An oversized thread, unavailable scope, malformed content, interruption or exhausted budget returns `complete: false`, a `reason`, and an unfinished `continuation`. Returned messages and their file metadata are complete; an unfinished message stays pending. Retain the returned work and continuation together in private host storage before advancing. To continue in a later host operation:

```php
if (! $read->complete) {
    $next = $mailbox->conversation(
        $conversationId,
        $scope,
        $budget,
        continuation: $read->continuation,
        limits: $limits,
    );
}
```

Combine completed messages from every call. A continuation binds the provider, connection, grant, generation, mailbox, folder, cutoff, conversation ID, phase and supported format version. A changed selected grant fails explicitly; it never falls back to configured authorization. Ordinary result JSON and debug output redact private continuation positions. Retain the actual continuation fields or trusted PHP serialization for private recovery, not the redacted public JSON, and never accept recovery state from an untrusted client.

Reasons include `item_limit`, `page_limit`, `request_limit`, `response_limit`, `content_limit`, Graph `hydration_limit` / `part_limit`, `scope_unavailable`, `provider_interrupted`, and `content_incomplete`. An unpaged Gmail payload may need a larger byte budget before any message IDs can be retained. A single message's complete content or metadata may also need larger limits; the SDK does not silently omit it or impose a hidden cap. These reads observe mutable provider state rather than an atomic snapshot; later synchronization reconciles arrivals, moves and other changes.

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

## Submitting a Prepared Gmail Message

You may submit text and HTML messages through Gmail's optional `SupportsMessageSubmission` capability. Your application chooses the sender, recipients, content and correlation identity. Constructing or editing a `PreparedMessage` performs no provider operation; calling `submit` explicitly requests a send.

```php
use Pyle\Mailbox\Contracts\SupportsMessageSubmission;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Facades\Mailbox;

// Reuse your explicitly selected GrantContext and GrantTokenProvider.
$mailbox = Mailbox::forGrant($context, $tokens);

if (! $mailbox instanceof SupportsMessageSubmission) {
    throw new UnsupportedCapabilityException('This mailbox does not support direct submission.');
}

$message = new PreparedMessage(
    sender: new EmailAddressDto('Élodie', 'sender@example.com'),
    to: [new EmailAddressDto('Zoë', 'recipient@example.com')],
    subject: 'Meeting confirmation',
    correlationId: 'host-operation-123',
    textBody: 'Your meeting is confirmed.',
    htmlBody: '<p>Your meeting is confirmed.</p>',
    cc: [],
    bcc: [],
    headers: ['X-Project' => 'Customer support'],
);

// Persist the operation and its chosen intent before requesting submission.
$result = $mailbox->submit($message, maxRequestBytes: 1_048_576);
```

The capability is available on Gmail mailbox resources, including configured Google Workspace resources. Existing custom drivers do not need to implement it. The selected authorization must permit Gmail submission, such as the `gmail.send` scope, and Gmail must authorize the chosen sender. The SDK does not choose an alias or elevate authorization.

### Explicit Recipients and Safe Encoding

Provide at least one address in `to`, `cc` or `bcc`. Bcc-only messages are supported. Each submission uses only that message's explicit lists; it never retains recipients from an earlier message. Gmail routes mail using the serialized To/Cc/Bcc headers, so the SDK retains the explicit Bcc header for Gmail even though Symfony's usual envelope-based serialization removes it.

Names, subjects and text headers support UTF-8. Text and HTML bodies must also contain valid UTF-8. The SDK rejects header controls, injection, invalid addresses and unsupported field names before any send. Structural headers and structured fields, including From, Sender, To/Cc/Bcc, Reply-To, Subject, Date, Message-ID, Return-Path, MIME-Version, Content-*, Resent-* and DKIM-Signature, cannot be supplied as text-header overrides. The encoder creates the MIME structure, Date and Message-ID itself.

`correlationId` is required, contains no control characters and is limited to 512 bytes. It is returned with submission evidence and encoded in `X-Mailbox-Correlation-Id`; choose an opaque value that is safe for recipients to see. Applications cannot override this header separately.

### Submission Evidence

`submit` makes one provider request. It never retries that request, falls back to another grant, or releases an enclosing queue job. Existing safe-read retries remain separate. A stale or disconnected selected grant fails before the send; a token refresh failure also remains a preflight failure.

| Outcome | Meaning | Host responsibility |
| --- | --- | --- |
| `SubmissionOutcome::Accepted` | Gmail returned HTTP 200 with a valid message identity and, when supplied, a valid thread identity. | Retain the provider IDs and original correlation. Acceptance is not delivery proof. |
| `SubmissionOutcome::Rejected` | A known rejection response, such as HTTP 400, 401, 403 or 429, was observed. | Retain the response evidence and decide how to correct or schedule the operation. |
| `SubmissionOutcome::Uncertain` | A timeout, 5xx, redirect, oversized/interrupted response or malformed acknowledgement prevents a reliable acceptance decision. | Retain the original operation and investigate it. Uncertainty does not authorize another send. |

`SubmissionResult` contains `provider`, `outcome`, `correlationId`, nullable HTTP `status`, safe response `headers`, and nullable validated `messageId` / `threadId`. It is not a fetched `MessageDto`. An empty Gmail 202 is uncertain; it does not have Graph's send-acceptance semantics.

The SDK retains safe request identity, Date and Retry-After response headers. Authorization, cookies, redirect/upload URLs, arbitrary response bodies and raw transport exceptions are excluded from public submission evidence. If no response was observed, `status` is `null`. A response body beyond 64 KiB cannot establish acceptance, but its observed status and safe headers remain available.

Your application owns durable operation state and recovery. Persist the chosen message and correlation before sending, then retain the returned evidence. The SDK does not maintain an outbox, deduplicate separate explicit calls, mirror private editor drafts, inspect sent copies automatically or claim recipient delivery. Injected HTTP clients must not add their own retry middleware for submission.

### Prepared Files

Pass selected files in `PreparedMessage::files`. Each `PreparedFile` carries your stable file identity, name, media type, decoded byte length, optional inline disposition/Content-ID, and the original expected SHA-256. The source returns a fresh readable PSR stream; opening it transfers ownership of that stream to the SDK. Your application retains the original storage.

```php
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\DTOs\PreparedFile;

$path = storage_path('app/private/report.pdf');

// Capture and persist this original metadata when your host prepares the file.
$file = new PreparedFile(
    identity: 'report-version-123',
    name: 'État annuel.pdf',
    mediaType: 'application/pdf',
    byteLength: filesize($path),
    source: static fn (): StreamInterface => Utils::streamFor(fopen($path, 'rb')),
    sha256: hash_file('sha256', $path),
);

// Supply files: [$file] when constructing your PreparedMessage.
```

Keep the original fingerprint and length when restoring a manifest. Do not recompute them from a mutable source on resume. Every `openVerified()` reopens, rewinds a seekable source, copies it into a private owned snapshot, and verifies all bytes against that original binding before returning any content. Close the returned snapshot after use. A changed same-length source, incorrect length, interrupted read or mismatched fingerprint fails before further serialization/upload/send. Matching identity, path, name and length alone does not establish immutable content.

Submission closes its own source streams and temporary snapshots on success and failure. It never removes or overwrites the host's original files. Your application owns file authorization, private storage, allowed media types, checksum persistence and durable operation state. Source callbacks and injected middleware must honor their own resource limits.

Names support safe UTF-8. The SDK preserves the selected files, explicit inline disposition, supplied Content-IDs and original text/HTML; it does not infer IDs from filenames, deduplicate filenames, add files, or replace them with links. Inline disposition may omit Content-ID. Supplied IDs must be safe unique tokens, and file identities must be unique within the message. Gmail's MIME encoder additionally requires supplied IDs in `name@domain` form; an unsupported ID fails explicitly before opening files.

### Limits and Unsupported Input

`maxRequestBytes` is a positive caller budget for the actual Gmail JSON request, including MIME/base64url expansion. Its default remains 1 MiB; it is not a universal Gmail message or file limit. File-bearing messages often need a larger explicit wire budget.

Gmail resources also expose the optional `SupportsSubmissionLimits` capability:

```php
use Pyle\Mailbox\Contracts\SupportsSubmissionLimits;
use Pyle\Mailbox\DTOs\SubmissionLimits;

if (! $mailbox instanceof SupportsSubmissionLimits) {
    throw new UnsupportedCapabilityException('This mailbox cannot configure submission limits.');
}

$bounded = $mailbox->withSubmissionLimits(new SubmissionLimits(
    maxFileBytes: 10_485_760,
    maxTotalFileBytes: 20_971_520,
    maxMemoryBytes: 8_388_608,
));

$result = $bounded->submit($message, maxRequestBytes: 50_331_648);
```

These per-file and aggregate values are the existing consumer's 10 MiB/20 MiB parity targets, not universal defaults. The default file limits impose no additional host policy (`PHP_INT_MAX`); the request and MIME caps still apply. Lengths are checked against actual source bytes and fingerprints. All configured limits must be positive.

The SDK caps serialized MIME at **36,700,160 bytes (35 MiB)**, matching the send method's `mediaUpload.maxSize` in Google's [Gmail discovery document](https://gmail.googleapis.com/$discovery/rest?version=v1). This is a MIME/media cap, separately enforced from the JSON wire budget. Google's [upload guide](https://developers.google.com/workspace/gmail/api/guides/uploads) distinguishes media uploads from the standard metadata endpoint; the SDK does not invent an equivalent JSON-body ceiling. Twenty MiB of decoded files expands through MIME attachment base64 and outer base64url, so its JSON body can exceed 35 MiB while its MIME remains below that cap. Provider/account acceptance remains a separate live-validation gate.

The submit path streams MIME chunks through base64url into a private temporary JSON spool; it does not build whole MIME, base64url and JSON strings. Actual MIME and JSON bytes are counted before send. `maxMemoryBytes` defaults to 8 MiB, with conservative reservations before SDK-controlled header and buffer allocations and checks against retained operation growth. It is not a total process/RSS ceiling: first-use PHP class loading, framework cache/Carbon/logging and HTTP initialization can create additional transient peaks. Source callbacks, arbitrary HTTP middleware, native libraries and other work in the process also remain outside that guarantee. Temporary disk capacity must accommodate the verified file snapshots, body spools and expanded JSON request.

Use a streaming HTTP transport for large requests. The installed Guzzle cURL handler streams large bodies; its PHP stream handler materializes an outgoing body. The SDK's request stream checks the allocation before `getContents()` or string conversion, and bounded reads remain available. A buffering transport that cannot fit the chosen budget fails conservatively after the request boundary, returning uncertainty without replay. Oversized preparation, MIME or JSON fails with `UnsupportedCapabilityException` before provider I/O. Preserve the original operation whenever the send outcome is uncertain.

The `submission-wire` tests are an explicit serial verification group. Ordinary runs skip them unless `MAILBOX_SUBMISSION_CONTROL_PATH` names a fresh owned measurement receipt. Run the tiny cold control and each large case in separate fresh PHP invocations, without parallel workers:

```powershell
$mailboxControlPath = Join-Path $env:TEMP ('mailbox-control-' + [guid]::NewGuid() + '.json')
$env:MAILBOX_SUBMISSION_CONTROL_PATH = $mailboxControlPath
php vendor/bin/pest tests/Sdk/GmailSubmissionWireTest.php --filter='small file' --compact
php vendor/bin/pest tests/Sdk/GmailSubmissionWireTest.php --filter='cold 10 MiB' --compact
php vendor/bin/pest tests/Sdk/GmailSubmissionWireTest.php --filter='cold 20 MiB' --compact
Remove-Item Env:\MAILBOX_SUBMISSION_CONTROL_PATH
```

Each invocation starts one owned local TLS server with a client-local CA and routes the original trusted Gmail HTTPS destination to it through cURL. No provider account, global DNS or trust store is changed. The server independently inspects received JSON/MIME and exact file hashes. The tests report absolute PHP heap growth, bind the cold receipt to the same PHP/runtime and package source, and require each large case's excess above that cold control to remain within the unchanged 8 MiB allocation budget. This separates demonstrated first-use framework/transport costs from payload-size growth; it does not claim an 8 MiB total cold heap. The receipt contains fixture measurements only and may be removed after verification.

Verified on 64-bit PHP 8.4.25 with Guzzle 7.16.1 and Symfony Mime 8.1.10, in fresh serial processes:

| Decoded files | Actual MIME bytes | Actual JSON bytes | Absolute cold heap growth | Growth above cold control |
| --- | ---: | ---: | ---: | ---: |
| 8 KiB control | 11,813 | 15,761 | 9,261,504 | — |
| 10 MiB | 14,349,539 | 19,132,729 | 9,259,056 | 0 |
| 20 MiB aggregate | 28,698,660 | 38,264,890 | 9,259,024 | 0 |

These are local protocol and PHP-buffer measurements. They do not establish live Gmail acceptance, delivery, total native/RSS memory, other PHP/runtime behavior or the host's storage capacity. The [ticket 18 receipt](plans/mailbox-provider-sdk/tickets/18-freeze-file-streams-for-gmail-submission.md) retains the original cold-heap failure, measured source fingerprint and separate verification gates.

Arbitrary raw MIME input and signed/encrypted MIME reconstruction remain unsupported. Reply/forward preparation is a separate capability; the SDK does not infer it from a body or silently transform arbitrary MIME.

## Preparing and Sending a Graph Draft

Graph exposes the optional `SupportsDraftSubmission` capability. Your application explicitly starts preparation, retains the returned draft identity, then requests send in a separate call. Constructing or editing a `PreparedMessage` makes no provider request.

```php
use Pyle\Mailbox\Contracts\SupportsDraftSubmission;
use Pyle\Mailbox\Enums\PreparationOutcome;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;

$mailbox = Mailbox::forGrant($context, $tokens);

if (! $mailbox instanceof SupportsDraftSubmission) {
    throw new UnsupportedCapabilityException('This mailbox does not support draft submission.');
}

$preparation = $mailbox->prepareSubmission($message);

// Retain this preparation and the original message in your durable private storage.
if ($preparation->outcome === PreparationOutcome::Prepared) {
    // A separate authorized host operation may now request send.
    $result = $mailbox->submitPrepared($preparation, $message);
}
```

This capability requires an explicit grant context. It does not add methods to mandatory resource contracts or change Gmail's direct submission API. `PreparedMessage::sender` maps to Graph's `from` field; Graph sets the actual `sender` according to the selected authorization and Exchange permissions.

### Retaining Preparation and Send Evidence

`SubmissionPreparation` retains the selected context and generation, caller correlation, preparation outcome, immutable `draftId`, observed status and safe headers. Its private original-intent fingerprint binds the later call to the same message. Retain every constructor field, including the value returned by `intentFingerprint()`, when reconstructing a handle from private host storage. Ordinary JSON and debug output omit that fingerprint; PHP serialization retains it for trusted private storage.

| Preparation outcome | Meaning |
| --- | --- |
| `PreparationOutcome::Prepared` | Graph returned HTTP 201 and a valid immutable draft identity. No send has been requested. |
| `PreparationOutcome::Rejected` | A definite rejection response was observed. No send has been requested. |
| `PreparationOutcome::Uncertain` | A draft may exist, but its creation acknowledgement is missing or unusable. No send has been requested. |

An uncertain preparation never causes another draft creation or an automatic send. The draft may be visible in Outlook and may remain after an interrupted or failed operation. The SDK does not automatically delete it.

`submitPrepared` checks the context, generation and original intent before provider I/O. It then reads the immutable draft, verifies its content and explicit To/Cc/Bcc lists, and makes one send request. Missing, changed, incomplete or unverifiable draft state stops before send. Each phase checks the selected grant again; a disconnected or superseded grant never falls back to configured application credentials.

Failed verification throws `ApiRequestException` with the observed `status` and filtered safe `headers`, including an available request ID. It retains no raw response body or secret-bearing cause, and no send has been requested.

Verification ignores JSON object-key order, recipient-list order, email-domain casing, header-name/body-type casing, and CRLF versus LF body line endings. Recipient counts, local address parts, display names, subject, header values and body content remain unchanged. Additional provider-generated headers are allowed; each caller-selected header and correlation must appear exactly once. No HTML rewriting, whitespace trimming or changed recipient/content value is treated as harmless normalization.

An empty HTTP 202 returns `SubmissionOutcome::Accepted` with status, safe headers and the original correlation. `messageId` and `threadId` remain `null`; retain the separate preparation handle for its draft identity. Acceptance does not prove delivery. Timeout, 5xx, redirects and malformed or interrupted acknowledgements remain uncertain, without replay, enclosing-job release or cleanup.

The host owns durable operation state, exclusive submission scheduling and any recovery decision. Separate explicit calls are not deduplicated by the SDK. Do not add retry middleware to an injected HTTP client. Treat provider drafts as submission artifacts and prevent concurrent editing; verification is a pre-send observation, not an atomic lock against other Outlook clients.

### Graph Limits and Permissions

This path accepts one UTF-8 text **or** HTML body, explicit recipients and custom `X-` headers. Supplying both body formats, nonempty files, raw MIME, native reply/forward intent, or unsupported headers requires a separate capability; the SDK does not silently discard fields. Correlation and address/header validation follow the structured prepared-message rules above.

`maxRequestBytes` is a positive caller budget, defaulting to 4,000,000 bytes. The actual encoded JSON must fit that budget and remain strictly below Graph's 4 MB write limit. Draft creation and verification responses are bounded to 4 MiB; send acknowledgements retain the shared 64 KiB limit. Overflow, truncation or inconsistent unencoded Content-Length preserves observed status and safe headers without establishing successful preparation, verification or send.

One-attempt receipts request identity encoding and disable automatic decompression. An encoded response is closed without reading its body; its status and safe headers remain available, but it cannot establish preparation or send acceptance. This keeps compressed bytes from expanding before the response budget can run.

Every preparation, verification and send request explicitly uses `Prefer: IdType="ImmutableId"`. Existing reads still honor the configured `prefer_immutable_ids` value. The new phases do not change that configuration.

Own-mailbox preparation requires `Mail.ReadWrite`, and sending requires `Mail.Send`, with the appropriate delegated or administrator-consented application permissions. Organizational delegated shared access also requires `Mail.ReadWrite.Shared`, `Mail.Send.Shared`, and the target's Exchange mailbox permissions. Sending through the target mailbox requires Full Access plus Send As or Send on Behalf. Token acquisition alone does not establish these permissions, and rejection never switches grants. See Microsoft's [draft creation](https://learn.microsoft.com/en-us/graph/api/user-post-messages?view=graph-rest-1.0), [draft send](https://learn.microsoft.com/en-us/graph/api/message-send?view=graph-rest-1.0), [shared sending](https://learn.microsoft.com/en-us/graph/outlook-send-mail-from-other-user), and [immutable identity](https://learn.microsoft.com/en-us/graph/outlook-immutable-id) guidance.

Live account permissions, provider normalization and delivery remain separate acceptance gates. HTTP fixtures do not establish them.

## What's Next

- [Attachments](attachments.md) -- downloading, streaming, and working with individual attachments in depth
- [Folders](folders.md) -- listing, creating, and managing mail folders
- [Delta Sync](delta-sync.md) -- efficiently tracking changes with incremental synchronization

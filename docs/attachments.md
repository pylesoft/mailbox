# Attachments

Most mail integrations eventually need to download files -- invoices, contracts, receipts, or embedded images. Mailbox can list attachment metadata, download files to your Laravel filesystem disk, or return their bytes as streams. Downloads reuse existing files when their content matches; the provider bytes are still read to verify that match.

Attachments are accessed through the message resource. You start with a message ID, then branch into metadata listing, single-file downloads, bulk downloads, or raw streaming.

For outgoing files, use [prepared immutable files](messages.md#prepared-files) and [staged Graph attachment preparation](messages.md#preparing-graph-attachments). The host's 10 MiB-per-file and 20 MiB aggregate targets remain separate [live acceptance cases](plans/mailbox-provider-sdk/live-acceptance.md), including Microsoft's shared/delegated large-file limitation.

## Listing Attachments

To see what files are attached to a message without downloading anything, call `attachments()` on a message resource:

```php
use Pyle\Mailbox\Facades\Mailbox;

$attachments = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->attachments(); // Collection<int, AttachmentDto>

foreach ($attachments as $attachment) {
    echo $attachment->name;        // "invoice-2025-001.pdf"
    echo $attachment->contentType;  // "application/pdf"
    echo $attachment->size;         // 204800
    echo $attachment->isInline;     // false
}
```

This returns a collection of `AttachmentDto` instances with lightweight metadata. No file content is transferred -- only names, types, sizes, and inline flags. Use this when you need to present a list of attachments to the user or decide which files are worth downloading.

## Downloading a Single Attachment

When you know exactly which attachment you need, use the `attachment()` method to get an `AttachmentResource`, then call `download()`:

```php
$file = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->attachment($attachmentId)
    ->download(); // AttachmentFileDto

echo $file->path; // "mailbox-attachments/invoices_acme_com/AAMk.../invoice-2025-001.pdf"
echo $file->disk; // "local"
```

The `download()` method fetches the file content from the provider, saves it to your configured Laravel filesystem disk, and returns an `AttachmentFileDto` with the storage location. If that destination already has the same verified content, the stored file is reused and `alreadyExisted` is set to `true`. Provider bytes are still read to verify the match.

### Retrieving Metadata Only

If you need the metadata for a specific attachment without downloading the file, call `metadata()` instead:

```php
$meta = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->attachment($attachmentId)
    ->metadata(); // AttachmentDto

echo $meta->name;        // "quarterly-report.xlsx"
echo $meta->contentType;  // "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
echo $meta->size;         // 1048576
```

## Downloading All Attachments

The `downloadAttachments()` method on a message resource downloads every non-inline attachment in one call and returns a collection of `AttachmentFileDto` instances:

```php
$files = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->downloadAttachments(); // Collection<int, AttachmentFileDto>

foreach ($files as $file) {
    echo $file->name;    // "invoice-2025-001.pdf"
    echo $file->path;    // "mailbox-attachments/invoices_acme_com/AAMk.../invoice-2025-001.pdf"
    echo $file->disk;    // "local"
}
```

By default, inline attachments (embedded images referenced in the HTML body) are excluded. To include them, pass `true`:

```php
$allFiles = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->downloadAttachments(includeInline: true); // Collection<int, AttachmentFileDto>
```

> **Tip**
> Inline attachments typically are images embedded in the message body via `Content-ID` references. Unless you are rendering the full HTML body and need those images, you can usually skip them to save storage.

## Streaming Attachments

Use `stream()` to read validated bytes without saving a permanent copy on your configured filesystem disk:

```php
use Psr\Http\Message\StreamInterface;

$stream = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->attachment($attachmentId)
    ->stream(); // StreamInterface
```

The `stream()` method returns a PSR-7 `StreamInterface`. You can read it incrementally, pass it to Guzzle, or convert it to a Laravel response:

```php
// Return the attachment as an HTTP download
$meta = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->attachment($attachmentId)
    ->metadata();

$stream = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->attachment($attachmentId)
    ->stream();

return response()->streamDownload(function () use ($stream) {
    try {
        while (! $stream->eof()) {
            echo $stream->read(8192);
        }
    } finally {
        $stream->close();
    }
}, $meta->name, [
    'Content-Type' => $meta->contentType,
]);
```

> **Note**
> The built-in providers validate the complete read into an owned temporary file before returning its stream. Closing or releasing the stream removes that temporary file. Streaming does not save to your configured disk or trigger deduplication events. `AttachmentDownloaded` and `AttachmentSkipped` are dispatched by `download()` and `downloadAttachments()`.

## Read Limits

The built-in Gmail and Graph resources support the optional `SupportsAttachmentReadLimits` capability. You may select limits for one resource without changing the mandatory attachment interface used by custom drivers:

```php
use Pyle\Mailbox\Contracts\SupportsAttachmentReadLimits;
use Pyle\Mailbox\DTOs\AttachmentReadLimits;

$attachment = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->attachment($attachmentId);

if (! $attachment instanceof SupportsAttachmentReadLimits) {
    throw new LogicException('This driver does not support bounded attachment reads.');
}

$file = $attachment->withReadLimits(new AttachmentReadLimits(
    maxResponseBytes: 32 * 1024 * 1024,
    maxFileBytes: 10 * 1024 * 1024,
    maxMemoryBytes: 64 * 1024 * 1024,
))->download();
```

The default technical limits are 64 MiB of provider response bytes, 32 MiB of file bytes, and a 128 MiB allocation budget. Each operation shares its response and memory budget across its metadata and content requests. Actual bytes count even when a provider declares a smaller size. These limits apply together, so a read can reach its response or memory limit before its file limit. `downloadAttachments()` uses the defaults separately for each attachment.

`ResponseLimitExceededException` reports a limit failure. Invalid encoding, missing content, inconsistent lengths, interrupted streams and unsupported responses raise a `MailboxException` subclass. Valid empty files remain empty files. Authenticated requests stay on the selected provider destination and reject redirects. Bounded reads request identity HTTP content encoding and reject compressed HTTP representations before reading them. A gzip file's media bytes remain valid file content.

### Memory Behavior

Gmail responses contain JSON and base64url content. The SDK retains a bounded encoded JSON envelope, reserves memory for string copies and JSON structure before native decoding, then decodes file bytes in small chunks into an owned temporary stream. It also reserves relevant MIME metadata before the existing parameter parser and path copies before sanitization and output. File-data strings are not counted again as MIME metadata. Gmail memory use therefore grows with the bounded encoded response. Graph raw bytes, content hashing and the built-in storage output path use streams.

The allocation guard covers package-controlled buffers and checks tracked heap growth. Framework and dependency initialization, host adapters, third-party HTTP handlers and filesystem adapters can allocate independently. The configured amount is not a hard ceiling on cold-process memory. The SDK performs no production warmup to hide those costs.

On 64-bit PHP 8.4.25, controlled 10 MiB fixtures measured 29,375,912 bytes of extra heap for Gmail and 92,816 bytes for the Graph download after a public metadata request initialized the transport. The Graph measurement includes real transport receipt, hashing and local filesystem output. A cold 2 MiB operation rejected during framework/transport initialization after 4,593,400 bytes of extra heap. Dense valid and truncated JSON were rejected before native JSON decoding within a 2 MiB budget after transport initialization. These measurements do not establish a runtime-independent allocator bound or constant memory for Gmail.

Read the returned stream incrementally and close it when finished. Calling `getContents()`, casting a stream to a string, detaching its raw resource, or using a host filesystem adapter that buffers content creates caller-controlled allocations outside that measured path. Your application still chooses its storage visibility, file authorization, accepted media types and file-size policy.

### Graph Content Forms

Graph returns file attachments as raw file bytes. Their declared file size must match the observed bytes. Item attachments use the provider's MIME representation, including vCard or iCalendar where appropriate. Item metadata `size` does not have a documented equality guarantee with the raw MIME representation length. `metadata()->size` retains the provider declaration, while `download()->size` reports the actual stored byte length, including when a declaration is omitted. Reference attachments do not support `/$value` and fail explicitly. See the [Graph attachment contract](https://learn.microsoft.com/en-us/graph/api/attachment-get?view=graph-rest-1.0).

## Content-Addressable Deduplication

Mailbox uses SHA-256 content hashing to avoid storing duplicate files. When `download()` or `downloadAttachments()` saves a file, it follows a three-step resolution process:

1. **Preferred path is empty.** If no file exists at the expected path, the file is written there. This is the normal case on first download.

2. **Preferred path exists, content matches.** If a file already exists at the expected path and its SHA-256 hash matches the downloaded content, the download is skipped. The returned `AttachmentFileDto` has `alreadyExisted` set to `true`.

3. **Preferred path exists, content differs.** If a file exists at the expected path but its hash does not match (a different file with the same name), Mailbox appends the first 12 characters of the content hash as a suffix to the filename. For example, `report.pdf` becomes `report-a1b2c3d4e5f6.pdf`. If that hash-suffixed path also exists and its content matches, the download is skipped.

This means you can safely call `downloadAttachments()` multiple times on the same message -- or on different messages that happen to have identically named files -- without overwriting unrelated data or wasting disk space.

```php
// First download: file is written to disk
$first = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->attachment($attachmentId)
    ->download();

echo $first->alreadyExisted; // false
echo $first->path;           // "mailbox-attachments/.../invoice.pdf"

// Second download: identical content, skipped
$second = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->attachment($attachmentId)
    ->download();

echo $second->alreadyExisted; // true
echo $second->path;           // "mailbox-attachments/.../invoice.pdf" (same path)
```

### Events

Mailbox dispatches events during the download process so you can hook into your own logging, auditing, or notification pipeline:

| Event | Dispatched When |
|---|---|
| `AttachmentDownloaded` | A file was downloaded and written to disk |
| `AttachmentSkipped` | A file was skipped because identical content already existed |

Both events carry the `driver`, `mailbox`, `messageId`, `attachmentId`, and `path` properties. `AttachmentDownloaded` additionally includes the `disk` name.

```php
use Pyle\Mailbox\Events\AttachmentDownloaded;
use Pyle\Mailbox\Events\AttachmentSkipped;

Event::listen(AttachmentDownloaded::class, function (AttachmentDownloaded $event) {
    logger()->info('Downloaded attachment', [
        'mailbox' => $event->mailbox,
        'message' => $event->messageId,
        'path' => $event->path,
        'disk' => $event->disk,
    ]);
});

Event::listen(AttachmentSkipped::class, function (AttachmentSkipped $event) {
    logger()->debug('Attachment already existed', [
        'mailbox' => $event->mailbox,
        'message' => $event->messageId,
        'path' => $event->path,
    ]);
});
```

For a complete guide to all events dispatched by Mailbox, see [Events](events.md).

## Storage Configuration

Mailbox stores downloaded attachments using Laravel's filesystem. Two configuration values in `config/mailbox.php` control where files land:

```php
'attachment_disk' => env('MAILBOX_ATTACHMENT_DISK', 'local'),
'attachment_path' => env('MAILBOX_ATTACHMENT_PATH', 'mailbox-attachments'),
```

### Disk

The `attachment_disk` value must match a disk defined in your `config/filesystems.php`. The default is `local`, which stores files in `storage/app`. To store attachments on S3, set the environment variable:

```env
MAILBOX_ATTACHMENT_DISK=s3
```

### Base Path

The `attachment_path` value is the directory prefix within the chosen disk. Files are organized under this path in a predictable structure:

```
{attachment_path}/{safe_mailbox}/{message_id}/{filename}
```

For example, an attachment named `invoice.pdf` on a message in the `invoices@acme.com` mailbox would be stored at:

```
mailbox-attachments/invoices_acme_com/AAMkAGI2TG93AAA=/invoice.pdf
```

The mailbox address replaces `@` and `.` with underscores. Mailbox and message identifiers are then sanitized as single path segments. Unsafe characters in filenames become underscores, and dot-only names use `attachment.bin`. Returned metadata keeps the original provider name.

> **Tip**
> When using a cloud disk like S3, the `attachment_path` becomes the key prefix. You can use lifecycle policies on that prefix to automatically expire old attachments.

## AttachmentDto Properties

The `AttachmentDto` represents attachment metadata without file content. It is returned by `attachments()` and `metadata()`.

| Property | Type | Description |
|---|---|---|
| `id` | `string` | Provider-specific attachment identifier |
| `name` | `string` | The original filename (e.g., `invoice-2025-001.pdf`) |
| `contentType` | `string` | MIME type (e.g., `application/pdf`, `image/png`) |
| `size` | `int` | Provider-declared file size in bytes; zero when omitted |
| `isInline` | `bool` | Whether this is an inline/embedded attachment |
| `contentId` | `?string` | The `Content-ID` header value for inline attachments, used in HTML `cid:` references |

`AttachmentDto` implements `Arrayable` and `JsonSerializable`, so you can convert it directly for API responses:

```php
$attachments = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->attachments();

return response()->json($attachments);
```

## AttachmentFileDto Properties

The `AttachmentFileDto` extends the attachment metadata with storage information. It is returned by `download()` and `downloadAttachments()`.

| Property | Type | Description |
|---|---|---|
| `id` | `string` | Provider-specific attachment identifier |
| `name` | `string` | The original filename |
| `contentType` | `string` | MIME type |
| `size` | `int` | Actual stored file length in bytes |
| `isInline` | `bool` | Whether this is an inline/embedded attachment |
| `contentId` | `?string` | The `Content-ID` header value for inline attachments |
| `path` | `string` | The storage path where the file was saved |
| `disk` | `string` | The Laravel filesystem disk used (matches `attachment_disk` config) |
| `alreadyExisted` | `bool` | `true` if the download was skipped because identical content already existed at the path |

You can use the `path` and `disk` values to retrieve the file later with Laravel's Storage facade:

```php
use Illuminate\Support\Facades\Storage;

$file = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->attachment($attachmentId)
    ->download();

// Read the file back from storage
$contents = Storage::disk($file->disk)->get($file->path);

// Generate a temporary URL (for S3 or other cloud disks)
$url = Storage::disk($file->disk)->temporaryUrl($file->path, now()->addMinutes(30));
```

## Practical Example: Archiving Invoice PDFs

Here is a complete workflow that checks for unread messages with attachments, downloads only the PDF files, and records what was saved:

```php
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Facades\Mailbox;

$mailbox = Mailbox::mailbox('invoices@acme.com');

$messages = $mailbox->messages()
    ->inFolder(WellKnownFolder::INBOX)
    ->where(FilterableField::IS_READ, false)
    ->where(FilterableField::HAS_ATTACHMENTS, true)
    ->take(50)
    ->get();

foreach ($messages as $message) {
    $files = $mailbox->message($message->id)->downloadAttachments();

    foreach ($files as $file) {
        if ($file->contentType !== 'application/pdf') {
            continue;
        }

        if ($file->alreadyExisted) {
            logger()->debug("Skipped duplicate: {$file->name}");
            continue;
        }

        logger()->info("Saved: {$file->name} to {$file->disk}:{$file->path}");

        ProcessInvoice::dispatch($file->path, $file->disk, [
            'from' => $message->from->address,
            'subject' => $message->subject,
        ]);
    }

    $mailbox->message($message->id)->markAsRead();
}
```

## What's Next

- [Messages](messages.md) -- querying, filtering, and the full message lifecycle
- [Folders](folders.md) -- listing, creating, and managing mail folders
- [Events](events.md) -- listening to `AttachmentDownloaded`, `AttachmentSkipped`, and other package events

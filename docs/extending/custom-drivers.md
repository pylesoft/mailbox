# Custom Drivers

Mailbox ships with first-party drivers for Microsoft Graph and Gmail, but you can integrate any mail provider by implementing a handful of PHP contracts. Every driver plugs into the same manager, so your application code stays unchanged when you swap providers. This guide walks you through each contract, shows you how to map provider responses to Mailbox DTOs, and ends with a complete skeleton you can copy-paste into your project.

If you prefer to start from ready-made files, publish the [driver stubs](stubs.md) first and fill in the blanks as you follow along.

## Architecture at a Glance

A custom driver is a tree of small, focused classes:

```
YourDriver (MailboxDriver)
 └── YourMailboxResource (MailboxResource)
      ├── YourMessageQuery (MessageQueryBuilder)
      ├── YourMessageResource (MessageResource)
      │    └── YourAttachmentResource (AttachmentResource)
      ├── YourFolderQuery (FolderQueryBuilder)
      └── YourFolderResource (FolderResource)
```

Each class implements exactly one contract from `Pyle\Mailbox\Contracts`. The driver is the entry point; it creates a mailbox resource, which in turn creates query builders and single-resource handlers on demand. You never need to register the inner classes with the container -- the driver wires them together internally.

## The Driver Contract

The `MailboxDriver` contract is the root of your driver. It handles authentication bootstrapping and gives callers access to individual mailbox resources.

```php
<?php

declare(strict_types=1);

namespace App\Mailbox\Drivers\Postmark;

use Pyle\Mailbox\Contracts\MailboxDriver;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\DTOs\ConnectionTestResult;
use Pyle\Mailbox\DTOs\HealthCheckResult;

class PostmarkDriver implements MailboxDriver
{
    private PostmarkClient $client;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
    ) {
        $this->client = new PostmarkClient($config);
    }

    public function mailbox(string $emailAddress): MailboxResource
    {
        return new PostmarkMailboxResource($this->client, $emailAddress);
    }

    public function testConnection(?string $emailAddress = null): ConnectionTestResult
    {
        $start = microtime(true);

        try {
            $this->client->ping($emailAddress);
            $latency = (int) round((microtime(true) - $start) * 1000);

            return new ConnectionTestResult(
                success: true,
                error: null,
                latencyMs: $latency,
                authenticatedAs: $this->config['server_token'] ?? null,
                accessibleMailboxes: $emailAddress ? [$emailAddress] : [],
            );
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            return new ConnectionTestResult(
                success: false,
                error: $e->getMessage(),
                latencyMs: $latency,
                authenticatedAs: null,
                accessibleMailboxes: [],
            );
        }
    }

    public function healthCheck(): HealthCheckResult
    {
        $tokenValid = false;
        $apiReachable = false;
        $latency = null;

        try {
            $start = microtime(true);
            $this->client->ping();
            $tokenValid = true;
            $apiReachable = true;
            $latency = (int) round((microtime(true) - $start) * 1000);
        } catch (\Throwable) {
            // best effort
        }

        return new HealthCheckResult(
            healthy: $tokenValid && $apiReachable,
            tokenValid: $tokenValid,
            tokenExpiresIn: null,
            apiReachable: $apiReachable,
            latencyMs: $latency,
            secretExpiresAt: null,
            secretExpirationWarning: false,
        );
    }
}
```

The constructor receives the `array $config` slice from `config('mailbox.drivers.your-driver')`. You can use it to instantiate HTTP clients, token managers, or anything else your provider needs.

### ConnectionTestResult

The `testConnection()` method returns a `ConnectionTestResult` with these fields:

| Field | Type | Purpose |
|---|---|---|
| `success` | `bool` | Whether authentication and mailbox probe succeeded |
| `error` | `?string` | Error message on failure, `null` on success |
| `latencyMs` | `?int` | Round-trip time in milliseconds |
| `authenticatedAs` | `?string` | Identifier of the authenticated principal |
| `accessibleMailboxes` | `array<string>` | Email addresses that were successfully probed |

### HealthCheckResult

The `healthCheck()` method returns a `HealthCheckResult`:

| Field | Type | Purpose |
|---|---|---|
| `healthy` | `bool` | Overall health (typically `$tokenValid && $apiReachable`) |
| `tokenValid` | `bool` | Whether the current credential is valid |
| `tokenExpiresIn` | `?int` | Seconds until the token expires, if known |
| `apiReachable` | `bool` | Whether the provider API responded |
| `latencyMs` | `?int` | Round-trip time in milliseconds |
| `secretExpiresAt` | `?CarbonImmutable` | When the client secret expires, if applicable |
| `secretExpirationWarning` | `bool` | `true` when the secret expires within the warning threshold |

## The Mailbox Resource Contract

Once a caller has a driver, they call `mailbox('invoices@acme.com')` to get a `MailboxResource`. This contract is the hub that dispatches to query builders and single-resource handlers.

```php
<?php

declare(strict_types=1);

namespace App\Mailbox\Drivers\Postmark;

use Pyle\Mailbox\Contracts\FolderQueryBuilder;
use Pyle\Mailbox\Contracts\FolderResource;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\Enums\WellKnownFolder;

class PostmarkMailboxResource implements MailboxResource
{
    public function __construct(
        private readonly PostmarkClient $client,
        private readonly string $emailAddress,
    ) {}

    public function messages(): MessageQueryBuilder
    {
        return new PostmarkMessageQuery($this->client, $this->emailAddress);
    }

    public function message(string $messageId): MessageResource
    {
        return new PostmarkMessageResource($this->client, $this->emailAddress, $messageId);
    }

    public function folders(): FolderQueryBuilder
    {
        return new PostmarkFolderQuery($this->client, $this->emailAddress);
    }

    public function folder(string|WellKnownFolder $folderId): FolderResource
    {
        $resolvedId = $folderId instanceof WellKnownFolder
            ? $this->resolveWellKnownFolder($folderId)
            : $folderId;

        return new PostmarkFolderResource($this->client, $this->emailAddress, $resolvedId);
    }

    private function resolveWellKnownFolder(WellKnownFolder $folder): string
    {
        // Map the enum to your provider's folder identifier.
        return match ($folder) {
            WellKnownFolder::INBOX => 'inbox',
            WellKnownFolder::DRAFTS => 'drafts',
            WellKnownFolder::SENT => 'sent',
            WellKnownFolder::DELETED => 'trash',
            WellKnownFolder::JUNK => 'spam',
            WellKnownFolder::ARCHIVE => 'archive',
            WellKnownFolder::OUTBOX => 'outbox',
        };
    }
}
```

> **Tip** The `WellKnownFolder` enum already includes a `forDriver(string $driver)` helper. If you add your driver name to that enum, you get folder resolution for free. Otherwise, resolve it locally as shown above.

## The Message Query Builder Contract

The message query builder is the most feature-rich contract. It supports filtering, searching, ordering, pagination, and bulk operations. Every chaining method returns `static` so callers can build queries fluently.

```php
<?php

declare(strict_types=1);

namespace App\Mailbox\Drivers\Postmark;

use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\WellKnownFolder;

class PostmarkMessageQuery implements MessageQueryBuilder
{
    private ?string $folder = null;

    /** @var array<array{field: FilterableField|string, operator: mixed, value: mixed}> */
    private array $filters = [];

    private ?string $searchQuery = null;

    /** @var array<string> */
    private array $selectedFields = [];

    private string $orderField = 'receivedAt';

    private string $orderDirection = 'desc';

    private ?int $limit = null;

    private int $pageSize = 50;

    public function __construct(
        private readonly PostmarkClient $client,
        private readonly string $mailbox,
    ) {}

    public function inFolder(string|WellKnownFolder $folder): static
    {
        $this->folder = $folder instanceof WellKnownFolder
            ? $folder->value
            : $folder;

        return $this;
    }

    public function where(FilterableField|string $field, mixed $operator, mixed $value = null): static
    {
        $this->filters[] = [
            'field' => $field,
            'operator' => $value === null ? '=' : $operator,
            'value' => $value ?? $operator,
        ];

        return $this;
    }

    public function search(string $query): static
    {
        $this->searchQuery = $query;

        return $this;
    }

    /** @param array<string> $fields */
    public function select(array $fields): static
    {
        $this->selectedFields = $fields;

        return $this;
    }

    public function orderBy(string $field, string $direction = 'desc'): static
    {
        $this->orderField = $field;
        $this->orderDirection = $direction;

        return $this;
    }

    public function take(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function pageSize(int $size): static
    {
        $this->pageSize = $size;

        return $this;
    }

    /** @return Collection<int, MessageDto> */
    public function get(): Collection
    {
        // Build the provider-specific request from accumulated state.
        $response = $this->client->listMessages(
            mailbox: $this->mailbox,
            folder: $this->folder,
            filters: $this->filters,
            search: $this->searchQuery,
            orderBy: $this->orderField,
            orderDirection: $this->orderDirection,
            limit: $this->limit,
            pageSize: $this->pageSize,
        );

        return collect($response['messages'] ?? [])
            ->map(fn (array $data): MessageDto => $this->mapToDto($data))
            ->values();
    }

    public function count(): int
    {
        return $this->get()->count();
    }

    public function first(): ?MessageDto
    {
        return $this->take(1)->get()->first();
    }

    /** @param array<string> $messageIds */
    public function markAsRead(array $messageIds): void
    {
        $this->client->batchMarkAsRead($this->mailbox, $messageIds);
    }

    /** @param array<string> $messageIds */
    public function markAsUnread(array $messageIds): void
    {
        $this->client->batchMarkAsUnread($this->mailbox, $messageIds);
    }

    /** @param array<string> $messageIds */
    public function moveTo(string|WellKnownFolder $folder, array $messageIds): void
    {
        $destination = $folder instanceof WellKnownFolder
            ? $folder->value
            : $folder;

        $this->client->batchMove($this->mailbox, $messageIds, $destination);
    }

    /** @param array<string, mixed> $data */
    private function mapToDto(array $data): MessageDto
    {
        // See "DTO Mapping" section below.
    }
}
```

> **Note** The `count()` and `first()` methods have sensible defaults: `count()` calls `get()->count()` and `first()` calls `take(1)->get()->first()`. If your provider supports dedicated count or single-message endpoints, override them for better performance.

## The Message Resource Contract

The message resource handles single-message operations: retrieving full metadata, reading the body, toggling read state, moving, copying, deleting, and working with attachments.

```php
<?php

declare(strict_types=1);

namespace App\Mailbox\Drivers\Postmark;

use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\AttachmentResource;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\AttachmentFileDto;
use Pyle\Mailbox\DTOs\BodyDto;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\WellKnownFolder;

class PostmarkMessageResource implements MessageResource
{
    public function __construct(
        private readonly PostmarkClient $client,
        private readonly string $mailbox,
        private readonly string $messageId,
    ) {}

    public function get(): MessageDto
    {
        $payload = $this->client->getMessage($this->mailbox, $this->messageId);

        return MessageDto::fromPostmark($payload);
    }

    public function body(): BodyDto
    {
        $message = $this->get();

        return $message->body ?? new BodyDto('text', '');
    }

    public function markAsRead(): void
    {
        $this->client->patchMessage($this->mailbox, $this->messageId, ['isRead' => true]);
    }

    public function markAsUnread(): void
    {
        $this->client->patchMessage($this->mailbox, $this->messageId, ['isRead' => false]);
    }

    public function moveTo(string|WellKnownFolder $folder): MessageDto
    {
        $destination = $folder instanceof WellKnownFolder ? $folder->value : $folder;
        $payload = $this->client->moveMessage($this->mailbox, $this->messageId, $destination);

        return MessageDto::fromPostmark($payload);
    }

    public function copyTo(string|WellKnownFolder $folder): MessageDto
    {
        $destination = $folder instanceof WellKnownFolder ? $folder->value : $folder;
        $payload = $this->client->copyMessage($this->mailbox, $this->messageId, $destination);

        return MessageDto::fromPostmark($payload);
    }

    public function delete(): void
    {
        $this->client->deleteMessage($this->mailbox, $this->messageId);
    }

    /** @return Collection<int, AttachmentDto> */
    public function attachments(): Collection
    {
        $payload = $this->client->listAttachments($this->mailbox, $this->messageId);

        return collect($payload['attachments'] ?? [])
            ->map(fn (array $data): AttachmentDto => AttachmentDto::fromPostmark($data))
            ->values();
    }

    public function attachment(string $attachmentId): AttachmentResource
    {
        return new PostmarkAttachmentResource(
            $this->client,
            $this->mailbox,
            $this->messageId,
            $attachmentId,
        );
    }

    /** @return Collection<int, AttachmentFileDto> */
    public function downloadAttachments(bool $includeInline = false): Collection
    {
        return $this->attachments()
            ->filter(fn (AttachmentDto $a): bool => $includeInline || ! $a->isInline)
            ->map(fn (AttachmentDto $a): AttachmentFileDto => $this->attachment($a->id)->download())
            ->values();
    }
}
```

The `moveTo()` and `copyTo()` methods must return a `MessageDto` representing the message in its new location. This lets callers chain operations without needing a second lookup.

## The Folder Query Builder Contract

The folder query builder lists, searches, and creates folders.

```php
<?php

declare(strict_types=1);

namespace App\Mailbox\Drivers\Postmark;

use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\FolderQueryBuilder;
use Pyle\Mailbox\DTOs\FolderDto;
use Pyle\Mailbox\Enums\WellKnownFolder;

class PostmarkFolderQuery implements FolderQueryBuilder
{
    public function __construct(
        private readonly PostmarkClient $client,
        private readonly string $mailbox,
    ) {}

    /** @return Collection<int, FolderDto> */
    public function get(): Collection
    {
        $response = $this->client->listFolders($this->mailbox);

        return collect($response['folders'] ?? [])
            ->map(fn (array $data): FolderDto => FolderDto::fromPostmark($data))
            ->values();
    }

    /** @return Collection<int, FolderDto> */
    public function tree(int $maxDepth = 10): Collection
    {
        $flat = $this->get();

        // Build a nested tree from the flat list.
        // Group by parentFolderId, then recursively attach children.
        return $this->buildTree($flat, null, 0, $maxDepth);
    }

    public function find(
        string $name,
        string|WellKnownFolder|null $root = null,
        bool $caseSensitive = true,
    ): ?FolderDto {
        $folders = $this->get();

        return $folders->first(function (FolderDto $folder) use ($name, $caseSensitive): bool {
            return $caseSensitive
                ? $folder->displayName === $name
                : mb_strtolower($folder->displayName) === mb_strtolower($name);
        });
    }

    public function create(string $name, ?string $parentId = null): FolderDto
    {
        $payload = $this->client->createFolder($this->mailbox, $name, $parentId);

        return FolderDto::fromPostmark($payload);
    }

    public function createPath(string $path): FolderDto
    {
        // Walk each segment, creating missing folders along the way.
        $segments = array_filter(explode('/', $path));
        $parentId = null;

        foreach ($segments as $segment) {
            $existing = $this->find($segment);

            if ($existing !== null) {
                $parentId = $existing->id;
                continue;
            }

            $created = $this->create($segment, $parentId);
            $parentId = $created->id;
        }

        return $this->find(end($segments)) ?? throw new \RuntimeException('Failed to create path.');
    }

    /**
     * @param Collection<int, FolderDto> $folders
     * @return Collection<int, FolderDto>
     */
    private function buildTree(Collection $folders, ?string $parentId, int $depth, int $maxDepth): Collection
    {
        if ($depth >= $maxDepth) {
            return collect();
        }

        return $folders
            ->filter(fn (FolderDto $f): bool => $f->parentFolderId === $parentId)
            ->map(fn (FolderDto $f): FolderDto => $f->withChildren(
                $this->buildTree($folders, $f->id, $depth + 1, $maxDepth)->all()
            ))
            ->values();
    }
}
```

### The tree() Method

The `tree()` method returns a nested collection of `FolderDto` objects. Each DTO's `children` array contains its child folders, up to `$maxDepth` levels deep. If your provider returns a flat list, build the tree client-side as shown above. If your provider has a native tree endpoint, use it instead.

## The Folder Resource Contract

A folder resource represents a single folder and supports reading its metadata, listing children, querying messages within it, running delta sync, and performing folder-level operations.

```php
<?php

declare(strict_types=1);

namespace App\Mailbox\Drivers\Postmark;

use Illuminate\Support\Collection;
use Pyle\Mailbox\Contracts\FolderResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\DTOs\DeltaResultDto;
use Pyle\Mailbox\DTOs\FolderDto;

class PostmarkFolderResource implements FolderResource
{
    public function __construct(
        private readonly PostmarkClient $client,
        private readonly string $mailbox,
        private readonly string $folderId,
    ) {}

    public function get(): FolderDto
    {
        $payload = $this->client->getFolder($this->mailbox, $this->folderId);

        return FolderDto::fromPostmark($payload);
    }

    /** @return Collection<int, FolderDto> */
    public function children(): Collection
    {
        $payload = $this->client->listChildFolders($this->mailbox, $this->folderId);

        return collect($payload['folders'] ?? [])
            ->map(fn (array $data): FolderDto => FolderDto::fromPostmark($data))
            ->values();
    }

    public function messages(): MessageQueryBuilder
    {
        return (new PostmarkMessageQuery($this->client, $this->mailbox))
            ->inFolder($this->folderId);
    }

    public function delta(?string $deltaToken = null): DeltaResultDto
    {
        $response = $this->client->delta($this->mailbox, $this->folderId, $deltaToken);

        return new DeltaResultDto(
            created: collect($response['created'] ?? [])
                ->map(fn (array $data): \Pyle\Mailbox\DTOs\MessageDto => \Pyle\Mailbox\DTOs\MessageDto::fromPostmark($data))
                ->values(),
            updated: collect($response['updated'] ?? [])
                ->map(fn (array $data): \Pyle\Mailbox\DTOs\MessageDto => \Pyle\Mailbox\DTOs\MessageDto::fromPostmark($data))
                ->values(),
            deleted: collect($response['deleted'] ?? []),
            deltaLink: $response['deltaLink'] ?? null,
            fullSyncRequired: (bool) ($response['fullSyncRequired'] ?? false),
        );
    }

    public function delete(): void
    {
        $this->client->deleteFolder($this->mailbox, $this->folderId);
    }

    public function moveTo(string $destinationParentId): FolderDto
    {
        $payload = $this->client->moveFolder($this->mailbox, $this->folderId, $destinationParentId);

        return FolderDto::fromPostmark($payload);
    }
}
```

### The delta() Method

The `delta()` method drives incremental sync. It returns a `DeltaResultDto` containing three collections -- `created`, `updated`, and `deleted` -- plus a `deltaLink` token for the next call. If your provider does not support delta queries natively, you can return `fullSyncRequired: true` to signal that callers should fall back to a full listing.

## The Attachment Resource Contract

The attachment resource provides metadata, file download, and raw streaming for a single attachment.

```php
<?php

declare(strict_types=1);

namespace App\Mailbox\Drivers\Postmark;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\Contracts\AttachmentResource;
use Pyle\Mailbox\DTOs\AttachmentDto;
use Pyle\Mailbox\DTOs\AttachmentFileDto;

class PostmarkAttachmentResource implements AttachmentResource
{
    public function __construct(
        private readonly PostmarkClient $client,
        private readonly string $mailbox,
        private readonly string $messageId,
        private readonly string $attachmentId,
    ) {}

    public function metadata(): AttachmentDto
    {
        $payload = $this->client->getAttachmentMetadata(
            $this->mailbox,
            $this->messageId,
            $this->attachmentId,
        );

        return AttachmentDto::fromPostmark($payload);
    }

    public function download(): AttachmentFileDto
    {
        $meta = $this->metadata();
        $content = $this->client->downloadAttachment(
            $this->mailbox,
            $this->messageId,
            $this->attachmentId,
        );

        $disk = config('mailbox.attachment_disk', 'local');
        $path = config('mailbox.attachment_path', 'mailbox-attachments');
        $filePath = sprintf('%s/%s/%s/%s', $path, $this->messageId, $this->attachmentId, $meta->name);

        $storage = \Illuminate\Support\Facades\Storage::disk($disk);
        $alreadyExisted = $storage->exists($filePath);

        if (! $alreadyExisted) {
            $storage->put($filePath, $content);
        }

        return new AttachmentFileDto(
            id: $meta->id,
            name: $meta->name,
            contentType: $meta->contentType,
            size: $meta->size,
            isInline: $meta->isInline,
            contentId: $meta->contentId,
            path: $filePath,
            disk: $disk,
            alreadyExisted: $alreadyExisted,
        );
    }

    public function stream(): StreamInterface
    {
        $content = $this->client->downloadAttachment(
            $this->mailbox,
            $this->messageId,
            $this->attachmentId,
        );

        return Utils::streamFor($content);
    }
}
```

The `download()` method writes the file to the disk and path configured in `mailbox.attachment_disk` and `mailbox.attachment_path`. The `alreadyExisted` flag on `AttachmentFileDto` tells callers whether the file was freshly downloaded or was already present from a previous sync.

## DTO Mapping

Every method that returns data to the caller must produce Mailbox DTOs -- not raw provider arrays. The built-in drivers use static factory methods like `MessageDto::fromMsGraph()` and `MessageDto::fromGmail()`. For your custom driver, add a similar factory method to each DTO.

### Adding a Factory Method to MessageDto

The simplest approach is to add a static `fromPostmark()` method directly on the DTO class. Since the DTOs are `final readonly`, you cannot extend them. Instead, create the instance using the constructor:

```php
use Carbon\CarbonImmutable;
use Pyle\Mailbox\DTOs\BodyDto;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\Importance;

/** @param array<string, mixed> $data */
function mapPostmarkMessage(array $data): MessageDto
{
    return new MessageDto(
        id: (string) ($data['MessageID'] ?? ''),
        subject: (string) ($data['Subject'] ?? ''),
        bodyPreview: isset($data['TextBody']) ? mb_substr($data['TextBody'], 0, 200) : null,
        body: isset($data['HtmlBody'])
            ? new BodyDto('html', $data['HtmlBody'])
            : (isset($data['TextBody']) ? new BodyDto('text', $data['TextBody']) : null),
        from: new EmailAddressDto(
            name: (string) ($data['FromName'] ?? ''),
            address: (string) ($data['From'] ?? ''),
        ),
        sender: new EmailAddressDto(
            name: (string) ($data['FromName'] ?? ''),
            address: (string) ($data['From'] ?? ''),
        ),
        toRecipients: array_map(
            fn (array $r) => new EmailAddressDto(
                name: (string) ($r['Name'] ?? ''),
                address: (string) ($r['Email'] ?? ''),
            ),
            (array) ($data['To'] ?? []),
        ),
        ccRecipients: array_map(
            fn (array $r) => new EmailAddressDto(
                name: (string) ($r['Name'] ?? ''),
                address: (string) ($r['Email'] ?? ''),
            ),
            (array) ($data['Cc'] ?? []),
        ),
        bccRecipients: array_map(
            fn (array $r) => new EmailAddressDto(
                name: (string) ($r['Name'] ?? ''),
                address: (string) ($r['Email'] ?? ''),
            ),
            (array) ($data['Bcc'] ?? []),
        ),
        receivedAt: isset($data['ReceivedAt']) ? CarbonImmutable::parse($data['ReceivedAt']) : null,
        sentAt: isset($data['SentAt']) ? CarbonImmutable::parse($data['SentAt']) : null,
        isRead: (bool) ($data['IsRead'] ?? false),
        isDraft: (bool) ($data['IsDraft'] ?? false),
        hasAttachments: ! empty($data['Attachments']),
        importance: Importance::tryFrom(strtolower((string) ($data['Importance'] ?? 'normal')))
            ?? Importance::NORMAL,
        conversationId: isset($data['ConversationID']) ? (string) $data['ConversationID'] : null,
        internetMessageId: isset($data['MessageID']) ? (string) $data['MessageID'] : null,
        parentFolderId: isset($data['Folder']) ? (string) $data['Folder'] : null,
        raw: $data,
    );
}
```

Follow the same pattern for `FolderDto`, `AttachmentDto`, `BodyDto`, and `EmailAddressDto`. The `raw` field on `MessageDto` is a good place to stash the original provider response for debugging.

### Key DTO Reference

| DTO | Required Fields | Notes |
|---|---|---|
| `MessageDto` | `id`, `subject`, `isRead`, `isDraft`, `hasAttachments`, `importance` | All other fields are nullable |
| `FolderDto` | `id`, `displayName`, `childFolderCount`, `totalItemCount`, `unreadItemCount` | `wellKnownName` maps to `WellKnownFolder` enum |
| `AttachmentDto` | `id`, `name`, `contentType`, `size`, `isInline` | `contentId` is nullable |
| `AttachmentFileDto` | `id`, `name`, `contentType`, `size`, `isInline`, `path`, `disk`, `alreadyExisted` | Returned by `download()` |
| `BodyDto` | `contentType`, `content` | `contentType` is `'html'` or `'text'` |
| `DeltaResultDto` | `created`, `updated`, `deleted`, `deltaLink`, `fullSyncRequired` | Collections of `MessageDto` / strings |
| `EmailAddressDto` | `name`, `address` | Used in `from`, `sender`, `toRecipients`, etc. |

## Registering Your Driver

Mailbox uses Laravel's Manager pattern, so you have two ways to register a custom driver.

### Option A: The extend() Method

The fastest way to wire up a custom driver is the `extend()` method on the Mailbox facade. Place this in a service provider's `boot()` method:

```php
use Pyle\Mailbox\Facades\Mailbox;
use App\Mailbox\Drivers\Postmark\PostmarkDriver;

public function boot(): void
{
    Mailbox::extend('postmark', function ($app) {
        return new PostmarkDriver(
            config('mailbox.drivers.postmark', []),
        );
    });
}
```

### Option B: The driver_classes Config Map

For drivers distributed as packages, add your class to the `driver_classes` array in `config/mailbox.php`. The manager instantiates the class automatically, passing the driver config array to the constructor:

```php
'driver_classes' => [
    'ms-graph' => \Pyle\Mailbox\Drivers\MsGraph\MsGraphDriver::class,
    'gmail'    => \Pyle\Mailbox\Drivers\Gmail\GmailDriver::class,
    'postmark' => \App\Mailbox\Drivers\Postmark\PostmarkDriver::class,
],
```

> **Warning** The `driver_classes` map requires your driver constructor to accept a single `array $config` parameter. If your driver needs additional dependencies, use `Mailbox::extend()` instead.

## Configuration

Add a section for your driver under `mailbox.drivers` in `config/mailbox.php`:

```php
'drivers' => [
    // ... existing drivers ...

    'postmark' => [
        'driver' => 'postmark',
        'server_token' => env('POSTMARK_SERVER_TOKEN'),
        'api_base_uri' => env('POSTMARK_API_BASE_URI', 'https://api.postmarkapp.com'),
        'timeout' => 30,
    ],
],
```

To make your driver the default, set the `MAILBOX_DRIVER` environment variable:

```env
MAILBOX_DRIVER=postmark
```

Or update the config directly:

```php
'default' => env('MAILBOX_DRIVER', 'postmark'),
```

## Testing Your Driver

A custom driver should be tested at two levels: unit tests for DTO mapping and feature tests for end-to-end contract compliance.

### Unit Testing DTO Mapping

Test your mapping functions with known provider payloads:

```php
it('maps a Postmark message to MessageDto', function () {
    $payload = [
        'MessageID' => 'abc-123',
        'Subject' => 'Invoice #1042',
        'From' => 'billing@vendor.com',
        'FromName' => 'Vendor Billing',
        'To' => [['Email' => 'invoices@acme.com', 'Name' => 'Acme Invoices']],
        'Cc' => [],
        'Bcc' => [],
        'ReceivedAt' => '2026-01-15T10:30:00Z',
        'IsRead' => false,
        'IsDraft' => false,
        'Attachments' => [],
    ];

    $dto = mapPostmarkMessage($payload);

    expect($dto->id)->toBe('abc-123');
    expect($dto->subject)->toBe('Invoice #1042');
    expect($dto->from->address)->toBe('billing@vendor.com');
    expect($dto->isRead)->toBeFalse();
});
```

### Feature Testing the Full Driver

Wire your driver into the manager and exercise the full contract surface:

```php
use Pyle\Mailbox\Facades\Mailbox;

beforeEach(function () {
    Mailbox::extend('postmark', fn () => new PostmarkDriver([
        'server_token' => 'test-token',
    ]));

    config(['mailbox.default' => 'postmark']);
});

it('lists messages from the inbox', function () {
    // Arrange: set up your HTTP mock or test double
    // ...

    $messages = Mailbox::mailbox('invoices@acme.com')
        ->messages()
        ->inFolder(\Pyle\Mailbox\Enums\WellKnownFolder::INBOX)
        ->take(10)
        ->get(); // Collection<int, MessageDto>

    expect($messages)->toHaveCount(10);
    expect($messages->first())->toBeInstanceOf(\Pyle\Mailbox\DTOs\MessageDto::class);
});
```

### Validation Checklist

Before shipping your driver, verify these behaviors:

- **Query parity** -- `get()`, `count()`, `first()`, `where()`, `search()`, `inFolder()`, `orderBy()`, `take()`, and `pageSize()` all work correctly.
- **Bulk operations** -- `markAsRead()`, `markAsUnread()`, and `moveTo()` on the query builder handle multiple message IDs.
- **Single-message actions** -- `get()`, `body()`, `markAsRead()`, `markAsUnread()`, `moveTo()`, `copyTo()`, and `delete()` on the message resource.
- **Folder operations** -- `get()`, `tree()`, `find()`, `create()`, `createPath()` on the query builder; `get()`, `children()`, `messages()`, `delta()`, `delete()`, `moveTo()` on the resource.
- **Attachment handling** -- `attachments()`, `attachment()`, `downloadAttachments()`, `metadata()`, `download()`, `stream()`.
- **Delta sync** -- Returns correct `created`, `updated`, `deleted` collections and a usable `deltaLink`.
- **Error mapping** -- Provider errors are caught and translated into meaningful exceptions.
- **Connection & health** -- `testConnection()` and `healthCheck()` report accurate results.

## Complete Skeleton

Here is every class you need in a single listing. Copy this into `app/Mailbox/Drivers/Postmark/` and implement the `PostmarkClient` methods to talk to your provider:

```php
// PostmarkDriver.php
class PostmarkDriver implements \Pyle\Mailbox\Contracts\MailboxDriver
{
    public function __construct(private readonly array $config) {}
    public function mailbox(string $emailAddress): \Pyle\Mailbox\Contracts\MailboxResource { /* ... */ }
    public function testConnection(?string $emailAddress = null): \Pyle\Mailbox\DTOs\ConnectionTestResult { /* ... */ }
    public function healthCheck(): \Pyle\Mailbox\DTOs\HealthCheckResult { /* ... */ }
}

// PostmarkMailboxResource.php
class PostmarkMailboxResource implements \Pyle\Mailbox\Contracts\MailboxResource
{
    public function messages(): \Pyle\Mailbox\Contracts\MessageQueryBuilder { /* ... */ }
    public function message(string $messageId): \Pyle\Mailbox\Contracts\MessageResource { /* ... */ }
    public function folders(): \Pyle\Mailbox\Contracts\FolderQueryBuilder { /* ... */ }
    public function folder(string|\Pyle\Mailbox\Enums\WellKnownFolder $folderId): \Pyle\Mailbox\Contracts\FolderResource { /* ... */ }
}

// PostmarkMessageQuery.php
class PostmarkMessageQuery implements \Pyle\Mailbox\Contracts\MessageQueryBuilder
{
    public function inFolder(string|\Pyle\Mailbox\Enums\WellKnownFolder $folder): static { /* ... */ }
    public function where(\Pyle\Mailbox\Enums\FilterableField|string $field, mixed $operator, mixed $value = null): static { /* ... */ }
    public function search(string $query): static { /* ... */ }
    public function select(array $fields): static { /* ... */ }
    public function orderBy(string $field, string $direction = 'desc'): static { /* ... */ }
    public function take(int $limit): static { /* ... */ }
    public function pageSize(int $size): static { /* ... */ }
    public function get(): \Illuminate\Support\Collection { /* ... */ }
    public function count(): int { /* ... */ }
    public function first(): ?\Pyle\Mailbox\DTOs\MessageDto { /* ... */ }
    public function markAsRead(array $messageIds): void { /* ... */ }
    public function markAsUnread(array $messageIds): void { /* ... */ }
    public function moveTo(string|\Pyle\Mailbox\Enums\WellKnownFolder $folder, array $messageIds): void { /* ... */ }
}

// PostmarkMessageResource.php
class PostmarkMessageResource implements \Pyle\Mailbox\Contracts\MessageResource
{
    public function get(): \Pyle\Mailbox\DTOs\MessageDto { /* ... */ }
    public function body(): \Pyle\Mailbox\DTOs\BodyDto { /* ... */ }
    public function markAsRead(): void { /* ... */ }
    public function markAsUnread(): void { /* ... */ }
    public function moveTo(string|\Pyle\Mailbox\Enums\WellKnownFolder $folder): \Pyle\Mailbox\DTOs\MessageDto { /* ... */ }
    public function copyTo(string|\Pyle\Mailbox\Enums\WellKnownFolder $folder): \Pyle\Mailbox\DTOs\MessageDto { /* ... */ }
    public function delete(): void { /* ... */ }
    public function attachments(): \Illuminate\Support\Collection { /* ... */ }
    public function attachment(string $attachmentId): \Pyle\Mailbox\Contracts\AttachmentResource { /* ... */ }
    public function downloadAttachments(bool $includeInline = false): \Illuminate\Support\Collection { /* ... */ }
}

// PostmarkFolderQuery.php
class PostmarkFolderQuery implements \Pyle\Mailbox\Contracts\FolderQueryBuilder
{
    public function get(): \Illuminate\Support\Collection { /* ... */ }
    public function tree(int $maxDepth = 10): \Illuminate\Support\Collection { /* ... */ }
    public function find(string $name, string|\Pyle\Mailbox\Enums\WellKnownFolder|null $root = null, bool $caseSensitive = true): ?\Pyle\Mailbox\DTOs\FolderDto { /* ... */ }
    public function create(string $name, ?string $parentId = null): \Pyle\Mailbox\DTOs\FolderDto { /* ... */ }
    public function createPath(string $path): \Pyle\Mailbox\DTOs\FolderDto { /* ... */ }
}

// PostmarkFolderResource.php
class PostmarkFolderResource implements \Pyle\Mailbox\Contracts\FolderResource
{
    public function get(): \Pyle\Mailbox\DTOs\FolderDto { /* ... */ }
    public function children(): \Illuminate\Support\Collection { /* ... */ }
    public function messages(): \Pyle\Mailbox\Contracts\MessageQueryBuilder { /* ... */ }
    public function delta(?string $deltaToken = null): \Pyle\Mailbox\DTOs\DeltaResultDto { /* ... */ }
    public function delete(): void { /* ... */ }
    public function moveTo(string $destinationParentId): \Pyle\Mailbox\DTOs\FolderDto { /* ... */ }
}

// PostmarkAttachmentResource.php
class PostmarkAttachmentResource implements \Pyle\Mailbox\Contracts\AttachmentResource
{
    public function metadata(): \Pyle\Mailbox\DTOs\AttachmentDto { /* ... */ }
    public function download(): \Pyle\Mailbox\DTOs\AttachmentFileDto { /* ... */ }
    public function stream(): \Psr\Http\Message\StreamInterface { /* ... */ }
}
```

Seven classes. Each one implements a single contract. Wire them together through your driver constructor, register the driver with `Mailbox::extend()` or the `driver_classes` config map, and you have a fully functional Mailbox driver.

## What's Next

- [Stubs](stubs.md) -- publish ready-made file templates to bootstrap your driver.
- [Testing](../testing.md) -- run the package test suite and use the `MailboxMock` helper.
- [Architecture](../architecture.md) -- understand how drivers fit into the broader Mailbox design.

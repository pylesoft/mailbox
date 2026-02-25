# Stubs

Building a custom Mailbox driver means implementing seven contracts across seven classes. Rather than starting from a blank file and cross-referencing each interface, you can publish a set of ready-made stubs that give you every method signature, the correct import statements, and helpful TODO comments marking exactly where your provider logic goes. They shave hours off the bootstrapping phase and help you avoid missing a method until your tests catch it.

## Publishing Stubs

Run the Artisan publish command to copy the stubs into your project:

```bash
php artisan vendor:publish --tag=mailbox-stubs
```

Mailbox places the stubs in `stubs/mailbox/` at your project root:

```
stubs/
└── mailbox/
    ├── driver.stub
    ├── mailbox-resource.stub
    ├── message-query-builder.stub
    ├── message-resource.stub
    ├── folder-query-builder.stub
    ├── folder-resource.stub
    ├── attachment-resource.stub
    └── dto.stub
```

Each `.stub` file is a valid PHP class with a `{{ class }}` placeholder where your driver name goes.

## What Each Stub Contains

### driver.stub

The entry point for your driver. Implements `MailboxDriver` with skeleton methods for `mailbox()`, `testConnection()`, and `healthCheck()`. The constructor accepts the `array $config` that Mailbox passes from `config('mailbox.drivers.your-driver')`.

```php
class {{ class }}Driver implements MailboxDriver
{
    public function __construct(private readonly array $config) {}

    public function mailbox(string $emailAddress): MailboxResource { /* ... */ }
    public function testConnection(?string $emailAddress = null): ConnectionTestResult { /* ... */ }
    public function healthCheck(): HealthCheckResult { /* ... */ }
}
```

### mailbox-resource.stub

The hub that dispatches to query builders and single-resource handlers. Implements `MailboxResource` with methods for `messages()`, `message()`, `folders()`, and `folder()`.

```php
class {{ class }}MailboxResource implements MailboxResource
{
    public function messages(): MessageQueryBuilder { /* ... */ }
    public function message(string $messageId): MessageResource { /* ... */ }
    public function folders(): FolderQueryBuilder { /* ... */ }
    public function folder(string|WellKnownFolder $folderId): FolderResource { /* ... */ }
}
```

### message-query-builder.stub

The most method-rich stub. Implements `MessageQueryBuilder` with all fluent chaining methods, terminal methods (`get()`, `count()`, `first()`), and bulk operations (`markAsRead()`, `markAsUnread()`, `moveTo()`). The chaining methods already return `$this` -- you just need to store the state they receive.

```php
class {{ class }}MessageQuery implements MessageQueryBuilder
{
    public function inFolder(string|WellKnownFolder $folder): static { return $this; }
    public function where(FilterableField|string $field, mixed $operator, mixed $value = null): static { return $this; }
    public function search(string $query): static { return $this; }
    public function select(array $fields): static { return $this; }
    public function orderBy(string $field, string $direction = 'desc'): static { return $this; }
    public function take(int $limit): static { return $this; }
    public function pageSize(int $size): static { return $this; }
    public function get(): Collection { /* ... */ }
    public function count(): int { return $this->get()->count(); }
    public function first(): ?MessageDto { return $this->get()->first(); }
    public function markAsRead(array $messageIds): void { /* ... */ }
    public function markAsUnread(array $messageIds): void { /* ... */ }
    public function moveTo(string|WellKnownFolder $folder, array $messageIds): void { /* ... */ }
}
```

> **Tip** The `count()` and `first()` methods ship with working defaults that delegate to `get()`. Override them only if your provider offers more efficient endpoints.

### message-resource.stub

Single-message operations. Implements `MessageResource` with methods for metadata retrieval, body reading, read-state toggling, moving, copying, deleting, and attachment access.

```php
class {{ class }}MessageResource implements MessageResource
{
    public function get(): MessageDto { /* ... */ }
    public function body(): BodyDto { /* ... */ }
    public function markAsRead(): void { /* ... */ }
    public function markAsUnread(): void { /* ... */ }
    public function moveTo(string|WellKnownFolder $folder): MessageDto { /* ... */ }
    public function copyTo(string|WellKnownFolder $folder): MessageDto { /* ... */ }
    public function delete(): void { /* ... */ }
    public function attachments(): Collection { /* ... */ }
    public function attachment(string $attachmentId): AttachmentResource { /* ... */ }
    public function downloadAttachments(bool $includeInline = false): Collection { /* ... */ }
}
```

### folder-query-builder.stub

Folder listing, tree building, searching, and creation. Implements `FolderQueryBuilder`.

```php
class {{ class }}FolderQuery implements FolderQueryBuilder
{
    public function get(): Collection { /* ... */ }
    public function tree(int $maxDepth = 10): Collection { /* ... */ }
    public function find(string $name, string|WellKnownFolder|null $root = null, bool $caseSensitive = true): ?FolderDto { /* ... */ }
    public function create(string $name, ?string $parentId = null): FolderDto { /* ... */ }
    public function createPath(string $path): FolderDto { /* ... */ }
}
```

### folder-resource.stub

Single-folder operations including delta sync. Implements `FolderResource`.

```php
class {{ class }}FolderResource implements FolderResource
{
    public function get(): FolderDto { /* ... */ }
    public function children(): Collection { /* ... */ }
    public function messages(): MessageQueryBuilder { /* ... */ }
    public function delta(?string $deltaToken = null): DeltaResultDto { /* ... */ }
    public function delete(): void { /* ... */ }
    public function moveTo(string $destinationParentId): FolderDto { /* ... */ }
}
```

### attachment-resource.stub

Metadata, download, and streaming for a single attachment. Implements `AttachmentResource`.

```php
class {{ class }}AttachmentResource implements AttachmentResource
{
    public function metadata(): AttachmentDto { /* ... */ }
    public function download(): AttachmentFileDto { /* ... */ }
    public function stream(): StreamInterface { /* ... */ }
}
```

### dto.stub

A minimal DTO skeleton if you need to create intermediate data objects for your provider. This is optional -- most drivers map directly to the built-in Mailbox DTOs.

```php
final readonly class {{ class }}Dto implements Arrayable, JsonSerializable
{
    public function __construct(public string $id) {}

    public function toArray(): array { return ['id' => $this->id]; }
    public function jsonSerialize(): array { return $this->toArray(); }
}
```

## Customization Workflow

Stubs are meant to be a starting point, not a straitjacket. Here is the recommended workflow for turning them into a working driver:

### 1. Copy and Rename

Copy the stubs from `stubs/mailbox/` into your application namespace. Replace the `{{ class }}` placeholder with your driver name. For example, to build a Postmark driver:

```bash
mkdir -p app/Mailbox/Drivers/Postmark
```

Then rename each file:

| Stub File | Destination |
|---|---|
| `driver.stub` | `app/Mailbox/Drivers/Postmark/PostmarkDriver.php` |
| `mailbox-resource.stub` | `app/Mailbox/Drivers/Postmark/PostmarkMailboxResource.php` |
| `message-query-builder.stub` | `app/Mailbox/Drivers/Postmark/PostmarkMessageQuery.php` |
| `message-resource.stub` | `app/Mailbox/Drivers/Postmark/PostmarkMessageResource.php` |
| `folder-query-builder.stub` | `app/Mailbox/Drivers/Postmark/PostmarkFolderQuery.php` |
| `folder-resource.stub` | `app/Mailbox/Drivers/Postmark/PostmarkFolderResource.php` |
| `attachment-resource.stub` | `app/Mailbox/Drivers/Postmark/PostmarkAttachmentResource.php` |

Update the namespace in each file from `App\Mailbox\Drivers` to `App\Mailbox\Drivers\Postmark` (or wherever you placed them).

### 2. Implement Incrementally

Start with the driver and mailbox resource -- these are the simplest classes and unblock everything else. Then work outward:

1. **PostmarkDriver** -- get `testConnection()` passing first. This validates your authentication setup.
2. **PostmarkMailboxResource** -- wire up the constructor to create query builders and resources.
3. **PostmarkMessageQuery** -- implement `get()` to list messages, then add `where()` and `search()`.
4. **PostmarkMessageResource** -- implement `get()` and `body()`, then the action methods.
5. **PostmarkFolderQuery** -- implement `get()` and `tree()`.
6. **PostmarkFolderResource** -- implement `get()`, `children()`, `messages()`, and `delta()`.
7. **PostmarkAttachmentResource** -- implement `metadata()`, `download()`, and `stream()`.

> **Note** Each stub method throws a `RuntimeException` with a descriptive message. You can run your test suite at any point during development -- unimplemented methods fail loudly instead of returning unexpected nulls.

### 3. Write Tests Before Registering

Write feature tests against your driver using `Mailbox::extend()` in a `beforeEach` block. Only register the driver in your service provider after the core operations pass:

```php
use Pyle\Mailbox\Facades\Mailbox;

beforeEach(function () {
    Mailbox::extend('postmark', fn () => new PostmarkDriver([
        'server_token' => 'test-token',
    ]));

    config(['mailbox.default' => 'postmark']);
});

it('lists inbox messages', function () {
    $messages = Mailbox::mailbox('invoices@acme.com')
        ->messages()
        ->get(); // Collection<int, MessageDto>

    expect($messages)->each->toBeInstanceOf(\Pyle\Mailbox\DTOs\MessageDto::class);
});
```

### 4. Register the Driver

Once your tests are green, register the driver in a service provider:

```php
use Pyle\Mailbox\Facades\Mailbox;

public function boot(): void
{
    Mailbox::extend('postmark', function ($app) {
        return new \App\Mailbox\Drivers\Postmark\PostmarkDriver(
            config('mailbox.drivers.postmark', []),
        );
    });
}
```

Add the matching config block to `config/mailbox.php` and you are done.

## Re-publishing Stubs

When you upgrade the Mailbox package, new contract methods may have been added. Re-publish the stubs to get updated templates:

```bash
php artisan vendor:publish --tag=mailbox-stubs --force
```

The `--force` flag overwrites existing stubs. Since you should have already copied them into your driver namespace, this is safe -- your working driver code is untouched.

## What's Next

- [Custom Drivers](custom-drivers.md) -- the full guide to implementing every contract.
- [Testing](../testing.md) -- run the package test suite and mock drivers in your application tests.
- [Architecture](../architecture.md) -- understand the Manager, contracts, and driver lifecycle.

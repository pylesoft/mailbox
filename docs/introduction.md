# Introduction

Mailbox gives your Laravel application a single, expressive API for reading and managing email across multiple providers. Instead of scattering provider-specific HTTP calls throughout your codebase, you work with a clean abstraction that feels like any other Laravel service -- swap drivers, not code.

Whether you are building an invoicing pipeline that ingests attachments from a shared inbox, a support desk that routes messages by subject, or a background sync job that tracks folder changes, Mailbox handles the provider plumbing so you can focus on your domain logic.

## A Familiar Pattern

If you have used Laravel's Cache, Queue, or Storage components, you already know how Mailbox works. It follows the same Manager / Driver pattern: a central `MailboxManager` resolves the configured driver, and every driver implements the `MailboxDriver` contract.

```php
use Pyle\Mailbox\Facades\Mailbox;

// Use the default driver (set in config/mailbox.php)
$inbox = Mailbox::mailbox('invoices@acme.com');

// Explicitly choose a driver at runtime
$inbox = Mailbox::driver('gmail')->mailbox('billing@vendor.com');
```

The facade proxies to `Pyle\Mailbox\MailboxManager`, which extends `Illuminate\Support\Manager`. You can register custom drivers, override existing ones, or resolve the manager directly from the container -- the same techniques you already use elsewhere in Laravel.

## What You Can Do

Mailbox covers the full lifecycle of mailbox interaction, from querying messages to downloading attachments and tracking incremental changes.

### Query Messages

Fetch messages with an Eloquent-like query builder. Filter by field, search by keyword, select specific properties, and paginate results -- all without writing raw API calls.

```php
$messages = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->where('isRead', false)
    ->where('receivedDateTime', '>=', now()->subDays(7))
    ->orderBy('receivedDateTime', 'desc')
    ->take(25)
    ->get(); // Collection<int, MessageDto>
```

### Work With Individual Messages

Read the full body, mark messages as read or unread, move them between folders, copy them, or delete them outright.

```php
$message = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId);

$body = $message->body();           // BodyDto
$message->markAsRead();
$message->moveTo('Archive');
```

### Manage Folders

List, create, nest, find, move, and delete folders. Build full folder trees or resolve a nested path in a single call.

```php
$folders = Mailbox::mailbox('invoices@acme.com')
    ->folders()
    ->tree(); // Collection<int, FolderDto>

$folder = Mailbox::mailbox('invoices@acme.com')
    ->folders()
    ->createPath('Clients/Acme/Invoices');
```

### Download Attachments

List attachments on a message, download them all at once, or fetch a single file. Mailbox stores downloads to the disk and path you configure.

```php
$files = Mailbox::mailbox('invoices@acme.com')
    ->message($messageId)
    ->downloadAttachments(); // Collection<int, AttachmentFileDto>
```

### Delta Sync

Track incremental changes to a folder using delta tokens. On the first call you receive the full message set; on subsequent calls you receive only what changed. This is the foundation for efficient background sync jobs.

```php
$result = Mailbox::mailbox('invoices@acme.com')
    ->folder('inbox')
    ->delta($previousToken); // DeltaResultDto
```

### Health Checks and Connection Tests

Verify that your credentials, tokens, and API connectivity are working before you deploy -- or monitor them in production.

```php
$health = Mailbox::healthCheck();          // HealthCheckResult
$test   = Mailbox::testConnection();       // ConnectionTestResult
```

```bash
php artisan mailbox:health --driver=ms-graph
```

## Supported Providers

| Provider | Driver name | Authentication |
|---|---|---|
| Microsoft 365 / Outlook | `ms-graph` | App-level client credentials or delegated user OAuth |
| Gmail / Google Workspace | `gmail` | Service account with domain-wide delegation or delegated user OAuth |
| Google Workspace (alias) | `google-workspace` | Same as `gmail`; merges both config sections |

> **Tip** You can register your own drivers for any provider. See [Extending Mailbox](extending/custom-drivers.md) for details.

## Documentation Map

### Getting Started

- [Installation](installation.md) -- requirements, Composer install, publishing config and migrations
- [Configuration](configuration.md) -- driver settings, cache, retry, attachment storage
- [Quickstart](quickstart.md) -- a working example in under five minutes

### Authentication

- [Microsoft Graph Setup](authentication/ms-graph.md) -- Azure app registration and client credentials
- [Gmail Setup](authentication/gmail.md) -- Google service account and domain-wide delegation
- [User OAuth](authentication/user-oauth.md) -- delegated OAuth flow for end-user mailboxes

### Usage

- [Messages](usage/messages.md) -- querying, reading, moving, and deleting messages
- [Folders](usage/folders.md) -- listing, creating, nesting, and syncing folders
- [Attachments](usage/attachments.md) -- downloading and managing file attachments
- [Delta Sync](usage/delta-sync.md) -- incremental sync with delta tokens
- [Mailboxes](usage/mailboxes.md) -- working with `MonitoredMailbox` models
- [Connections](usage/connections.md) -- managing `MailboxConnection` models

### Operations

- [Events](events.md) -- connection tests, secret warnings, and custom listeners
- [Logging](logging.md) -- dedicated log channel and level configuration
- [Models and Traits](models-and-traits.md) -- Eloquent models shipped with the package
- [Rule Matching](rule-matching.md) -- classify messages by subject, sender, or custom rules

### Advanced

- [Extending Mailbox](extending/custom-drivers.md) -- build and register custom drivers
- [Stubs](extending/stubs.md) -- publish and customize package stubs
- [Testing](testing.md) -- faking the Mailbox facade and writing assertions
- [Troubleshooting](troubleshooting.md) -- common errors and how to resolve them
- [Migration Guide](migration-guide.md) -- upgrading from earlier versions

## Getting Help

If you discover a bug or have a feature request, please open an issue on the [GitHub repository](https://github.com/pylesoft/mailbox). When reporting an issue, include the output of `php artisan mailbox:health` and your `composer show pylesoft/mailbox` version.

## What's Next

- [Installation](installation.md) -- get Mailbox installed and verified in under two minutes.
- [Configuration](configuration.md) -- understand every option in `config/mailbox.php`.
- [Quickstart](quickstart.md) -- fetch your first messages with a working example.

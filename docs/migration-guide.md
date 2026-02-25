# Migration Guide

If you have been working with Microsoft Graph or Gmail APIs directly -- building your own HTTP clients, managing tokens, and parsing raw responses -- Mailbox gives you a clean abstraction that handles all of that for you. This guide walks you through replacing direct provider code with Mailbox's unified API, mapping your existing patterns to their package equivalents, and planning a smooth cutover with minimal disruption to your running application.

## API Mapping

The table below shows common direct-provider patterns and their Mailbox equivalents. In every case, the package method returns a strongly-typed DTO instead of a raw array.

| Legacy Pattern | Mailbox Equivalent |
| --- | --- |
| Find a folder by name | `Mailbox::mailbox($addr)->folders()->find($name)` |
| List messages in a folder | `Mailbox::mailbox($addr)->messages()->inFolder($id)->get()` |
| Get a single message | `Mailbox::mailbox($addr)->message($id)->get()` |
| Download all attachments | `Mailbox::mailbox($addr)->message($id)->downloadAttachments()` |
| Move a message to a folder | `Mailbox::mailbox($addr)->message($id)->moveTo($folderId)` |
| Mark a message as read | `Mailbox::mailbox($addr)->message($id)->markAsRead()` |
| Test provider connectivity | `Mailbox::testConnection('invoices@acme.com')` |
| Run a delta (incremental) sync | `Mailbox::forFolder($folder)->delta($token)` |

### Before and After

Here is what a typical "fetch inbox messages" workflow looks like before and after adopting Mailbox.

**Before** -- direct Microsoft Graph call:

```php
use GuzzleHttp\Client;

$token = $this->acquireToken(); // Your custom token logic
$client = new Client(['base_uri' => 'https://graph.microsoft.com/v1.0/']);

$response = $client->get("users/invoices@acme.com/mailFolders/Inbox/messages", [
    'headers' => ['Authorization' => "Bearer {$token}"],
    'query' => ['$top' => 50, '$select' => 'id,subject,from,receivedDateTime'],
]);

$messages = json_decode($response->getBody()->getContents(), true)['value'];
```

**After** -- with Mailbox:

```php
use Pyle\Mailbox\Facades\Mailbox;

$messages = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->inFolder('Inbox')
    ->take(50)
    ->get(); // Collection<int, MessageDto>
```

Mailbox handles token acquisition, caching, retries, rate limiting, and response mapping automatically. The `$messages` collection contains `MessageDto` objects with typed properties like `$message->subject`, `$message->from->address`, and `$message->receivedAt`.

## Data Migration Notes

If your existing application stores mailbox-related data, you will want to map it to the Mailbox package's database schema.

### Connection Records

Mailbox stores provider credentials and connection metadata in the `mailbox_connections` table. If you have existing credential records, map them:

```php
use Pyle\Mailbox\Models\MailboxConnection;

MailboxConnection::create([
    'driver' => 'ms-graph',
    'name' => 'Acme Production',
    'is_active' => true,
]);
```

### Monitored Mailboxes

Each email address you want to sync maps to a `monitored_mailboxes` record:

```php
use Pyle\Mailbox\Models\MonitoredMailbox;

MonitoredMailbox::create([
    'connection_id' => $connection->id,
    'email_address' => 'invoices@acme.com',
    'is_active' => true,
]);
```

### Sync State

If you have been tracking delta tokens or sync cursors, persist them in the `monitored_folders` table:

```php
use Pyle\Mailbox\Models\MonitoredFolder;

MonitoredFolder::create([
    'monitored_mailbox_id' => $mailbox->id,
    'folder_id' => 'AAMkAG...',
    'display_name' => 'Inbox',
    'delta_token' => $yourExistingDeltaToken,
    'is_active' => true,
]);
```

> **Tip** If your legacy delta tokens are still valid, they will continue to work with Mailbox. There is no need to re-sync from scratch.

## Suggested Cutover Plan

A phased rollout reduces risk and gives you time to validate each step before moving on.

### Phase 1 -- Deploy and Configure

Install the package and run migrations alongside your existing code. Both systems can coexist safely.

```bash
composer require pylesoft/mailbox
php artisan vendor:publish --tag=mailbox-config
php artisan vendor:publish --tag=mailbox-migrations
php artisan migrate
```

### Phase 2 -- Verify Connectivity

Run connection tests against every mailbox your application accesses:

```bash
php artisan mailbox:test-connection --email=invoices@acme.com
```

Address any authentication or permission issues before proceeding. See [Troubleshooting](troubleshooting.md) for common issues.

### Phase 3 -- Backfill Records

Create `MailboxConnection`, `MonitoredMailbox`, and `MonitoredFolder` records for each mailbox and folder you are tracking. If you have existing delta tokens, preserve them.

### Phase 4 -- Switch Ingestion

Update your ingestion jobs and commands to use the Mailbox API instead of direct provider calls. This is where the [API Mapping](#api-mapping) table above is most useful.

### Phase 5 -- Validate and Clean Up

Run both systems in parallel for a period if possible, comparing results. Once you are confident in parity:

1. Remove your legacy provider code and HTTP client wrappers.
2. Remove any custom token management logic.
3. Remove unused environment variables.

> **Note** There is no deadline for removing legacy code. The Mailbox package does not conflict with direct provider calls, so you can take your time with the transition.

## What's Next

- [Testing](testing.md) -- patterns for testing code that uses Mailbox
- [Troubleshooting](troubleshooting.md) -- solutions for common provider issues
- [Configuration](configuration.md) -- full reference for all configuration options

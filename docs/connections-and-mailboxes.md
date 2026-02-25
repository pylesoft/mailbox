# Connections and Mailboxes

Every interaction with a mail provider begins with a connection. Mailbox uses a three-level Eloquent hierarchy -- connection, mailbox, folder -- to organize your monitored email accounts, and provides a clean bridge from those database records into the runtime API layer. This page walks you through creating connections, registering mailboxes and folders, resolving live API resources, and setting up multi-tenant architectures.

## Creating a Connection

A `MailboxConnection` record tells Mailbox which driver to use and (optionally) stores driver-specific configuration. Start by creating one:

```php
use Pyle\Mailbox\Models\MailboxConnection;
use Pyle\Mailbox\Enums\ConnectionStatus;

$connection = MailboxConnection::create([
    'name'   => 'Acme Microsoft 365',
    'driver' => 'ms-graph',
    'status' => ConnectionStatus::PENDING,
    'config' => [
        'tenant_id'     => '00000000-0000-0000-0000-000000000000',
        'client_id'     => '...',
        'client_secret' => '...',
    ],
]);
```

The `config` column is cast to `encrypted:array`, so any credentials you store there are encrypted at rest. The `driver` value must match a key in your `config/mailbox.php` drivers array -- typically `ms-graph` or `gmail`.

> **Tip** You can leave `config` as `null` if your driver reads credentials from environment variables or the config file. The model-level config is useful when different connections use different tenants or service accounts.

### Connection Status Lifecycle

Connections progress through a simple state machine:

```
pending  →  connected
    ↓           ↓
  error  ←  disabled
```

| Status | When to Set |
|---|---|
| `pending` | Connection created, not yet verified |
| `connected` | After a successful `testConnection()` or first sync |
| `error` | After a failed connection attempt (store details in `last_error`) |
| `disabled` | Manually disabled by your application |

```php
$connection->update([
    'status'            => ConnectionStatus::CONNECTED,
    'last_connected_at' => now(),
    'last_error'        => null,
]);
```

## Registering Mailboxes

Once you have a connection, register the email addresses you want to monitor:

```php
use Pyle\Mailbox\Models\MonitoredMailbox;

$mailbox = MonitoredMailbox::create([
    'mailbox_connection_id' => $connection->id,
    'email_address'         => 'invoices@acme.com',
    'display_name'          => 'Acme Invoices',
]);
```

A single connection can host multiple mailboxes. For example, a Microsoft 365 tenant might monitor both `invoices@acme.com` and `support@acme.com`:

```php
$connection->mailboxes()->createMany([
    ['email_address' => 'invoices@acme.com', 'display_name' => 'Invoices'],
    ['email_address' => 'support@acme.com',  'display_name' => 'Support'],
]);
```

A unique composite index on `(mailbox_connection_id, email_address)` ensures you cannot accidentally register the same address twice under one connection.

### Activating and Deactivating

You can toggle monitoring on and off without deleting the record:

```php
// Pause syncing
$mailbox->update(['is_active' => false]);

// Resume syncing
$mailbox->update(['is_active' => true]);
```

Use the `active()` scope when querying for mailboxes that should be synced:

```php
$activeMailboxes = MonitoredMailbox::active()->get();
```

## Registering Folders

Each mailbox can track one or more folders. Register them with the provider's folder ID:

```php
use Pyle\Mailbox\Models\MonitoredFolder;
use Pyle\Mailbox\Enums\WellKnownFolder;

$folder = MonitoredFolder::create([
    'monitored_mailbox_id' => $mailbox->id,
    'folder_id'            => 'AAMkAGI2TG93AAA=',
    'display_name'         => 'Inbox',
    'well_known_name'      => WellKnownFolder::INBOX,
]);
```

> **Tip** You can discover folder IDs at runtime using the API layer. See [Folders](folders.md) for details on listing and creating folders through the provider API.

### Auto-Discovering Folders

A common pattern is to discover folders from the provider and register them in one pass:

```php
use Pyle\Mailbox\Facades\Mailbox;

$resource = Mailbox::forMailbox($mailbox);
$providerFolders = $resource->folders()->get(); // Collection<int, FolderDto>

foreach ($providerFolders as $dto) {
    MonitoredFolder::updateOrCreate(
        [
            'monitored_mailbox_id' => $mailbox->id,
            'folder_id'            => $dto->id,
        ],
        [
            'display_name'    => $dto->displayName,
            'well_known_name' => $dto->wellKnownName,
        ],
    );
}
```

## The Eloquent-to-API Bridge

Mailbox provides two facade methods that bridge from Eloquent models to live API resources. This is the primary way to go from "a database record" to "make API calls against this mailbox."

### Mailbox::forMailbox()

Pass a `MonitoredMailbox` model and receive a `MailboxResource` -- the entry point for messages, folders, and everything else:

```php
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Models\MonitoredMailbox;

$mailbox = MonitoredMailbox::with('connection')->find(1);

$resource = Mailbox::forMailbox($mailbox); // MailboxResource
```

From there, you have full access to the API:

```php
// List recent messages
$messages = $resource->messages()
    ->inFolder('inbox')
    ->orderBy('receivedDateTime', 'desc')
    ->take(25)
    ->get(); // Collection<int, MessageDto>

// Get a specific message
$message = $resource->message('AAMkAGI2...');

// Access a folder
$folder = $resource->folder(WellKnownFolder::INBOX); // FolderResource

// List all folders
$folders = $resource->folders()->get(); // Collection<int, FolderDto>
```

> **Warning** The `forMailbox()` method reads the `connection` relationship from the model. If the relationship is not loaded, Mailbox throws a `RuntimeException`. Always eager-load the connection or call `$mailbox->load('connection')` before bridging.

### Mailbox::forFolder()

When you already have a `MonitoredFolder` record and want to jump straight to folder-level operations:

```php
use Pyle\Mailbox\Models\MonitoredFolder;

$folder = MonitoredFolder::with('mailbox.connection')->find(1);

$folderResource = Mailbox::forFolder($folder); // FolderResource

// Run a delta sync
$delta = $folderResource->delta($folder->delta_token);

// List messages in this folder
$messages = $folderResource->messages()->get(); // Collection<int, MessageDto>
```

Under the hood, `forFolder()` resolves the mailbox from the folder, calls `forMailbox()`, then narrows to the specific folder using `folder_id`.

## Direct Access Without Eloquent

You do not need Eloquent models to use the API layer. If you prefer a simpler setup or are working in a context where database records are not appropriate, you can access providers directly through the facade.

### Using the Default Driver

The `mailbox()` method on the facade takes an email address and returns a `MailboxResource` using the default driver:

```php
use Pyle\Mailbox\Facades\Mailbox;

$resource = Mailbox::mailbox('invoices@acme.com'); // MailboxResource

$messages = $resource->messages()->get(); // Collection<int, MessageDto>
```

The default driver is set in your `config/mailbox.php` file:

```php
// config/mailbox.php
'default' => env('MAILBOX_DRIVER', 'ms-graph'),
```

### Switching Drivers

To explicitly select a driver, call `driver()` first:

```php
$msGraph = Mailbox::driver('ms-graph')->mailbox('invoices@acme.com');
$gmail   = Mailbox::driver('gmail')->mailbox('billing@vendor.com');
```

### Testing the Connection

Verify that a driver can reach the provider:

```php
$result = Mailbox::testConnection('invoices@acme.com'); // ConnectionTestResult

if ($result->success) {
    // Connection is healthy
}
```

Or check overall driver health without specifying a mailbox:

```php
$health = Mailbox::healthCheck(); // HealthCheckResult
```

## Multi-Tenant Setup

In a multi-tenant application, each tenant typically has its own provider credentials. Mailbox supports this through per-connection configuration and the Eloquent bridge.

### One Connection Per Tenant

The recommended pattern is to create a `MailboxConnection` for each tenant, storing tenant-specific credentials in the encrypted `config` column:

```php
use Pyle\Mailbox\Models\MailboxConnection;

// Tenant A: Microsoft 365
$tenantA = MailboxConnection::create([
    'name'   => 'Tenant A - Microsoft 365',
    'driver' => 'ms-graph',
    'config' => [
        'tenant_id'     => $tenantA->ms_tenant_id,
        'client_id'     => $tenantA->ms_client_id,
        'client_secret' => $tenantA->ms_client_secret,
    ],
]);

// Tenant B: Google Workspace
$tenantB = MailboxConnection::create([
    'name'   => 'Tenant B - Google Workspace',
    'driver' => 'gmail',
    'config' => [
        'service_account_json' => $tenantB->google_service_account,
        'subject_email'        => 'admin@tenantb.com',
    ],
]);
```

### Scoping by Tenant

Add a `tenant_id` column to the `mailbox_connections` table (or use your existing tenant scoping strategy) and query accordingly:

```php
$connections = MailboxConnection::where('tenant_id', $currentTenant->id)
    ->active()
    ->with('mailboxes')
    ->get();
```

### Resolving at Runtime

When processing work for a specific tenant, load their connection and bridge into the API layer:

```php
$mailbox = MonitoredMailbox::with('connection')
    ->whereHas('connection', fn ($q) => $q->where('tenant_id', $tenant->id))
    ->forEmail('invoices@acme.com')
    ->firstOrFail();

$resource = Mailbox::forMailbox($mailbox);

$messages = $resource->messages()
    ->inFolder('inbox')
    ->get(); // Collection<int, MessageDto>
```

### Using the HasMailbox Trait

If your tenant model has a `monitored_mailbox_id` column, the `HasMailbox` trait makes this even cleaner:

```php
use Illuminate\Database\Eloquent\Model;
use Pyle\Mailbox\Traits\HasMailbox;

class TenantEmailAccount extends Model
{
    use HasMailbox;
}

// Resolve the API resource directly from the tenant's model
$resource = TenantEmailAccount::find($id)->mailboxResource();
```

See [Eloquent Models](eloquent-models.md) for a full reference on the `HasMailbox` trait.

### Mixed Provider Environments

Because the driver is stored per connection, a single application can monitor mailboxes across multiple providers simultaneously. One tenant might use Microsoft 365 while another uses Google Workspace -- the Eloquent bridge resolves the correct driver automatically:

```php
// Both resolve to the correct driver based on their connection
$msResource    = Mailbox::forMailbox($msGraphMailbox);    // Uses ms-graph driver
$gmailResource = Mailbox::forMailbox($gmailMailbox);      // Uses gmail driver
```

## What's Next

- [Eloquent Models](eloquent-models.md) -- field reference, casts, relationships, and scopes for all four models
- [Configuration](configuration.md) -- driver settings, OAuth, caching, and environment variables
- [Messages](messages.md) -- querying, filtering, and interacting with messages through the API layer

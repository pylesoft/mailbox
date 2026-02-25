# Eloquent Models

Mailbox provides four Eloquent models that persist your provider connections, monitored email addresses, folder sync state, and OAuth tokens. These models form the bridge between your application's database and the runtime API layer, letting you query mailbox state with familiar Eloquent patterns while Mailbox handles the provider communication behind the scenes.

## Entity Relationship Diagram

The following diagram shows how the four tables relate to one another:

```
┌──────────────────────┐       ┌───────────────────────┐
│  mailbox_connections │       │  mailbox_oauth_tokens  │
├──────────────────────┤       ├───────────────────────┤
│ id                   │───┐   │ id                    │
│ name                 │   │   │ mailbox_connection_id  │──┐
│ driver               │   │   │ provider               │  │
│ status               │   │   │ external_user_id       │  │
│ config (encrypted)   │   │   │ email                  │  │
│ last_connected_at    │   │   │ tenant_id              │  │
│ last_error           │   │   │ access_token (enc.)    │  │
│ timestamps           │   │   │ refresh_token (enc.)   │  │
│ soft_deletes         │   │   │ token_type             │  │
└──────────────────────┘   │   │ scopes                 │  │
         │                 │   │ expires_at             │  │
         │ hasMany         │   │ last_refreshed_at      │  │
         ▼                 │   │ revoked_at             │  │
┌──────────────────────┐   │   │ meta                   │  │
│  monitored_mailboxes │   │   │ timestamps             │  │
├──────────────────────┤   │   └───────────────────────┘  │
│ id                   │   │              ▲                │
│ mailbox_connection_id│───┘              │ belongsTo      │
│ email_address        │                  └────────────────┘
│ display_name         │
│ is_active            │
│ last_synced_at       │
│ timestamps           │
│ soft_deletes         │
└──────────────────────┘
         │
         │ hasMany
         ▼
┌──────────────────────┐
│  monitored_folders   │
├──────────────────────┤
│ id                   │
│ monitored_mailbox_id │
│ folder_id            │
│ display_name         │
│ path                 │
│ well_known_name      │
│ is_active            │
│ delta_token          │
│ last_synced_at       │
│ sync_status          │
│ last_sync_error      │
│ timestamps           │
└──────────────────────┘
```

## MailboxConnection

The `MailboxConnection` model represents a configured provider connection -- the top-level entity that holds driver credentials and health state. Every monitored mailbox and OAuth token belongs to a connection.

### Database Fields

| Column | Type | Default | Description |
|---|---|---|---|
| `id` | `bigint` | auto | Primary key |
| `name` | `string` | -- | Human-readable label (e.g. "Production MS Graph") |
| `driver` | `string` | -- | Driver identifier (`ms-graph`, `gmail`) |
| `status` | `string` | `pending` | Connection health state |
| `config` | `json` (nullable) | `null` | Driver-specific configuration |
| `last_connected_at` | `timestamp` (nullable) | `null` | Last successful connection time |
| `last_error` | `text` (nullable) | `null` | Most recent error message |
| `created_at` | `timestamp` | auto | |
| `updated_at` | `timestamp` | auto | |
| `deleted_at` | `timestamp` (nullable) | `null` | Soft delete timestamp |

### Casts

```php
protected $casts = [
    'status'            => ConnectionStatus::class,  // pending, connected, error, disabled
    'config'            => 'encrypted:array',
    'last_connected_at' => 'immutable_datetime',
];
```

The `config` column uses Laravel's `encrypted:array` cast, so credentials are encrypted at rest and automatically decrypted when accessed.

### Relationships

```php
use Pyle\Mailbox\Models\MailboxConnection;

$connection = MailboxConnection::find(1);

$connection->mailboxes;      // HasMany → MonitoredMailbox (all)
$connection->activeMailboxes; // HasMany → MonitoredMailbox (where is_active = true)
$connection->oauthTokens;    // HasMany → MailboxOAuthToken
```

### Scopes

```php
use Pyle\Mailbox\Models\MailboxConnection;
use Illuminate\Support\Carbon;

// All connections with status "connected"
MailboxConnection::active()->get();

// Filter by driver
MailboxConnection::forDriver('ms-graph')->get();

// Connections in an error state
MailboxConnection::withError()->get();

// Connections used within the last hour
MailboxConnection::connectedSince(Carbon::now()->subHour())->get();
```

### Resolving a Driver at Runtime

You can resolve the `MailboxDriver` instance directly from a connection model:

```php
$driver = $connection->resolveDriver(); // MailboxDriver
```

## MonitoredMailbox

A `MonitoredMailbox` ties a single email address to a connection. You can monitor multiple addresses under the same connection -- for example, `invoices@acme.com` and `support@acme.com` both using the same Microsoft 365 tenant.

### Database Fields

| Column | Type | Default | Description |
|---|---|---|---|
| `id` | `bigint` | auto | Primary key |
| `mailbox_connection_id` | `bigint` (FK) | -- | References `mailbox_connections.id` (cascade delete) |
| `email_address` | `string` | -- | The monitored email address |
| `display_name` | `string` (nullable) | `null` | Friendly label |
| `is_active` | `boolean` | `true` | Whether syncing is enabled |
| `last_synced_at` | `timestamp` (nullable) | `null` | Last successful sync time |
| `created_at` | `timestamp` | auto | |
| `updated_at` | `timestamp` | auto | |
| `deleted_at` | `timestamp` (nullable) | `null` | Soft delete timestamp |

A unique composite index on `(mailbox_connection_id, email_address)` prevents duplicate registrations.

### Casts

```php
protected $casts = [
    'is_active'      => 'boolean',
    'last_synced_at' => 'immutable_datetime',
];
```

### Relationships

```php
use Pyle\Mailbox\Models\MonitoredMailbox;

$mailbox = MonitoredMailbox::find(1);

$mailbox->connection;    // BelongsTo → MailboxConnection
$mailbox->folders;       // HasMany → MonitoredFolder (all)
$mailbox->activeFolders; // HasMany → MonitoredFolder (where is_active = true)
```

### Scopes

```php
use Pyle\Mailbox\Models\MonitoredMailbox;

// Only active mailboxes
MonitoredMailbox::active()->get();

// Find by email address
MonitoredMailbox::forEmail('invoices@acme.com')->first();

// Mailboxes not synced in the last 30 minutes
MonitoredMailbox::stale(30)->get();

// Mailboxes that have never been synced
MonitoredMailbox::neverSynced()->get();
```

The `stale()` scope accepts a `$minutes` parameter (default `30`). It matches mailboxes where `last_synced_at` is null or older than the threshold -- useful for building a sync scheduler.

## MonitoredFolder

Each `MonitoredFolder` represents a single folder (or label, in Gmail terms) being tracked within a mailbox. This is where delta sync tokens and per-folder sync state live.

### Database Fields

| Column | Type | Default | Description |
|---|---|---|---|
| `id` | `bigint` | auto | Primary key |
| `monitored_mailbox_id` | `bigint` (FK) | -- | References `monitored_mailboxes.id` (cascade delete) |
| `folder_id` | `string` | -- | Provider-specific folder identifier |
| `display_name` | `string` | -- | Human-readable folder name |
| `path` | `string` (nullable) | `null` | Full folder path (e.g. `Clients/Acme`) |
| `well_known_name` | `string` (nullable) | `null` | Well-known folder type |
| `is_active` | `boolean` | `true` | Whether syncing is enabled for this folder |
| `delta_token` | `text` (nullable) | `null` | Opaque token for incremental sync |
| `last_synced_at` | `timestamp` (nullable) | `null` | Last successful sync time |
| `sync_status` | `string` | `idle` | Current sync state |
| `last_sync_error` | `text` (nullable) | `null` | Most recent sync error |
| `created_at` | `timestamp` | auto | |
| `updated_at` | `timestamp` | auto | |

A unique composite index on `(monitored_mailbox_id, folder_id)` prevents duplicate folder registrations within the same mailbox.

### Casts

```php
protected $casts = [
    'well_known_name' => WellKnownFolder::class,  // inbox, drafts, sent, deleted, junk, archive, outbox
    'sync_status'     => SyncStatus::class,        // idle, syncing, error
    'is_active'       => 'boolean',
    'last_synced_at'  => 'immutable_datetime',
];
```

### Relationships

```php
use Pyle\Mailbox\Models\MonitoredFolder;

$folder = MonitoredFolder::find(1);

$folder->mailbox; // BelongsTo → MonitoredMailbox
```

### Scopes

```php
use Pyle\Mailbox\Models\MonitoredFolder;
use Pyle\Mailbox\Enums\WellKnownFolder;

// Only active folders
MonitoredFolder::active()->get();

// Currently syncing
MonitoredFolder::syncing()->get();

// Folders in an error state
MonitoredFolder::withErrors()->get();

// Folders that need a sync (active, not synced in the last 15 minutes)
MonitoredFolder::needsSync(15)->get();

// Find the inbox folder
MonitoredFolder::forWellKnown(WellKnownFolder::INBOX)->first();
```

The `needsSync()` scope combines the `is_active` check with a staleness threshold (default `15` minutes). It matches folders that are active and either have never been synced or were last synced before the threshold.

## MailboxOAuthToken

The `MailboxOAuthToken` model stores OAuth credentials for providers that use user-delegated authentication. Tokens are encrypted at rest and optionally linked to a connection.

### Database Fields

| Column | Type | Default | Description |
|---|---|---|---|
| `id` | `bigint` | auto | Primary key |
| `mailbox_connection_id` | `bigint` (nullable, FK) | `null` | References `mailbox_connections.id` (null on delete) |
| `provider` | `string` | -- | Provider identifier (`ms-graph`, `gmail`) |
| `external_user_id` | `string` (nullable) | `null` | Provider's user ID (e.g. Azure AD object ID) |
| `email` | `string` (nullable) | `null` | Email address associated with the token |
| `tenant_id` | `string` (nullable) | `null` | Tenant identifier (for multi-tenant setups) |
| `access_token` | `text` | -- | Encrypted access token |
| `refresh_token` | `text` (nullable) | `null` | Encrypted refresh token |
| `token_type` | `string` | `Bearer` | Token type |
| `scopes` | `json` (nullable) | `null` | Granted OAuth scopes |
| `expires_at` | `timestamp` (nullable) | `null` | Token expiration time |
| `last_refreshed_at` | `timestamp` (nullable) | `null` | Last token refresh time |
| `revoked_at` | `timestamp` (nullable) | `null` | Revocation timestamp |
| `meta` | `json` (nullable) | `null` | Arbitrary metadata |
| `created_at` | `timestamp` | auto | |
| `updated_at` | `timestamp` | auto | |

The table has composite indexes on `(provider, external_user_id)`, `(provider, email)`, and `(provider, revoked_at)` for efficient lookups.

### Casts

```php
protected $casts = [
    'access_token'      => 'encrypted',
    'refresh_token'     => 'encrypted',
    'scopes'            => 'array',
    'meta'              => 'array',
    'expires_at'        => 'immutable_datetime',
    'last_refreshed_at' => 'immutable_datetime',
    'revoked_at'        => 'immutable_datetime',
];
```

Both `access_token` and `refresh_token` use Laravel's `encrypted` cast, ensuring sensitive credentials never appear as plaintext in your database.

### Relationships

```php
use Pyle\Mailbox\Models\MailboxOAuthToken;

$token = MailboxOAuthToken::find(1);

$token->connection; // BelongsTo → MailboxConnection (nullable)
```

### Scopes

```php
use Pyle\Mailbox\Models\MailboxOAuthToken;

// Filter by provider
MailboxOAuthToken::provider('ms-graph')->get();

// Only non-revoked tokens
MailboxOAuthToken::active()->get();

// Tokens expiring within the next 5 minutes (300 seconds)
MailboxOAuthToken::expiringSoon(300)->get();
```

The `expiringSoon()` scope accepts a `$seconds` parameter (default `300`). It matches tokens that have an `expires_at` value within that window -- useful for proactive token refresh jobs.

## Enum Reference

Several model fields are cast to PHP enums. Here is the complete reference for each:

### ConnectionStatus

| Case | Value | Description |
|---|---|---|
| `PENDING` | `pending` | Connection created but not yet verified |
| `CONNECTED` | `connected` | Last connection attempt succeeded |
| `ERROR` | `error` | Last connection attempt failed |
| `DISABLED` | `disabled` | Manually disabled by the application |

### SyncStatus

| Case | Value | Description |
|---|---|---|
| `IDLE` | `idle` | No sync in progress |
| `SYNCING` | `syncing` | Sync currently running |
| `ERROR` | `error` | Last sync failed |

### WellKnownFolder

| Case | Value | MS Graph | Gmail |
|---|---|---|---|
| `INBOX` | `inbox` | `Inbox` | `INBOX` |
| `DRAFTS` | `drafts` | `Drafts` | `DRAFT` |
| `SENT` | `sent` | `SentItems` | `SENT` |
| `DELETED` | `deleted` | `DeletedItems` | `TRASH` |
| `JUNK` | `junk` | `JunkEmail` | `SPAM` |
| `ARCHIVE` | `archive` | `Archive` | `ALL_MAIL` |
| `OUTBOX` | `outbox` | `Outbox` | `OUTBOX` |

## The HasMailbox Trait

The `HasMailbox` trait is designed for your own application models -- any model that stores a `monitored_mailbox_id` foreign key. It provides relationships back into the Mailbox model layer and convenient scopes for querying by mailbox or connection.

### Adding the Trait

```php
use Illuminate\Database\Eloquent\Model;
use Pyle\Mailbox\Traits\HasMailbox;

class EmailInbox extends Model
{
    use HasMailbox;

    protected $fillable = [
        'monitored_mailbox_id',
        'name',
        // ...
    ];
}
```

Your model's table must have a `monitored_mailbox_id` column:

```php
Schema::table('email_inboxes', function (Blueprint $table) {
    $table->foreignId('monitored_mailbox_id')
        ->constrained('monitored_mailboxes')
        ->cascadeOnDelete();
});
```

### Relationships Provided

The trait adds two relationships to your model:

```php
$inbox = EmailInbox::find(1);

$inbox->monitoredMailbox;   // BelongsTo → MonitoredMailbox
$inbox->mailboxConnection;  // HasOneThrough → MailboxConnection (via MonitoredMailbox)
```

The `mailboxConnection` relationship traverses through the `MonitoredMailbox` join, giving you direct access to the connection without intermediate queries.

### Getting a MailboxResource

The `mailboxResource()` method resolves a live API resource from the associated mailbox. This is the fastest way to go from an Eloquent model to provider API calls:

```php
$resource = $inbox->mailboxResource(); // MailboxResource

$messages = $resource->messages()
    ->inFolder('inbox')
    ->take(10)
    ->get(); // Collection<int, MessageDto>
```

> **Warning** The `mailboxResource()` method throws a `RuntimeException` if the model does not have an associated `MonitoredMailbox`. Always ensure the relationship is loaded or check for `null` before calling this method.

### Scopes Provided

```php
use Pyle\Mailbox\Models\MonitoredMailbox;
use Pyle\Mailbox\Models\MailboxConnection;

$mailbox = MonitoredMailbox::find(1);
$connection = MailboxConnection::find(1);

// All inboxes for a specific monitored mailbox
EmailInbox::forMailbox($mailbox)->get();

// All inboxes for any mailbox under a specific connection
EmailInbox::forConnection($connection)->get();
```

## What's Next

- [Connections and Mailboxes](connections-and-mailboxes.md) -- creating connections, registering mailboxes, and bridging to the API layer
- [Rule Matching](rule-matching.md) -- evaluating messages against JSON rule trees
- [Delta Sync](delta-sync.md) -- incremental synchronization using folder delta tokens

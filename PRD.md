# Product Requirements Document
## Pyle Mailbox — Multi-Provider Mailbox Management for Laravel
### `pylesoft/mailbox`

**Version:** 5.0 (Final)
**Date:** February 24, 2026
**Author:** Pyle Team
**Status:** Approved

---

## Executive Summary

`pylesoft/mailbox` is a Laravel package that provides a **driver-based mailbox management abstraction** for connecting Laravel applications to email providers, monitoring folders, syncing messages, and performing mailbox operations (read, move, mark as read, download attachments) — all through a unified API regardless of the underlying provider.

**Microsoft 365 (via Microsoft Graph API)** ships as the first driver. **Google Workspace (via Gmail API)** is planned as the second driver. Both drivers implement the same contracts, return the same DTOs, and operate against the same shared Eloquent models for connections, mailboxes, and folder monitoring.

The package is designed to power applications that:

- Monitor one or more email addresses (including shared/delegated mailboxes)
- Watch specific folders within those mailboxes
- Incrementally sync new, updated, and deleted messages via delta/history-based queries
- Apply application-level routing rules against message properties (subject, sender, attachments, etc.)
- Move messages between folders after processing
- Download attachments to configurable storage
- Run all of the above on Laravel queues, in the background, without user interaction

The MS365 driver uses **application-level permissions** (client credentials flow) with **Application Access Policies** to restrict access to only the specific mailboxes needed — no per-user OAuth, no redirect flows. The Google Workspace driver will use a Service Account with domain-wide delegation for the same pattern.

### Why This Package Exists

The current implementation uses `dcblogdev/ms-graph` directly with 7+ hand-rolled action classes that duplicate boilerplate (connection checks, pagination URL handling, response normalization) and lack retry logic, rate limiting, delta sync, or any abstraction that would allow swapping providers. As the application grows to support Google Workspace alongside MS365, the current architecture would require duplicating all business logic per provider. This package extracts the mailbox layer into a clean, driver-based abstraction — similar to how Laravel's `Mail`, `Queue`, `Filesystem`, and `Notification` systems work.

### Design Philosophy

The package follows Taylor Otwell's design principles throughout:

- **Fluent, chainable API** — Every builder returns `static` for chaining. The facade reads like English: `Mailbox::mailbox($addr)->messages()->inFolder('Inbox')->where('isRead', false)->get()`.
- **Manager pattern** — `MailboxManager` extends Laravel's `Manager` base class for driver resolution, identical to `CacheManager`, `QueueManager`, `FilesystemManager`.
- **Convention over configuration** — Sensible defaults everywhere. Zero config needed to start; fine-grained control when you need it.
- **Publishable everything** — Config, migrations, and stubs are all publishable. The package never forces a structure.
- **Type-safe and IDE-friendly** — Full return types on every method, `@template` generics on collections, PHPDoc `@method` tags on the facade, typed enums for all finite sets. Zero `mixed` returns. PHPStan level 8 clean.
- **Beautiful developer experience** — Human-readable exceptions with actionable fix suggestions, Laravel Prompts in every artisan command, dedicated log channel, stubs for extending.

---

## 1. Goals & Non-Goals

### 1.1 Goals

1. **Driver-based architecture** — Define contracts (`MailboxDriver`, `MessageQueryBuilder`, `FolderQueryBuilder`, `AttachmentResource`) that providers implement. Application code depends on contracts, never on a specific provider. Adding a new driver means implementing ~7 interfaces with zero changes to consuming code.
2. **MS365 driver (v1.0)** — Full implementation using Microsoft Graph API with client credentials, Application Access Policies, retry/rate-limit handling, and delta query.
3. **Shared Eloquent models** — The package owns `MailboxConnection`, `MonitoredMailbox`, and `MonitoredFolder` with publishable migrations, query scopes, and a `HasMailbox` trait for consuming models.
4. **Folder tree discovery** — Provider-agnostic folder tree retrieval for UI rendering (checkboxes, search, hierarchical display).
5. **Connection health & testing** — Standardized `testConnection()` and `healthCheck()` methods across drivers, including client secret expiration monitoring with advance warnings.
6. **Incremental sync** — Delta query (MS365) / History ID (Gmail) abstracted behind a common delta interface.
7. **Disk-based attachment storage** — Download attachments to a configurable Laravel filesystem disk, returning paths not base64. Skip re-download if file already exists.
8. **Clean DTO responses** — Typed, immutable DTOs shared across all drivers.
9. **Queue-safe operations** — All API calls handle serialization, token refresh, retry, and rate limiting transparently.
10. **Advanced query builder** — Support both `$filter` and `$search` on MS365 (and Gmail search syntax) with a fluent `->where()->search()->get()` interface.
11. **Flexible folder operations** — Move to any folder, create folders, resolve folder paths. Well-known folders normalized across providers via a shared enum.
12. **Message matching primitives** — Built-in `MessageMatcher` with the operators expected of an email routing tool (contains, starts with, ends with, regex, AND/OR groups, attachment-level conditions) — without owning the full rule engine UI.
13. **Stable message identity** — Immutable IDs (MS365) on by default, plus `internetMessageId` (RFC 2822) as the primary stable key across providers.
14. **Dedicated logging** — Package-specific `mailbox` log channel. Events emitted for all significant operations.
15. **Full enum coverage** — Every finite set (`ConnectionStatus`, `SyncStatus`, `WellKnownFolder`, `Importance`, `MatchOperator`) is a backed PHP enum.
16. **PEST 4 test suite** — Feature and unit tests using Pest 4 with type coverage, architecture tests, and dataset-driven assertions.
17. **Stubs & extensibility** — Publishable stubs for custom drivers, DTOs, and commands. A documented driver creation guide.
18. **Beautiful documentation** — Standalone `README.md` + `docs/` folder with focused .md guides.
19. **Laravel Prompts CLI** — Every artisan command uses `laravel/prompts` for interactive, beautiful terminal output.

### 1.2 Non-Goals

- **Sending/composing emails** — Read and organize only.
- **Owning a rule engine UI** — The package provides DTOs, filterable field metadata, and a `MessageMatcher` evaluator. The consuming app builds the rule builder UI, persistence, and grouping logic.
- **Webhook/push notification subscriptions** — v1.0 uses pull-based delta sync. Webhooks may be added in v2.0.
- **Google Workspace driver implementation** — Planned for v1.1+. v1.0 ships the contracts and the MS365 driver only.
- **Calendar, Contacts, Drive/OneDrive** — Mail only.
- **Multi-tenant SaaS OAuth flows** — Both drivers use service-level auth (app credentials / service account), not per-user OAuth.

---

## 2. Architecture

### 2.1 Driver Pattern Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                      Application Layer                          │
│  (Rule Builder, Ingestion Pipeline, Queue Jobs, UI)             │
└──────────────────────────┬──────────────────────────────────────┘
                           │ Uses contracts + DTOs
┌──────────────────────────▼──────────────────────────────────────┐
│                     pylesoft/mailbox                             │
│                                                                  │
│  Contracts/         DTOs/            Models/          Enums/    │
│  ├─ MailboxDriver   ├─ MessageDto    ├─ Connection    ├─ Well.. │
│  ├─ MailboxResource ├─ FolderDto     ├─ Mailbox       ├─ Conn.. │
│  ├─ MessageQuery..  ├─ AttachmentDto ├─ Folder        ├─ Sync.. │
│  ├─ MessageResource ├─ DeltaResult   │  (scopes)      ├─ Impo.. │
│  ├─ FolderQuery..   └─ EmailAddress  │  (traits)      └─ Match  │
│  ├─ FolderResource                   │                           │
│  └─ AttachmentRes.                   │                           │
│                                                                  │
│  Support/                                                        │
│  ├─ MessageMatcher         — Client-side rule evaluation        │
│  └─ FilterableFields       — Metadata for rule builder UIs      │
│                                                                  │
│  Drivers/                                                        │
│  ├─ MsGraph/          ├─ Gmail/  (v1.1+)                       │
│  │  ├─ MsGraphDriver  │  └─ ...                                │
│  │  ├─ GraphClient    │                                          │
│  │  ├─ TokenManager   │                                          │
│  │  ├─ RateLimiter    │                                          │
│  │  └─ DeltaSync      │                                          │
│  └────────────────────┘                                          │
└──────────────────────────────────────────────────────────────────┘
```

### 2.2 How Drivers Are Resolved

The `MailboxManager` extends Laravel's `Illuminate\Support\Manager`, following the exact pattern used by `CacheManager`, `QueueManager`, and `FilesystemManager`:

```php
namespace Pyle\Mailbox;

use Illuminate\Support\Manager;

class MailboxManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('mailbox.default', 'ms-graph');
    }

    protected function createMsGraphDriver(): MsGraphDriver
    {
        return new MsGraphDriver($this->config->get('mailbox.drivers.ms-graph'));
    }

    // Future:
    // protected function createGmailDriver(): GmailDriver { ... }
}
```

```php
// config/mailbox.php
'default' => env('MAILBOX_DRIVER', 'ms-graph'),

'drivers' => [
    'ms-graph' => [
        'driver'        => 'ms-graph',
        'tenant_id'     => env('MS365_TENANT_ID'),
        'client_id'     => env('MS365_CLIENT_ID'),
        'client_secret' => env('MS365_CLIENT_SECRET'),
    ],

    'gmail' => [
        'driver'              => 'gmail',
        'service_account_json' => env('GMAIL_SERVICE_ACCOUNT_JSON'),
        'subject_email'       => env('GMAIL_SUBJECT_EMAIL'),
    ],
],
```

```php
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Enums\WellKnownFolder;

// Uses default driver
$mailbox = Mailbox::mailbox('shared@company.com');

// Explicit driver
$mailbox = Mailbox::driver('ms-graph')->mailbox('shared@company.com');
$mailbox = Mailbox::driver('gmail')->mailbox('billing@company.com');

// Both return the same MailboxResource contract, same DTOs
$messages = $mailbox->messages()
    ->inFolder(WellKnownFolder::INBOX)
    ->where('isRead', false)
    ->get();
// Returns: Collection<MessageDto> regardless of driver
```

### 2.3 MS365 Driver — Authentication

The MS365 driver uses **client credentials flow** (same as the current `dcblogdev/ms-graph` setup). No user interaction needed.

```
POST https://login.microsoftonline.com/{tenant_id}/oauth2/v2.0/token
    client_id={client_id}
    client_secret={client_secret}
    scope=https://graph.microsoft.com/.default
    grant_type=client_credentials
```

**Token lifecycle:**
- Access tokens expire in 60–90 minutes.
- No refresh token in client credentials — the driver acquires a fresh token before expiry.
- Tokens are cached via Laravel's Cache (configurable store) with a TTL of `expires_in - buffer` seconds.
- If cache is cleared, a new token is acquired transparently.

**Client secret expiration monitoring:**
Client secrets in Entra ID expire (typically 12–24 months). The package cannot auto-rotate secrets (that requires Azure Portal / API access beyond scope), but it monitors expiration and warns:

- The `healthCheck()` method returns `secretExpiresAt` when detectable from token claims.
- The `mailbox:health` command prints a warning when the secret expires within the configured threshold (default: 30 days).
- A `SecretExpirationWarning` event is emitted when expiration is approaching, allowing the consuming app to send admin notifications.
- If the secret has expired and authentication fails, the `TokenRefreshFailed` event includes clear guidance: _"Client secret may have expired. Rotate it in Entra ID and update MS365_CLIENT_SECRET."_

**Mailbox scoping via Application Access Policies:**
By default, `Mail.ReadWrite` (application permission) grants access to every mailbox in the tenant. An Exchange admin creates an Application Access Policy restricting the app to specific mailboxes:

```powershell
# One-time setup by Exchange admin:
Connect-ExchangeOnline

New-DistributionGroup -Name "PyleMailboxAccess" -Type Security
Add-DistributionGroupMember -Identity "PyleMailboxAccess" -Member "invoices@company.com"
Add-DistributionGroupMember -Identity "PyleMailboxAccess" -Member "billing@company.com"

New-ServicePrincipal -AppId "{CLIENT_ID}" -ObjectId "{ENTERPRISE_APP_OBJECT_ID}"

New-ApplicationAccessPolicy -AppId "{CLIENT_ID}" `
    -PolicyScopeGroupId "PyleMailboxAccess" `
    -AccessRight RestrictAccess

# Verify
Test-ApplicationAccessPolicy -AppId "{CLIENT_ID}" -Identity "invoices@company.com"
# → Granted
Test-ApplicationAccessPolicy -AppId "{CLIENT_ID}" -Identity "random-user@company.com"
# → Denied
```

The package provides the `mailbox:test-access` command and setup documentation to guide through this process.

### 2.4 Gmail Driver — Authentication (Planned, v1.1+)

The Gmail driver will use a **Google Service Account with domain-wide delegation**. Same pattern: no user interaction, server-to-server auth, scoped to specific mailboxes by impersonation.

```php
$mailbox = Mailbox::driver('gmail')->mailbox('billing@company.com');
// Internally: service account authenticates, impersonates billing@company.com
```

Contracts are designed now to accommodate both providers. See [Section 18: Adding a Custom Driver](#18-adding-a-custom-driver) for the driver creation guide.

### 2.5 Rate Limiting & Retry (Cross-Driver)

Each driver implements its own rate limiting strategy, but the contract defines the behavior:

**MS365 (Graph API):**
| Limit | Value |
|-------|-------|
| Requests per app per mailbox | 10,000 / 10 minutes |
| Concurrent requests per app per mailbox | 4 |

**Gmail API (planned):**
| Limit | Value |
|-------|-------|
| Quota units per user per second | 250 |
| Queries per 100 seconds per user | 500 |

**Shared retry strategy:**
| Status | Behavior |
|--------|----------|
| `401` | Re-acquire token (once), retry. Throw `AuthenticationException` on second failure |
| `403` | Throw `MailboxAccessDeniedException` — do not retry |
| `404` | Throw `ResourceNotFoundException` |
| `429` | Respect `Retry-After` header. In queue: release job with delay. Sync: sleep. Max 3 retries |
| `5xx` | Exponential backoff (2s, 4s, 8s). Max 3 retries. Throw `ProviderServerException` |

**Concurrency limiting:**
Microsoft allows 4 concurrent requests per app per mailbox. The package uses `Cache::lock("mailbox:lock:{driver}:{email}", $timeout)` to enforce this across queue workers. Each request acquires a slot, performs the call, and releases. If all 4 slots are occupied, the caller waits up to `concurrency_lock_timeout` seconds before throwing.

### 2.6 Immutable Message Identity

Message IDs in Microsoft Graph can change when a message is moved or copied. **The package uses immutable IDs by default.** Every MS365 Graph API request includes the header:

```
Prefer: IdType="ImmutableId"
```

This causes Graph to return stable IDs that survive move/copy operations. The `MessageDto.id` field always contains the immutable variant.

Additionally, `MessageDto` exposes `internetMessageId` (the RFC 2822 `Message-ID` header) which is stable across all providers and serves as a global dedup key. The consuming application should use `internetMessageId` as the primary unique key for cross-provider scenarios.

### 2.7 JSON Batching

For bulk operations (mark multiple messages as read, move multiple messages), the MS365 driver uses Graph's `$batch` endpoint to combine up to 20 individual requests into a single HTTP call. Each individual request within a batch is evaluated against throttling limits independently. Failed items are retried individually.

---

## 3. Enums

Every finite set in the package is a backed PHP enum. No magic strings.

```php
namespace Pyle\Mailbox\Enums;

enum WellKnownFolder: string
{
    case INBOX   = 'inbox';
    case DRAFTS  = 'drafts';
    case SENT    = 'sent';
    case DELETED = 'deleted';
    case JUNK    = 'junk';
    case ARCHIVE = 'archive';
    case OUTBOX  = 'outbox';

    /**
     * Resolve to provider-specific folder name/label.
     */
    public function forDriver(string $driver): string;
}
```

| WellKnownFolder | MS365 (Graph) | Gmail (Labels) |
|-----------------|----------------|----------------|
| `INBOX` | `Inbox` | `INBOX` |
| `DRAFTS` | `Drafts` | `DRAFT` |
| `SENT` | `SentItems` | `SENT` |
| `DELETED` | `DeletedItems` | `TRASH` |
| `JUNK` | `JunkEmail` | `SPAM` |
| `ARCHIVE` | `Archive` | `All Mail` (via label removal) |
| `OUTBOX` | `Outbox` | N/A |

```php
enum ConnectionStatus: string
{
    case PENDING     = 'pending';
    case CONNECTED   = 'connected';
    case ERROR       = 'error';
    case DISABLED    = 'disabled';
}
```

```php
enum SyncStatus: string
{
    case IDLE    = 'idle';
    case SYNCING = 'syncing';
    case ERROR   = 'error';
}
```

```php
enum Importance: string
{
    case LOW    = 'low';
    case NORMAL = 'normal';
    case HIGH   = 'high';
}
```

```php
enum MatchOperator: string
{
    case EQUALS        = 'equals';
    case NOT_EQUALS    = 'not_equals';
    case CONTAINS      = 'contains';
    case NOT_CONTAINS  = 'not_contains';
    case STARTS_WITH   = 'starts_with';
    case ENDS_WITH     = 'ends_with';
    case MATCHES_REGEX = 'matches_regex';
    case GREATER_THAN  = 'greater_than';
    case LESS_THAN     = 'less_than';
    case BETWEEN       = 'between';
    case BEFORE        = 'before';
    case AFTER         = 'after';
}
```

```php
enum FilterableField: string
{
    case SUBJECT                = 'subject';
    case FROM_ADDRESS           = 'from.address';
    case FROM_NAME              = 'from.name';
    case SENDER_ADDRESS         = 'sender.address';
    case TO_ADDRESS             = 'toRecipients.address';
    case CC_ADDRESS             = 'ccRecipients.address';
    case RECEIVED_AT            = 'receivedAt';
    case IS_READ                = 'isRead';
    case IS_DRAFT               = 'isDraft';
    case HAS_ATTACHMENTS        = 'hasAttachments';
    case IMPORTANCE             = 'importance';
    case BODY_PREVIEW           = 'bodyPreview';
    case ATTACHMENT_COUNT       = 'attachmentCount';
    case ATTACHMENT_NAME        = 'attachmentName';
    case ATTACHMENT_CONTENT_TYPE = 'attachmentContentType';
    case ATTACHMENT_SIZE        = 'attachmentSize';

    /**
     * Return the available operators for this field.
     *
     * @return array<MatchOperator>
     */
    public function operators(): array;

    /**
     * Return the value type ('string', 'integer', 'boolean', 'datetime', 'enum').
     */
    public function valueType(): string;

    /**
     * Whether this field can be pushed to the provider's server-side query.
     */
    public function isServerPushable(string $driver = 'ms-graph'): bool;
}
```

Usage — the enum or a raw string both work wherever the contract accepts it:

```php
$mailbox->messages()->inFolder(WellKnownFolder::INBOX)->get();
$mailbox->messages()->inFolder('Inbox')->get();       // MS365-specific string, still works
$mailbox->message($id)->moveTo(WellKnownFolder::ARCHIVE);
$mailbox->message($id)->moveTo($customFolderId);      // Raw folder ID works too
```

---

## 4. Shared Models, Scopes & Traits

### 4.1 `MailboxConnection`

Represents a configured connection to an email provider.

```php
namespace Pyle\Mailbox\Models;

use Pyle\Mailbox\Enums\ConnectionStatus;

class MailboxConnection extends Model
{
    use SoftDeletes;

    protected $casts = [
        'status' => ConnectionStatus::class,
        'config' => 'encrypted:array',
        'last_connected_at' => 'immutable_datetime',
    ];
}
```

```php
// Migration
Schema::create('mailbox_connections', function (Blueprint $table) {
    $table->id();
    $table->string('name');                          // "Production MS365"
    $table->string('driver');                        // 'ms-graph', 'gmail'
    $table->string('status')->default('pending');
    $table->json('config')->nullable();              // Encrypted cast
    $table->timestamp('last_connected_at')->nullable();
    $table->text('last_error')->nullable();
    $table->timestamps();
    $table->softDeletes();
});
```

### 4.2 `MonitoredMailbox`

Represents a specific email address being monitored through a connection.

```php
namespace Pyle\Mailbox\Models;

class MonitoredMailbox extends Model
{
    use SoftDeletes;

    protected $casts = [
        'is_active'      => 'boolean',
        'last_synced_at' => 'immutable_datetime',
    ];
}
```

```php
Schema::create('monitored_mailboxes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('mailbox_connection_id')->constrained()->cascadeOnDelete();
    $table->string('email_address');
    $table->string('display_name')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamp('last_synced_at')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->unique(['mailbox_connection_id', 'email_address']);
});
```

### 4.3 `MonitoredFolder`

Represents a specific folder being watched within a mailbox. Stores delta sync state.

```php
namespace Pyle\Mailbox\Models;

use Pyle\Mailbox\Enums\SyncStatus;
use Pyle\Mailbox\Enums\WellKnownFolder;

class MonitoredFolder extends Model
{
    protected $casts = [
        'well_known_name' => WellKnownFolder::class,
        'sync_status'     => SyncStatus::class,
        'is_active'       => 'boolean',
        'last_synced_at'  => 'immutable_datetime',
    ];
}
```

```php
Schema::create('monitored_folders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('monitored_mailbox_id')->constrained()->cascadeOnDelete();
    $table->string('folder_id');
    $table->string('display_name');
    $table->string('path')->nullable();              // "Inbox/Finance/Invoices"
    $table->string('well_known_name')->nullable();
    $table->boolean('is_active')->default(true);
    $table->text('delta_token')->nullable();
    $table->timestamp('last_synced_at')->nullable();
    $table->string('sync_status')->default('idle');
    $table->text('last_sync_error')->nullable();
    $table->timestamps();

    $table->unique(['monitored_mailbox_id', 'folder_id']);
});
```

### 4.4 Model Relationships

```php
// MailboxConnection
public function mailboxes(): HasMany;
public function activeMailboxes(): HasMany;
public function resolveDriver(): MailboxDriver;

// MonitoredMailbox
public function connection(): BelongsTo;
public function folders(): HasMany;
public function activeFolders(): HasMany;

// MonitoredFolder
public function mailbox(): BelongsTo;
```

### 4.5 Query Scopes

Every model ships with expressive query scopes:

```php
// MailboxConnection scopes
MailboxConnection::query()
    ->active()                      // status = connected
    ->forDriver('ms-graph')         // driver = 'ms-graph'
    ->withError()                   // status = error
    ->connectedSince(now()->subDay());

// MonitoredMailbox scopes
MonitoredMailbox::query()
    ->active()                      // is_active = true
    ->forEmail('shared@company.com')
    ->stale(minutes: 30)            // last_synced_at older than 30 min
    ->neverSynced();                // last_synced_at is null

// MonitoredFolder scopes
MonitoredFolder::query()
    ->active()                      // is_active = true
    ->syncing()                     // sync_status = syncing
    ->withErrors()                  // sync_status = error
    ->needsSync(minutes: 15)        // active + (stale or never synced)
    ->forWellKnown(WellKnownFolder::INBOX);
```

### 4.6 `HasMailbox` Trait

A trait for consuming application models that need a relationship to a `MonitoredMailbox`:

```php
namespace Pyle\Mailbox\Traits;

/**
 * Add to any model that belongs to a MonitoredMailbox.
 *
 * @property-read MonitoredMailbox|null $monitoredMailbox
 * @property-read MailboxConnection|null $mailboxConnection
 */
trait HasMailbox
{
    public function monitoredMailbox(): BelongsTo
    {
        return $this->belongsTo(MonitoredMailbox::class);
    }

    public function mailboxConnection(): HasOneThrough
    {
        return $this->hasOneThrough(
            MailboxConnection::class,
            MonitoredMailbox::class,
            'id',                       // monitored_mailboxes.id
            'id',                       // mailbox_connections.id
            'monitored_mailbox_id',     // this model's FK
            'mailbox_connection_id'     // monitored_mailboxes FK
        );
    }

    /**
     * Get a driver-connected MailboxResource for this model's mailbox.
     */
    public function mailboxResource(): MailboxResource
    {
        return Mailbox::forMailbox($this->monitoredMailbox);
    }

    // Scopes
    public function scopeForMailbox(Builder $query, MonitoredMailbox $mailbox): Builder
    {
        return $query->where('monitored_mailbox_id', $mailbox->id);
    }

    public function scopeForConnection(Builder $query, MailboxConnection $connection): Builder
    {
        return $query->whereHas('monitoredMailbox', fn ($q) =>
            $q->where('mailbox_connection_id', $connection->id)
        );
    }
}
```

Usage in the consuming application:

```php
// app/Models/EmailInbox.php (your existing model)
use Pyle\Mailbox\Traits\HasMailbox;

class EmailInbox extends Model
{
    use HasMailbox;

    // Your existing columns + monitored_mailbox_id FK
}

// Now you can:
$inbox = EmailInbox::find(1);
$resource = $inbox->mailboxResource();
$messages = $resource->messages()->inFolder(WellKnownFolder::INBOX)->get();

// Scope queries:
EmailInbox::forMailbox($monitoredMailbox)->get();
EmailInbox::forConnection($connection)->get();
```

### 4.7 Model ↔ Driver Bridge

Models are persistence — drivers are operations. The bridge is clean:

```php
use Pyle\Mailbox\Facades\Mailbox;

// From a MonitoredMailbox model, get a driver-connected resource:
$monitoredMailbox = MonitoredMailbox::find(1);
$resource = Mailbox::forMailbox($monitoredMailbox);

// From a MonitoredFolder, get a folder-scoped resource:
$folder = MonitoredFolder::find(5);
$result = Mailbox::forFolder($folder)->delta($folder->delta_token);
$folder->update([
    'delta_token'    => $result->deltaLink,
    'last_synced_at' => now(),
    'sync_status'    => SyncStatus::IDLE,
]);
```

---

## 5. Contracts (Interfaces)

All contracts use full return types. No `mixed` returns. IDE autocompletion works on every method.

### 5.1 `MailboxDriver`

```php
namespace Pyle\Mailbox\Contracts;

interface MailboxDriver
{
    public function mailbox(string $emailAddress): MailboxResource;
    public function testConnection(?string $emailAddress = null): ConnectionTestResult;
    public function healthCheck(): HealthCheckResult;
}
```

### 5.2 `MailboxResource`

```php
interface MailboxResource
{
    public function messages(): MessageQueryBuilder;
    public function message(string $messageId): MessageResource;
    public function folders(): FolderQueryBuilder;
    public function folder(string|WellKnownFolder $folderId): FolderResource;
}
```

### 5.3 `MessageQueryBuilder`

Fluent builder for listing/searching messages. Each driver compiles to its native query language (OData `$filter`/`$search` for MS365, Gmail search operators for Google).

```php
interface MessageQueryBuilder
{
    /**
     * Scope to a specific folder.
     */
    public function inFolder(string|WellKnownFolder $folder): static;

    /**
     * Add a server-side filter condition.
     * Compiles to OData $filter (MS365) or search operators (Gmail).
     *
     * @param FilterableField|string $field
     * @param MatchOperator|string $operator  When omitted, $value is treated as the operator and '=' is implied
     */
    public function where(FilterableField|string $field, mixed $operator, mixed $value = null): static;

    /**
     * Full-text search across subject, body, and addresses.
     * Uses $search on MS365 (handles $filter/$search mutual exclusion transparently).
     * Uses Gmail native search syntax on Google.
     */
    public function search(string $query): static;

    /** @param array<string> $fields */
    public function select(array $fields): static;

    public function orderBy(string $field, string $direction = 'desc'): static;
    public function take(int $limit): static;
    public function pageSize(int $size): static;

    /** @return \Illuminate\Support\Collection<int, MessageDto> */
    public function get(): Collection;

    public function count(): int;
    public function first(): ?MessageDto;

    // Bulk actions (uses JSON batching where supported)
    /** @param array<string> $messageIds */
    public function markAsRead(array $messageIds): void;
    /** @param array<string> $messageIds */
    public function markAsUnread(array $messageIds): void;
    /** @param array<string> $messageIds */
    public function moveTo(string|WellKnownFolder $folder, array $messageIds): void;
}
```

**`$search` vs `$filter` on MS365:** The builder supports both. When `search()` is called alongside `where()`, the MS365 driver uses `$search` for the text query and evaluates `$filter`-incompatible conditions client-side after fetching. When only `where()` is used, everything compiles to `$filter`. This is handled transparently.

### 5.4 `MessageResource`

```php
interface MessageResource
{
    public function get(): MessageDto;
    public function body(): BodyDto;
    public function markAsRead(): void;
    public function markAsUnread(): void;
    public function moveTo(string|WellKnownFolder $folder): MessageDto;
    public function copyTo(string|WellKnownFolder $folder): MessageDto;
    public function delete(): void;

    /** @return \Illuminate\Support\Collection<int, AttachmentDto> */
    public function attachments(): Collection;

    public function attachment(string $attachmentId): AttachmentResource;

    /** @return \Illuminate\Support\Collection<int, AttachmentFileDto> */
    public function downloadAttachments(bool $includeInline = false): Collection;
}
```

### 5.5 `FolderQueryBuilder`

```php
interface FolderQueryBuilder
{
    /** @return \Illuminate\Support\Collection<int, FolderDto> */
    public function get(): Collection;

    /** @return \Illuminate\Support\Collection<int, FolderDto> */
    public function tree(int $maxDepth = 10): Collection;

    public function find(
        string $name,
        string|WellKnownFolder|null $root = null,
        bool $caseSensitive = true,
    ): ?FolderDto;

    public function create(string $name, ?string $parentId = null): FolderDto;
    public function createPath(string $path): FolderDto;
}
```

### 5.6 `FolderResource`

```php
interface FolderResource
{
    public function get(): FolderDto;

    /** @return \Illuminate\Support\Collection<int, FolderDto> */
    public function children(): Collection;

    public function messages(): MessageQueryBuilder;
    public function delta(?string $deltaToken = null): DeltaResultDto;
    public function delete(): void;
    public function moveTo(string $destinationParentId): FolderDto;
}
```

### 5.7 `AttachmentResource`

```php
interface AttachmentResource
{
    public function metadata(): AttachmentDto;

    /**
     * Download to configured disk. Skips if file already exists (content-addressable dedup).
     */
    public function download(): AttachmentFileDto;

    public function stream(): StreamInterface;
}
```

---

## 6. DTOs

Shared across all drivers. Immutable, implement `JsonSerializable` and `Illuminate\Contracts\Support\Arrayable`. Full `@property-read` PHPDoc for IDE support.

```php
namespace Pyle\Mailbox\DTOs;

use Pyle\Mailbox\Enums\Importance;

final readonly class MessageDto implements JsonSerializable, Arrayable
{
    public function __construct(
        public string $id,                           // Immutable provider ID
        public string $subject,
        public ?string $bodyPreview,
        public ?BodyDto $body,                       // Only on single-message get()
        public ?EmailAddressDto $from,
        public ?EmailAddressDto $sender,
        /** @var array<EmailAddressDto> */
        public array $toRecipients,
        /** @var array<EmailAddressDto> */
        public array $ccRecipients,
        /** @var array<EmailAddressDto> */
        public array $bccRecipients,
        public ?CarbonImmutable $receivedAt,
        public ?CarbonImmutable $sentAt,
        public bool $isRead,
        public bool $isDraft,
        public bool $hasAttachments,
        public Importance $importance,
        public ?string $conversationId,
        public ?string $internetMessageId,           // RFC 2822 — stable cross-provider dedup key
        public ?string $parentFolderId,
        /** @var array<string, mixed> */
        public array $raw = [],                      // Full provider response (escape hatch)
    ) {}

    public static function fromMsGraph(array $data): self;
    public static function fromGmail(array $data): self;  // v1.1+
}

final readonly class FolderDto implements JsonSerializable, Arrayable
{
    public function __construct(
        public string $id,
        public string $displayName,
        public ?string $parentFolderId,
        public int $childFolderCount,
        public int $totalItemCount,
        public int $unreadItemCount,
        public ?string $path,
        public ?WellKnownFolder $wellKnownName,
        /** @var array<FolderDto> */
        public array $children = [],
    ) {}
}

final readonly class EmailAddressDto implements JsonSerializable, Arrayable
{
    public function __construct(
        public string $name,
        public string $address,
    ) {}
}

final readonly class BodyDto implements JsonSerializable, Arrayable
{
    public function __construct(
        public string $contentType,                  // 'text' or 'html'
        public string $content,
    ) {}
}

final readonly class AttachmentDto implements JsonSerializable, Arrayable
{
    public function __construct(
        public string $id,
        public string $name,
        public string $contentType,
        public int $size,
        public bool $isInline,
        public ?string $contentId,
    ) {}
}

final readonly class AttachmentFileDto implements JsonSerializable, Arrayable
{
    public function __construct(
        public string $id,
        public string $name,
        public string $contentType,
        public int $size,
        public bool $isInline,
        public ?string $contentId,
        public string $path,                         // Relative path on disk
        public string $disk,                         // Laravel filesystem disk name
        public bool $alreadyExisted,                 // True if download was skipped (dedup)
    ) {}
}

final readonly class DeltaResultDto implements JsonSerializable, Arrayable
{
    public function __construct(
        /** @var \Illuminate\Support\Collection<int, MessageDto> */
        public Collection $created,
        /** @var \Illuminate\Support\Collection<int, MessageDto> */
        public Collection $updated,
        /** @var \Illuminate\Support\Collection<int, string> */
        public Collection $deleted,                  // Message IDs
        public ?string $deltaLink,
        public bool $fullSyncRequired,
    ) {}
}

final readonly class ConnectionTestResult implements JsonSerializable, Arrayable
{
    public function __construct(
        public bool $success,
        public ?string $error,
        public ?int $latencyMs,
        public ?string $authenticatedAs,
        /** @var array<string> */
        public array $accessibleMailboxes = [],
    ) {}
}

final readonly class HealthCheckResult implements JsonSerializable, Arrayable
{
    public function __construct(
        public bool $healthy,
        public bool $tokenValid,
        public ?int $tokenExpiresIn,
        public bool $apiReachable,
        public ?int $latencyMs,
        public ?CarbonImmutable $secretExpiresAt,
        public bool $secretExpirationWarning,
    ) {}
}
```

---

## 7. Facade & IDE Support

### 7.1 Facade with `@method` Tags

The facade includes full PHPDoc `@method` tags so IDEs autocomplete every method without resolving the underlying class:

```php
namespace Pyle\Mailbox\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static MailboxDriver driver(string $name = null)
 * @method static MailboxResource mailbox(string $emailAddress)
 * @method static MailboxResource forMailbox(MonitoredMailbox $mailbox)
 * @method static FolderResource forFolder(MonitoredFolder $folder)
 * @method static ConnectionTestResult testConnection(?string $emailAddress = null)
 * @method static HealthCheckResult healthCheck()
 * @method static \Illuminate\Support\Collection<int, FilterableField> filterableFields()
 *
 * @see \Pyle\Mailbox\MailboxManager
 */
class Mailbox extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MailboxManager::class;
    }
}
```

### 7.2 Type Safety Standards

The entire codebase follows these type-safety rules:

- **No `mixed` return types** — every public method has an explicit return type.
- **`final readonly class`** on all DTOs — prevents accidental mutation and inheritance.
- **Backed enums everywhere** — `ConnectionStatus`, `SyncStatus`, `WellKnownFolder`, `Importance`, `MatchOperator`, `FilterableField` are all `string`-backed enums with no magic strings.
- **Generic collection annotations** — `@return Collection<int, MessageDto>` on every method that returns a collection.
- **`@param` tags** for union types — e.g., `@param FilterableField|string $field`.
- **Strict types** — `declare(strict_types=1)` in every file.
- **PHPStan level 8** — the package CI runs PHPStan at the strictest level. No baseline ignores shipped.

---

## 8. Facade & Service API

### 8.1 Primary Usage

```php
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Enums\Importance;

// --- Driver resolution ---
Mailbox::driver('ms-graph');
Mailbox::mailbox('shared@company.com');              // Default driver

// --- From models ---
Mailbox::forMailbox($monitoredMailbox);
Mailbox::forFolder($monitoredFolder);

// --- Connection testing (powers "Test Connection" button) ---
$result = Mailbox::driver('ms-graph')->testConnection('shared@company.com');
$result->success;     // bool
$result->latencyMs;   // int
$result->error;       // string|null

// --- Health check ---
$health = Mailbox::driver('ms-graph')->healthCheck();
$health->healthy;                  // bool
$health->secretExpirationWarning;  // bool — true if within threshold

// --- Folder discovery (powers the folder tree UI with checkboxes) ---
$tree = Mailbox::mailbox('shared@company.com')->folders()->tree(maxDepth: 5);
// Returns Collection<FolderDto> with hierarchical paths

// --- Messages with fluent query builder ---
$messages = Mailbox::mailbox('shared@company.com')
    ->messages()
    ->inFolder(WellKnownFolder::INBOX)
    ->where('isRead', false)
    ->where('receivedAt', '>=', now()->subHours(6))
    ->where('hasAttachments', true)
    ->where('importance', Importance::HIGH)
    ->search('invoice')
    ->select(['id', 'subject', 'from', 'receivedAt', 'hasAttachments'])
    ->orderBy('receivedAt', 'desc')
    ->take(100)
    ->pageSize(25)
    ->get();

// --- Single message ---
$message = Mailbox::mailbox($addr)->message($id)->get();
$body    = Mailbox::mailbox($addr)->message($id)->body();

// --- Actions ---
Mailbox::mailbox($addr)->message($id)->markAsRead();
Mailbox::mailbox($addr)->message($id)->moveTo($folderId);
Mailbox::mailbox($addr)->message($id)->moveTo(WellKnownFolder::DELETED);

// --- Bulk actions (JSON batching on MS365) ---
Mailbox::mailbox($addr)->messages()->markAsRead([$id1, $id2, $id3]);
Mailbox::mailbox($addr)->messages()->moveTo($folderId, [$id1, $id2]);

// --- Attachments ---
$attachments = Mailbox::mailbox($addr)->message($id)->attachments();           // metadata
$files       = Mailbox::mailbox($addr)->message($id)->downloadAttachments();   // to disk
$file        = Mailbox::mailbox($addr)->message($id)->attachment($attId)->download();
$file->alreadyExisted;  // true if skipped (dedup)

// --- Delta sync ---
$result = Mailbox::mailbox($addr)->folder(WellKnownFolder::INBOX)->delta($storedDeltaToken);
$result->created;          // Collection<MessageDto>
$result->updated;          // Collection<MessageDto>
$result->deleted;          // Collection<string>
$result->deltaLink;        // Store for next sync
$result->fullSyncRequired; // Handle re-sync if true

// --- Raw escape hatch (driver-specific) ---
Mailbox::driver('ms-graph')->raw()->get('users/shared@company.com/messages/{id}');
```

### 8.2 Syncing a Monitored Folder (Queue Job Pattern)

```php
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Models\MonitoredFolder;
use Pyle\Mailbox\Enums\SyncStatus;

class SyncMonitoredFolder implements ShouldQueue
{
    public function handle(MonitoredFolder $folder): void
    {
        $folder->update(['sync_status' => SyncStatus::SYNCING]);

        try {
            $result = Mailbox::forFolder($folder)->delta($folder->delta_token);

            if ($result->fullSyncRequired) {
                $result = Mailbox::forFolder($folder)->delta(null);
            }

            foreach ($result->created as $messageDto) {
                ProcessNewMessage::dispatch($folder, $messageDto);
            }

            foreach ($result->deleted as $deletedId) {
                // App-level: soft-delete or mark as removed
            }

            $folder->update([
                'delta_token'      => $result->deltaLink,
                'last_synced_at'   => now(),
                'sync_status'      => SyncStatus::IDLE,
                'last_sync_error'  => null,
            ]);
        } catch (\Throwable $e) {
            $folder->update([
                'sync_status'     => SyncStatus::ERROR,
                'last_sync_error' => Str::limit($e->getMessage(), 500),
            ]);
            throw $e;
        }
    }
}
```

---

## 9. Message Matching & Rule Builder Support

### 9.1 Filterable Fields Metadata

Returns metadata for UI rendering (field dropdowns, operator dropdowns, value input types):

```php
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Enums\FilterableField;

$fields = Mailbox::filterableFields();
// Returns Collection<FilterableField> — each knows its operators, type, and pushability

// Or query a specific field:
FilterableField::SUBJECT->operators();         // [MatchOperator::EQUALS, ::CONTAINS, ::STARTS_WITH, ...]
FilterableField::SUBJECT->valueType();         // 'string'
FilterableField::SUBJECT->isServerPushable();  // true (contains only on MS365)
```

| Field | Type | Operators | Server-pushable (MS365) |
|-------|------|-----------|------------------------|
| `subject` | string | equals, contains, starts_with, ends_with, matches_regex | contains only |
| `from.address` | string | equals, contains, ends_with | equals only |
| `from.name` | string | equals, contains | No |
| `sender.address` | string | equals, contains, ends_with | No |
| `toRecipients.address` | string | equals, contains | No |
| `ccRecipients.address` | string | equals, contains | No |
| `receivedAt` | datetime | before, after, between | Yes |
| `isRead` | boolean | equals | Yes |
| `isDraft` | boolean | equals | Yes |
| `hasAttachments` | boolean | equals | Yes |
| `importance` | enum | equals | Yes |
| `bodyPreview` | string | contains, matches_regex | No |
| `attachmentCount` | integer | equals, greater_than, less_than, between | No |
| `attachmentName` | string | equals, contains, starts_with, ends_with, matches_regex | No |
| `attachmentContentType` | string | equals, contains | No |
| `attachmentSize` | integer | greater_than, less_than, between | No |

### 9.2 `MessageMatcher`

Evaluates a rule set against a `MessageDto`. Supports nested AND/OR groups:

```php
use Pyle\Mailbox\Support\MessageMatcher;
use Pyle\Mailbox\Enums\MatchOperator;
use Pyle\Mailbox\Enums\FilterableField;

// Rule structure — your app stores this however it wants (JSON column, etc.)
$rules = [
    'operator' => 'AND',
    'conditions' => [
        [
            'field'    => FilterableField::SUBJECT,           // Enum or string
            'operator' => MatchOperator::MATCHES_REGEX,       // Enum or string
            'value'    => '^(CONFIDENTIAL|INTERNAL)',
        ],
        [
            'operator' => 'OR',                               // Nested sub-group
            'conditions' => [
                [
                    'field'    => 'from.address',
                    'operator' => 'ends_with',
                    'value'    => '@vendor-portal.net',
                ],
                [
                    'field'    => 'from.address',
                    'operator' => 'contains',
                    'value'    => 'billing@',
                ],
            ],
        ],
        [
            'operator' => 'AND',                              // Attachment sub-group
            'conditions' => [
                [
                    'field'    => 'attachmentCount',
                    'operator' => 'greater_than',
                    'value'    => 1,
                ],
                [
                    'field'    => 'attachmentSize',
                    'operator' => 'less_than',
                    'value'    => 26214400,                    // 25 MB
                ],
            ],
        ],
    ],
];

$matcher = new MessageMatcher($rules);

$matches = $matcher->matches($messageDto);                        // bool
$matches = $matcher->matches($messageDto, $attachmentDtos);       // bool (with attachment checks)

$matched = $messages->filter(fn ($msg) => $matcher->matches($msg));
```

**Supported operators:**

| Operator | Applies To | Behavior |
|----------|-----------|----------|
| `equals` | string, int, bool, enum | Exact match (case-insensitive for strings) |
| `not_equals` | string, int, bool, enum | Inverse |
| `contains` | string | Substring match (case-insensitive) |
| `not_contains` | string | Inverse |
| `starts_with` | string | Prefix match (case-insensitive) |
| `ends_with` | string | Suffix match (case-insensitive) |
| `matches_regex` | string | PHP `preg_match` |
| `greater_than` | int, datetime | `>` |
| `less_than` | int, datetime | `<` |
| `between` | int, datetime | Inclusive range `[min, max]` |
| `before` | datetime | Alias for `less_than` |
| `after` | datetime | Alias for `greater_than` |

**Attachment-level conditions:** Fields prefixed with `attachment` evaluate against the `Collection<AttachmentDto>` passed as the second argument. `attachmentCount` evaluates against the collection count. Other attachment fields use existential quantifier (at least one attachment must match).

---

## 10. Logging & Observability

### 10.1 Dedicated Log Channel

The package registers a custom log channel that writes to its own file:

```php
// Auto-registered by the service provider:
'mailbox' => [
    'driver' => 'daily',
    'path'   => storage_path('logs/mailbox.log'),
    'level'  => env('MAILBOX_LOG_LEVEL', 'info'),
    'days'   => 14,
],
```

All package-internal logging uses `Log::channel('mailbox')`. When `config('app.debug')` is `true`, the level drops to `debug` automatically, producing verbose output:

**Debug mode (`app.debug = true`):**
- Every Graph/Gmail API request with method, URL, response status, and duration.
- Token acquisition, cache hits/misses.
- Rate limiter slot acquisition/release.
- Retry attempts with backoff duration.
- Delta sync change-by-change details.

**Production (`app.debug = false`):**
- Token refreshes, sync completions, errors, rate limit hits.

### 10.2 Events

Events are always emitted regardless of log level:

```php
namespace Pyle\Mailbox\Events;

// Connection lifecycle
TokenAcquired::class               // { driver, expires_in }
TokenRefreshFailed::class          // { driver, error, guidance }
SecretExpirationWarning::class     // { driver, expires_at, days_remaining }
ConnectionTestCompleted::class     // { driver, mailbox, success, latency_ms }

// API errors
RateLimitHit::class                // { driver, mailbox, retry_after, endpoint }
AccessDenied::class                // { driver, mailbox, endpoint }
ApiError::class                    // { driver, mailbox, status, error, endpoint }

// Sync
DeltaSyncStarted::class            // { driver, mailbox, folder }
DeltaSyncCompleted::class          // { driver, mailbox, folder, created, updated, deleted }
DeltaTokenExpired::class           // { driver, mailbox, folder }

// Attachment
AttachmentDownloaded::class        // { driver, mailbox, message_id, attachment_id, path, disk }
AttachmentSkipped::class           // { driver, mailbox, message_id, attachment_id, path } (dedup)
```

---

## 11. Exceptions

All exceptions extend `MailboxException`. Every exception includes a **human-readable message** with actionable guidance — no raw HTTP status dumps.

```php
namespace Pyle\Mailbox\Exceptions;

class MailboxException extends RuntimeException
{
    // Base for catch-all handling
}
```

```php
class AuthenticationException extends MailboxException
{
    // Message examples:
    // "Failed to authenticate with Microsoft Graph. The client secret may have
    //  expired — check Entra ID → App registrations → Certificates & secrets
    //  and update MS365_CLIENT_SECRET in your .env file."
    //
    // "Failed to authenticate with Microsoft Graph. Verify that MS365_TENANT_ID,
    //  MS365_CLIENT_ID, and MS365_CLIENT_SECRET are correctly set in your .env file."

    public readonly ?string $guidance;
}

class MailboxAccessDeniedException extends MailboxException
{
    // "Access denied to mailbox 'invoices@company.com'. This usually means the
    //  Application Access Policy hasn't been configured for this mailbox, or it
    //  hasn't propagated yet (can take up to 30 minutes). Run:
    //  Test-ApplicationAccessPolicy -AppId '{clientId}' -Identity 'invoices@company.com'
    //  in Exchange PowerShell to verify."

    public readonly string $mailbox;
    public readonly ?string $guidance;
}

class ResourceNotFoundException extends MailboxException
{
    // "Message 'AAMkAG...' was not found. It may have been deleted or moved.
    //  If you're using mutable IDs, consider enabling 'prefer_immutable_ids'
    //  in config/mailbox.php."

    public readonly string $resourceType;  // 'message', 'folder'
    public readonly string $resourceId;
}

class RateLimitException extends MailboxException
{
    // "Rate limit exceeded for mailbox 'invoices@company.com'. Retry after 32
    //  seconds. If this happens frequently, consider reducing page_size or
    //  spreading sync jobs across a wider time window."

    public readonly int $retryAfter;       // Seconds
    public readonly string $mailbox;
}

class ProviderServerException extends MailboxException
{
    // "Microsoft Graph returned a 503 error after 3 retry attempts. This is
    //  usually a temporary outage. Check https://status.office.com for service
    //  health. The operation will be retried automatically on the next sync."

    public readonly int $statusCode;
    public readonly int $attemptsExhausted;
}

class DeltaTokenExpiredException extends MailboxException
{
    // "The delta sync token for folder 'Inbox' in 'invoices@company.com' has
    //  expired. A full re-sync is required. This typically happens when the
    //  token hasn't been used for more than 30 days."

    public readonly string $mailbox;
    public readonly string $folderId;
}

class DriverNotConfiguredException extends MailboxException
{
    // "No configuration found for driver 'gmail'. Add it to the 'drivers' array
    //  in config/mailbox.php. Available drivers: ms-graph."
}
```

---

## 12. Artisan Commands with Laravel Prompts

Every command uses `laravel/prompts` for interactive, beautiful terminal output with spinners, tables, progress bars, and confirmations.

### 12.1 `mailbox:test-access`

Tests that the driver can authenticate and access a specific mailbox. Powers the "Test Connection" button equivalent in CLI.

```bash
php artisan mailbox:test-access

# Interactive prompt:
# ┌ Which driver? ─────────────────┐
# │ › ms-graph                     │
# │   gmail                        │
# └────────────────────────────────┘
# ┌ Email address to test? ────────┐
# │ invoices@company.com           │
# └────────────────────────────────┘
# ⠋ Testing connection...
# ✓ Connected successfully (142ms)
# ✓ Access to invoices@company.com: Granted

# Or non-interactive:
php artisan mailbox:test-access invoices@company.com --driver=ms-graph
```

### 12.2 `mailbox:folders`

Lists folders for a mailbox. Powers folder tree discovery for UI.

```bash
php artisan mailbox:folders invoices@company.com --tree --max-depth=5

# Output:
# 📂 Folder Tree for invoices@company.com
# ├── Inbox (342 items, 12 unread)
# │   ├── Finance
# │   │   ├── Invoices (89 items)
# │   │   └── Receipts (23 items)
# │   └── Vendor Portal (156 items)
# ├── Sent Items (1,204 items)
# ├── Drafts (3 items)
# └── Archived (4,521 items)
```

### 12.3 `mailbox:find-folder`

```bash
php artisan mailbox:find-folder invoices@company.com "Processed" --root=Inbox
```

### 12.4 `mailbox:health`

Health check including secret expiration warning.

```bash
php artisan mailbox:health

# Output:
# ┌─────────────────────────────────────────┐
# │ Mailbox Health Check                     │
# ├─────────────┬───────────────────────────┤
# │ Driver      │ ms-graph                  │
# │ Token       │ ✓ Valid (expires in 42m)  │
# │ API         │ ✓ Reachable (89ms)        │
# │ Secret      │ ⚠ Expires in 23 days      │
# │ Status      │ Healthy                   │
# └─────────────┴───────────────────────────┘
```

### 12.5 `mailbox:sync`

Runs delta sync on active monitored folders.

```bash
# Sync all active folders
php artisan mailbox:sync

# Sync a specific mailbox
php artisan mailbox:sync --mailbox=invoices@company.com

# Sync a specific folder
php artisan mailbox:sync --folder=5

# Output:
# ⠋ Syncing Inbox (invoices@company.com)...
# ✓ Inbox: 12 new, 3 updated, 1 deleted (1.2s)
# ⠋ Syncing Finance/Invoices (invoices@company.com)...
# ✓ Finance/Invoices: 0 new, 0 updated, 0 deleted (0.3s)
```

### 12.6 `mailbox:status`

Overview of all connections, mailboxes, folders, and sync state.

```bash
php artisan mailbox:status

# Output (table rendered with laravel/prompts):
# ┌──────────────────────────────────────────────────────────────┐
# │ Connection: Production MS365 (ms-graph) — ● Connected       │
# ├──────────────────────┬──────────┬────────────┬──────────────┤
# │ Mailbox              │ Folders  │ Last Sync  │ Status       │
# ├──────────────────────┼──────────┼────────────┼──────────────┤
# │ invoices@company.com │ 3 active │ 2 min ago  │ ● Idle       │
# │ billing@company.com  │ 1 active │ 15 min ago │ ● Idle       │
# └──────────────────────┴──────────┴────────────┴──────────────┘
```

---

## 13. Stubs

The package ships publishable stubs for extending the package with custom drivers, commands, and DTOs:

```bash
php artisan vendor:publish --tag=mailbox-stubs
```

This publishes to `stubs/mailbox/`:

```
stubs/mailbox/
├── driver.stub                    — Custom MailboxDriver implementation
├── mailbox-resource.stub          — Custom MailboxResource implementation
├── message-query-builder.stub     — Custom MessageQueryBuilder implementation
├── message-resource.stub          — Custom MessageResource implementation
├── folder-query-builder.stub      — Custom FolderQueryBuilder implementation
├── folder-resource.stub           — Custom FolderResource implementation
├── attachment-resource.stub       — Custom AttachmentResource implementation
└── dto.stub                       — Custom DTO
```

Each stub includes the full contract implementation with method signatures, PHPDoc, and `TODO` comments explaining what to implement. Example excerpt from `driver.stub`:

```php
<?php

namespace App\Mailbox\Drivers;

use Pyle\Mailbox\Contracts\MailboxDriver;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\DTOs\ConnectionTestResult;
use Pyle\Mailbox\DTOs\HealthCheckResult;

class {{ class }}Driver implements MailboxDriver
{
    public function __construct(
        private readonly array $config,
    ) {
        // TODO: Initialize your HTTP client, auth handler, etc.
    }

    public function mailbox(string $emailAddress): MailboxResource
    {
        // TODO: Return a MailboxResource scoped to this email address.
    }

    public function testConnection(?string $emailAddress = null): ConnectionTestResult
    {
        // TODO: Verify authentication and mailbox access.
    }

    public function healthCheck(): HealthCheckResult
    {
        // TODO: Check token validity, API reachability, and credential expiry.
    }
}
```

---

## 14. Configuration

```php
// config/mailbox.php
return [
    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    */
    'default' => env('MAILBOX_DRIVER', 'ms-graph'),

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | Each driver entry maps to a createXxxDriver() method on MailboxManager.
    | To add a custom driver, call Mailbox::extend('custom', fn ($app) => ...);
    |
    */
    'drivers' => [

        'ms-graph' => [
            'driver'        => 'ms-graph',
            'tenant_id'     => env('MS365_TENANT_ID'),
            'client_id'     => env('MS365_CLIENT_ID'),
            'client_secret' => env('MS365_CLIENT_SECRET'),
            'api_version'   => 'v1.0',
            'timeout'       => 30,
        ],

        // 'gmail' => [
        //     'driver'               => 'gmail',
        //     'service_account_json' => env('GMAIL_SERVICE_ACCOUNT_JSON'),
        //     'subject_email'        => env('GMAIL_SUBJECT_EMAIL'),
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Caching
    |--------------------------------------------------------------------------
    */
    'cache_store'            => env('MAILBOX_CACHE_STORE', null),
    'cache_prefix'           => 'mailbox_token',
    'token_refresh_buffer'   => 300,

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting & Retries
    |--------------------------------------------------------------------------
    */
    'max_retries'                => 3,
    'retry_backoff_base'         => 2,
    'max_concurrent_per_mailbox' => 4,
    'concurrency_lock_timeout'   => 30,

    /*
    |--------------------------------------------------------------------------
    | Default Query Settings
    |--------------------------------------------------------------------------
    */
    'default_page_size' => 50,
    'default_select'    => [
        'id', 'subject', 'from', 'sender', 'toRecipients', 'ccRecipients',
        'receivedDateTime', 'sentDateTime', 'isRead', 'isDraft',
        'hasAttachments', 'importance', 'bodyPreview',
        'conversationId', 'internetMessageId', 'parentFolderId',
    ],

    /*
    |--------------------------------------------------------------------------
    | Immutable IDs (MS365)
    |--------------------------------------------------------------------------
    */
    'prefer_immutable_ids' => true,

    /*
    |--------------------------------------------------------------------------
    | Attachment Storage
    |--------------------------------------------------------------------------
    */
    'attachment_disk' => env('MAILBOX_ATTACHMENT_DISK', 'local'),
    'attachment_path' => env('MAILBOX_ATTACHMENT_PATH', 'mailbox-attachments'),
    // Path: {attachment_path}/{mailbox}/{message_id}/{filename}
    // Dedup: skips download if file already exists

    /*
    |--------------------------------------------------------------------------
    | Client Secret Monitoring
    |--------------------------------------------------------------------------
    */
    'secret_expiry_warning_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'log_channel' => 'mailbox',
];
```

---

## 15. Testing with Pest 4

### 15.1 Test Framework & Standards

The package uses **Pest 4** exclusively with the following standards:

- `pestphp/pest ^3.0` (Pest 4 is the marketing name for the ^3.x PHP package series)
- `pestphp/pest-plugin-laravel` for Laravel-specific helpers
- `pestphp/pest-plugin-type-coverage` — enforces return type coverage
- `pestphp/pest-plugin-arch` — architecture tests

### 15.2 Architecture Tests

```php
// tests/Arch/ArchitectureTest.php

arch('contracts are interfaces')
    ->expect('Pyle\Mailbox\Contracts')
    ->toBeInterfaces();

arch('DTOs are final readonly classes')
    ->expect('Pyle\Mailbox\DTOs')
    ->toBeFinal()
    ->toBeReadonly();

arch('enums are backed string enums')
    ->expect('Pyle\Mailbox\Enums')
    ->toBeEnums();

arch('models extend Eloquent Model')
    ->expect('Pyle\Mailbox\Models')
    ->toExtend(\Illuminate\Database\Eloquent\Model::class);

arch('events are final classes')
    ->expect('Pyle\Mailbox\Events')
    ->toBeFinal();

arch('exceptions extend MailboxException')
    ->expect('Pyle\Mailbox\Exceptions')
    ->toExtend(\Pyle\Mailbox\Exceptions\MailboxException::class)
    ->ignoring(\Pyle\Mailbox\Exceptions\MailboxException::class);

arch('no debugging statements')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('strict types everywhere')
    ->expect('Pyle\Mailbox')
    ->toUseStrictTypes();
```

### 15.3 Type Coverage

```php
// tests/Arch/TypeCoverageTest.php

test('return types')
    ->expect('Pyle\Mailbox')
    ->toHaveReturnTypes();

test('parameter types')
    ->expect('Pyle\Mailbox')
    ->toHaveParameterTypes();
```

### 15.4 Unit Tests

```php
// tests/Unit/DTOs/MessageDtoTest.php

it('creates a MessageDto from MS Graph response', function () {
    $data = msgraphMessageFixture();

    $dto = MessageDto::fromMsGraph($data);

    expect($dto)
        ->id->toBe('AAMkAG...')
        ->subject->toBe('Invoice #1234')
        ->from->address->toBe('vendor@example.com')
        ->isRead->toBeFalse()
        ->importance->toBe(Importance::NORMAL)
        ->internetMessageId->toBe('<msg-123@example.com>');
});

it('serializes to array and JSON', function () {
    $dto = MessageDto::fromMsGraph(msgraphMessageFixture());

    expect($dto->toArray())->toBeArray()->toHaveKeys(['id', 'subject', 'from']);
    expect(json_decode(json_encode($dto), true))->toHaveKey('id');
});

// tests/Unit/Support/MessageMatcherTest.php

it('evaluates simple equals condition', function () {
    $matcher = new MessageMatcher([
        'operator' => 'AND',
        'conditions' => [
            ['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice'],
        ],
    ]);

    expect($matcher->matches(messageDto(subject: 'Your invoice #123')))->toBeTrue();
    expect($matcher->matches(messageDto(subject: 'Hello world')))->toBeFalse();
});

it('evaluates nested AND/OR groups', function () {
    // ...
});

it('evaluates attachment conditions', function () {
    // ...
});

dataset('operators', [
    'equals'        => ['equals', 'hello', 'hello', true],
    'contains'      => ['contains', 'hello world', 'world', true],
    'starts_with'   => ['starts_with', 'hello world', 'hello', true],
    'ends_with'     => ['ends_with', 'hello world', 'world', true],
    'matches_regex' => ['matches_regex', 'CONFIDENTIAL: secret', '^CONFIDENTIAL', true],
]);

it('evaluates operator correctly', function ($op, $actual, $expected, $result) {
    // ...
})->with('operators');

// tests/Unit/ODataFilterCompilerTest.php

it('compiles where clauses to OData $filter', function () {
    $compiler = new ODataFilterCompiler();
    $compiler->where('isRead', '=', false);
    $compiler->where('receivedDateTime', '>=', '2026-01-01T00:00:00Z');

    expect($compiler->compile())->toBe(
        "isRead eq false and receivedDateTime ge 2026-01-01T00:00:00Z"
    );
});
```

### 15.5 Feature Tests

```php
// tests/Feature/MsGraph/GraphClientTest.php

it('acquires and caches a token', function () {
    Http::fake([
        '*/oauth2/v2.0/token' => Http::response([
            'access_token' => 'test-token',
            'expires_in'   => 3600,
        ]),
    ]);

    $client = createGraphClient();
    $token = $client->getToken();

    expect($token)->toBe('test-token');
    Http::assertSentCount(1);

    // Second call uses cache
    $client->getToken();
    Http::assertSentCount(1);
});

it('retries on 429 with Retry-After', function () {
    Http::fake(Http::sequence()
        ->push(status: 429, headers: ['Retry-After' => '1'])
        ->push(['value' => []], 200)
    );

    $result = createGraphClient()->get('users/test@example.com/messages');

    expect($result)->toHaveKey('value');
    Http::assertSentCount(2);
});

it('throws MailboxAccessDeniedException on 403', function () {
    Http::fake(['*' => Http::response(status: 403)]);

    createGraphClient()->get('users/test@example.com/messages');
})->throws(MailboxAccessDeniedException::class);

// tests/Feature/MsGraph/DeltaSyncTest.php

it('performs initial delta sync and returns created messages', function () {
    // ...
});

it('handles 410 Gone with fullSyncRequired', function () {
    // ...
});

// tests/Feature/DriverResolutionTest.php

it('resolves the default driver', function () {
    $driver = Mailbox::driver();
    expect($driver)->toBeInstanceOf(MsGraphDriver::class);
});

it('throws DriverNotConfiguredException for unknown drivers', function () {
    Mailbox::driver('unknown');
})->throws(DriverNotConfiguredException::class);
```

---

## 16. Documentation

### 16.1 Structure

```
docs/
├── installation.md          — Composer, publish config/migrations, .env setup
├── configuration.md         — All config options explained with examples
├── quickstart.md            — 5-minute "first sync" walkthrough
├── authentication/
│   ├── ms-graph.md          — Entra ID app registration, permissions, access policy
│   └── gmail.md             — Service account, domain-wide delegation (v1.1+)
├── usage/
│   ├── connections.md       — Managing MailboxConnection models
│   ├── mailboxes.md         — MonitoredMailbox setup and lifecycle
│   ├── folders.md           — Folder discovery, tree, find, create
│   ├── messages.md          — Query builder, filters, search, pagination
│   ├── attachments.md       — Download, dedup, disk configuration
│   └── delta-sync.md        — Incremental sync, token management, full re-sync
├── rule-matching.md         — MessageMatcher, FilterableFields, operator reference
├── models-and-traits.md     — HasMailbox trait, query scopes, model bridge
├── events.md                — Full event reference with listener examples
├── logging.md               — Log channel, debug mode, troubleshooting
├── testing.md               — Mocking the Mailbox facade, test helpers
├── extending/
│   ├── custom-drivers.md    — Step-by-step guide to adding a new driver
│   └── stubs.md             — Available stubs and how to use them
├── migration-guide.md       — Moving from dcblogdev/ms-graph
└── troubleshooting.md       — Common errors, exception guidance, FAQ
```

### 16.2 README.md

The `README.md` is the package's front door. It includes:

1. **Hero section** — Package name, tagline, badges (tests, coverage, PHPStan, Laravel version)
2. **Feature highlights** — Bullet list of key capabilities
3. **Quick install** — `composer require`, publish, migrate, `.env`
4. **30-second usage example** — Single code block showing connection → query → result
5. **Supported drivers** — Table with MS365 ✅ and Gmail 🔜
6. **Documentation link** — Points to `docs/` folder
7. **Contributing** — How to add drivers, run tests, submit PRs
8. **License** — MIT

---

## 17. Migrating from Current Implementation

### What Gets Deleted from `pyle/ingest`

| Current Class | Replacement |
|---|---|
| `FindMailboxFolder` | `Mailbox::mailbox($addr)->folders()->find(...)` |
| `GetEmailList` | `Mailbox::mailbox($addr)->messages()->inFolder(...)->where(...)->get()` |
| `GetEmailFromId` | `Mailbox::mailbox($addr)->message($id)->get()` |
| `GetEmailAttachments` | `Mailbox::mailbox($addr)->message($id)->downloadAttachments()` |
| `GetEmailAttachmentMetadata` | `Mailbox::mailbox($addr)->message($id)->attachments()` |
| `MoveMailboxMessage` | `Mailbox::mailbox($addr)->message($id)->moveTo($folderId)` |
| `ListMailboxFolders` | `Mailbox::mailbox($addr)->folders()->tree()` |

The `dcblogdev/ms-graph` composer dependency is removed entirely.

### What Stays in `pyle/ingest`

- `EmailInbox` → adds `HasMailbox` trait and `monitored_mailbox_id` FK
- `EmailMessage` → populated from `MessageDto`, keyed on `internetMessageId`
- `EmailAttachment` → `content_bytes` column replaced with `disk` + `path` columns
- `FetchMessages` → refactored to use delta sync
- All business logic (ingestion, PDF detection, rule evaluation)

---

## 18. Adding a Custom Driver

The package is designed so that adding a new provider is a matter of implementing ~7 interfaces. No core package code changes required.

### 18.1 Steps

1. **Publish stubs:**
   ```bash
   php artisan vendor:publish --tag=mailbox-stubs
   ```

2. **Implement the contracts.** Start with the driver, then the resource classes:
   ```
   App\Mailbox\Drivers\MyProvider\
   ├── MyProviderDriver.php            → implements MailboxDriver
   ├── MyProviderMailboxResource.php   → implements MailboxResource
   ├── MyProviderMessageQuery.php      → implements MessageQueryBuilder
   ├── MyProviderMessageResource.php   → implements MessageResource
   ├── MyProviderFolderQuery.php       → implements FolderQueryBuilder
   ├── MyProviderFolderResource.php    → implements FolderResource
   └── MyProviderAttachmentResource.php → implements AttachmentResource
   ```

3. **Register the driver** in a service provider:
   ```php
   use Pyle\Mailbox\Facades\Mailbox;

   public function boot(): void
   {
       Mailbox::extend('my-provider', function ($app) {
           return new MyProviderDriver(
               config('mailbox.drivers.my-provider')
           );
       });
   }
   ```

4. **Add config:**
   ```php
   // config/mailbox.php → drivers
   'my-provider' => [
       'driver' => 'my-provider',
       'api_key' => env('MY_PROVIDER_API_KEY'),
   ],
   ```

5. **Use it:**
   ```php
   Mailbox::driver('my-provider')->mailbox('user@example.com')->messages()->get();
   ```

### 18.2 What the Driver Must Handle

| Responsibility | Contract Method | Notes |
|---|---|---|
| Authentication | `testConnection()`, `healthCheck()` | Token/key management is internal to the driver |
| Message listing | `MessageQueryBuilder::get()` | Compile `where()` and `search()` to native query |
| Message actions | `MessageResource::markAsRead()`, `moveTo()`, etc. | |
| Folder tree | `FolderQueryBuilder::tree()` | Must populate `FolderDto.path` and `wellKnownName` |
| Well-known folder mapping | Use `WellKnownFolder::forDriver()` or implement mapping | |
| Delta/incremental sync | `FolderResource::delta()` | Return `DeltaResultDto` |
| Attachment download | `AttachmentResource::download()` | Must write to configured disk, respect dedup |
| Rate limiting | Internal | The driver handles its own rate limiting |
| Retry logic | Internal | Follow the retry contract (401 re-auth, 429 backoff, 5xx exponential) |

### 18.3 DTO Factory Methods

Each DTO has a static `fromXxx()` factory method per driver. When adding a new driver, add a new factory:

```php
// In MessageDto:
public static function fromMyProvider(array $data): self
{
    return new self(
        id: $data['uid'],
        subject: $data['title'] ?? '',
        // ... map all fields
    );
}
```

---

## 19. Dependencies

| Package | Purpose |
|---------|---------|
| `laravel/framework ^12.0` | Framework (Laravel 12 minimum) |
| `guzzlehttp/guzzle ^7.0` | HTTP client |

**Dev dependencies:**
| Package | Purpose |
|---------|---------|
| `pestphp/pest ^3.0` | Test framework (Pest 4) |
| `pestphp/pest-plugin-laravel` | Laravel test helpers |
| `pestphp/pest-plugin-type-coverage` | Return type coverage enforcement |
| `pestphp/pest-plugin-arch` | Architecture tests |
| `larastan/larastan ^3.0` | PHPStan for Laravel (level 8) |

Zero third-party Microsoft/Google SDK dependencies. Both Graph and Gmail APIs are HTTP+JSON — the package owns its HTTP layer.

---

## 20. Package Structure

```
pylesoft/mailbox/
├── config/
│   └── mailbox.php
├── database/
│   └── migrations/
│       ├── create_mailbox_connections_table.php
│       ├── create_monitored_mailboxes_table.php
│       └── create_monitored_folders_table.php
├── docs/
│   ├── installation.md
│   ├── configuration.md
│   ├── quickstart.md
│   ├── authentication/
│   │   ├── ms-graph.md
│   │   └── gmail.md
│   ├── usage/
│   │   ├── connections.md
│   │   ├── mailboxes.md
│   │   ├── folders.md
│   │   ├── messages.md
│   │   ├── attachments.md
│   │   └── delta-sync.md
│   ├── rule-matching.md
│   ├── models-and-traits.md
│   ├── events.md
│   ├── logging.md
│   ├── testing.md
│   ├── extending/
│   │   ├── custom-drivers.md
│   │   └── stubs.md
│   ├── migration-guide.md
│   └── troubleshooting.md
├── stubs/
│   ├── driver.stub
│   ├── mailbox-resource.stub
│   ├── message-query-builder.stub
│   ├── message-resource.stub
│   ├── folder-query-builder.stub
│   ├── folder-resource.stub
│   ├── attachment-resource.stub
│   └── dto.stub
├── src/
│   ├── MailboxServiceProvider.php
│   ├── MailboxManager.php
│   │
│   ├── Facades/
│   │   └── Mailbox.php                      — Full @method PHPDoc tags
│   │
│   ├── Enums/
│   │   ├── WellKnownFolder.php
│   │   ├── ConnectionStatus.php
│   │   ├── SyncStatus.php
│   │   ├── Importance.php
│   │   ├── MatchOperator.php
│   │   └── FilterableField.php
│   │
│   ├── Contracts/
│   │   ├── MailboxDriver.php
│   │   ├── MailboxResource.php
│   │   ├── MessageQueryBuilder.php
│   │   ├── MessageResource.php
│   │   ├── FolderQueryBuilder.php
│   │   ├── FolderResource.php
│   │   └── AttachmentResource.php
│   │
│   ├── DTOs/
│   │   ├── MessageDto.php                   — final readonly class
│   │   ├── FolderDto.php
│   │   ├── EmailAddressDto.php
│   │   ├── BodyDto.php
│   │   ├── AttachmentDto.php
│   │   ├── AttachmentFileDto.php
│   │   ├── DeltaResultDto.php
│   │   ├── ConnectionTestResult.php
│   │   └── HealthCheckResult.php
│   │
│   ├── Models/
│   │   ├── MailboxConnection.php            — Scopes, enum casts, encrypted config
│   │   ├── MonitoredMailbox.php             — Scopes, relationships
│   │   └── MonitoredFolder.php              — Scopes, enum casts, delta state
│   │
│   ├── Traits/
│   │   └── HasMailbox.php                   — For consuming app models
│   │
│   ├── Support/
│   │   ├── MessageMatcher.php
│   │   └── FilterableFields.php
│   │
│   ├── Events/                              — All events (section 10.2)
│   ├── Exceptions/                          — All exceptions (section 11)
│   │
│   ├── Commands/                            — All commands use laravel/prompts
│   │   ├── TestAccessCommand.php
│   │   ├── ListFoldersCommand.php
│   │   ├── FindFolderCommand.php
│   │   ├── HealthCheckCommand.php
│   │   ├── SyncCommand.php
│   │   └── StatusCommand.php
│   │
│   └── Drivers/
│       └── MsGraph/
│           ├── MsGraphDriver.php
│           ├── MsGraphMailboxResource.php
│           ├── MsGraphMessageQuery.php
│           ├── MsGraphMessageResource.php
│           ├── MsGraphFolderQuery.php
│           ├── MsGraphFolderResource.php
│           ├── MsGraphAttachmentResource.php
│           ├── MsGraphDeltaSync.php
│           ├── GraphClient.php
│           ├── TokenManager.php
│           ├── RateLimiter.php
│           ├── BatchRequest.php
│           └── ODataFilterCompiler.php
│
└── tests/
    ├── Arch/
    │   ├── ArchitectureTest.php             — Structure enforcement
    │   └── TypeCoverageTest.php             — Return + parameter type coverage
    ├── Feature/
    │   ├── MsGraph/
    │   │   ├── GraphClientTest.php
    │   │   ├── MessageQueryTest.php
    │   │   ├── FolderOperationsTest.php
    │   │   ├── DeltaSyncTest.php
    │   │   ├── AttachmentDownloadTest.php
    │   │   └── RateLimitingTest.php
    │   └── DriverResolutionTest.php
    ├── Unit/
    │   ├── DTOs/
    │   │   ├── MessageDtoTest.php
    │   │   └── FolderDtoTest.php
    │   ├── Support/
    │   │   └── MessageMatcherTest.php
    │   ├── Enums/
    │   │   └── WellKnownFolderTest.php
    │   ├── TokenManagerTest.php
    │   └── ODataFilterCompilerTest.php
    ├── Fixtures/                             — JSON fixtures for MS Graph responses
    │   ├── message.json
    │   ├── messages-list.json
    │   ├── folder-tree.json
    │   ├── delta-response.json
    │   └── attachments.json
    ├── Helpers.php                           — Test helper functions (messageDto(), createGraphClient())
    └── Pest.php                              — Pest configuration
```

---

## 21. Milestones

### Phase 1: Core + Contracts + Auth (Week 1–2)
- `MailboxManager` extending Laravel's `Manager`, `MailboxServiceProvider`, facade with `@method` tags
- All contracts (7 interfaces) with full return types
- All enums: `WellKnownFolder`, `ConnectionStatus`, `SyncStatus`, `Importance`, `MatchOperator`, `FilterableField`
- `GraphClient` with Guzzle, immutable IDs, retry, error handling
- `TokenManager` with client credentials, cache, secret expiration detection
- Config publishing, log channel registration, `.env` setup
- Models with enum casts, encrypted config, query scopes, relationships
- `HasMailbox` trait
- `mailbox:health` and `mailbox:test-access` commands (Laravel Prompts)
- Pest 4 architecture tests + type coverage tests

### Phase 2: Messages + Folders (Week 3–4)
- `MsGraphMessageQuery` with `ODataFilterCompiler` (both `$filter` and `$search`)
- `MsGraphMessageResource` (get, body, mark, move, copy, delete)
- All DTOs as `final readonly class` with `fromMsGraph()` factories
- Automatic pagination (nextLink handling)
- `MsGraphFolderQuery` with tree traversal and find
- `MsGraphFolderResource` with create, createPath
- `mailbox:folders` and `mailbox:find-folder` commands
- Unit tests for ODataFilterCompiler, DTOs
- Feature tests for message queries, folder operations

### Phase 3: Rate Limiting + Batching (Week 5)
- `RateLimiter` with per-mailbox concurrency locks
- 429 handling with Retry-After
- `BatchRequest` for bulk mark-read and bulk-move
- Queue-aware retry (release vs. sleep)
- Events for rate limiting
- Feature tests for retry, rate limiting, batching

### Phase 4: Attachments on Disk (Week 6)
- `MsGraphAttachmentResource` with metadata and download
- Disk storage with dedup (skip if file exists, `alreadyExisted` flag)
- `AttachmentFileDto`
- `downloadAttachments()` for bulk download
- Feature tests for attachment download, dedup

### Phase 5: Delta Sync (Week 7)
- `MsGraphDeltaSync` with initial and incremental sync
- `DeltaResultDto` with created/updated/deleted
- 410 Gone → `fullSyncRequired` handling
- `MonitoredFolder.delta_token` persistence pattern
- `mailbox:sync` and `mailbox:status` commands
- Feature tests for delta sync lifecycle

### Phase 6: Rule Matching + Stubs + Docs (Week 8)
- `MessageMatcher` with all operators, nested AND/OR, attachment conditions
- `FilterableFields` metadata
- Publishable stubs for custom drivers
- Full `docs/` folder (all .md files)
- README with badges, quick install, usage example
- Migration guide from `dcblogdev/ms-graph`
- PHPStan level 8 pass, final test coverage review

# Artisan Commands

Mailbox ships with a set of Artisan commands for testing connections, inspecting folder structures, running syncs, and monitoring the health of your mail integrations. These commands are designed for development workflows, CI pipelines, and production health checks. Every command uses Laravel Prompts for interactive input when arguments are omitted, so they work equally well in scripts and at the terminal.

This page documents every command with its full signature, all arguments and options, and realistic terminal output so you know exactly what to expect.

## mailbox:test-access

Tests authentication and mailbox access for a given email address. This is the first command you should run after configuring a new driver to verify that credentials, permissions, and delegated access are working correctly.

### Signature

```bash
php artisan mailbox:test-access {email?} {--driver=}
```

### Arguments and Options

| Name | Type | Description |
|---|---|---|
| `email` | argument (optional) | The email address to test access for. If omitted, the command prompts you interactively. |
| `--driver` | option | The driver to use (e.g., `ms-graph`, `gmail`). If omitted, the command prompts you to select from configured drivers. |

### Example Usage

```bash
# Interactive — prompts for driver and email
php artisan mailbox:test-access

# Fully specified
php artisan mailbox:test-access invoices@acme.com --driver=ms-graph
```

### Sample Output (Success)

```
 Connected successfully (247ms)
 Access to invoices@acme.com: Granted
```

### Sample Output (Failure)

```
 ERROR  Access denied: insufficient permissions for invoices@acme.com
```

> **Tip** Run this command immediately after setting up a new service account or app registration. It catches permission issues before they surface in production.

## mailbox:health

Runs a comprehensive health check against the configured driver, reporting token validity, API reachability, latency, and client secret expiration status. Use this in monitoring dashboards or CI smoke tests.

### Signature

```bash
php artisan mailbox:health {--driver=}
```

### Options

| Name | Type | Description |
|---|---|---|
| `--driver` | option | The driver to check. If omitted, the command prompts you to select from configured drivers. |

### Example Usage

```bash
# Interactive — prompts for driver selection
php artisan mailbox:health

# Specify the driver directly
php artisan mailbox:health --driver=ms-graph
```

### Sample Output

```
 Mailbox Health Check

  Metric                 Value
  Driver                 ms-graph
  Token                  Valid
  Token Expires In       2847s
  API                    Reachable
  Latency                189ms
  Secret Expiration      2026-09-14 00:00:00
  Warning                No
  Overall Status         Healthy
```

When the secret is expiring within the configured warning threshold (`mailbox.secret_expiry_warning_days`, default 30 days), the **Warning** row shows `Yes`. If the token is invalid or the API is unreachable, **Overall Status** shows `Unhealthy` and the command exits with a non-zero status code.

> **Tip** Add `php artisan mailbox:health --driver=ms-graph` to your deployment pipeline or a scheduled health-check monitor. The command returns exit code `0` for healthy and `1` for unhealthy, making it easy to integrate with alerting tools.

## mailbox:folders

Lists all folders in a mailbox, either as a flat table or as a nested tree. This is useful for discovering folder IDs, verifying folder structures, and debugging folder-based operations.

### Signature

```bash
php artisan mailbox:folders {email} {--driver=} {--tree} {--max-depth=5}
```

### Arguments and Options

| Name | Type | Description |
|---|---|---|
| `email` | argument (required) | The email address of the mailbox to inspect. |
| `--driver` | option | The driver to use. Defaults to the configured default driver. |
| `--tree` | flag | Display folders as a nested tree instead of a flat table. |
| `--max-depth` | option | Maximum nesting depth when using `--tree`. Defaults to `5`. |

### Example Usage

```bash
# Flat table view
php artisan mailbox:folders invoices@acme.com

# Tree view with Gmail
php artisan mailbox:folders billing@vendor.com --driver=gmail --tree

# Tree view limited to 2 levels deep
php artisan mailbox:folders invoices@acme.com --tree --max-depth=2
```

### Sample Output (Flat Table)

```
  ID                             Name           Path            Unread  Total
  AAMkAGI2TG93AAA=              Inbox          Inbox           12      847
  AAMkAGI2TG94AAA=              Sent Items     Sent Items      0       2341
  AAMkAGI2TG95AAA=              Drafts         Drafts          3       18
  AAMkAGI2TG96AAA=              Deleted Items  Deleted Items   0       56
  AAMkAGI2TG97AAA=              Archive        Archive         0       4892
  AAMkAGI2TG98AAA=              Invoices       Invoices        4       312
  AAMkAGI2TG99AAA=              2024           Invoices/2024   0       189
  AAMkAGI2TH00AAA=              2025           Invoices/2025   4       123
```

### Sample Output (Tree View)

```
 Folder Tree for invoices@acme.com

- Inbox (12 unread / 847 total)
- Sent Items (0 unread / 2341 total)
- Drafts (3 unread / 18 total)
- Deleted Items (0 unread / 56 total)
- Archive (0 unread / 4892 total)
- Invoices (4 unread / 312 total)
    - 2024 (0 unread / 189 total)
    - 2025 (4 unread / 123 total)
```

## mailbox:find-folder

Searches for a folder by name within a mailbox and returns its ID and path. This is handy when you need to look up a folder ID for configuration or when debugging folder-scoped queries.

### Signature

```bash
php artisan mailbox:find-folder {email} {name} {--driver=} {--root=}
```

### Arguments and Options

| Name | Type | Description |
|---|---|---|
| `email` | argument (required) | The email address of the mailbox to search. |
| `name` | argument (required) | The display name of the folder to find. |
| `--driver` | option | The driver to use. Defaults to the configured default driver. |
| `--root` | option | A parent folder ID to scope the search. Only searches within this folder's children. |

### Example Usage

```bash
# Find a folder by name
php artisan mailbox:find-folder invoices@acme.com "Invoices"

# Search within a specific parent folder
php artisan mailbox:find-folder invoices@acme.com "2025" --root=AAMkAGI2TG98AAA=

# Using Gmail
php artisan mailbox:find-folder billing@vendor.com "Receipts" --driver=gmail
```

### Sample Output (Found)

```
 Found folder: Invoices (AAMkAGI2TG98AAA=)
 Path: Invoices
```

### Sample Output (Not Found)

```
 ERROR  Folder "Receipts" was not found.
```

## mailbox:sync

Runs delta sync for all active monitored folders, or a filtered subset. This command is the CLI equivalent of dispatching sync jobs and is primarily useful for development, debugging, and manual one-off syncs. For production use, prefer dispatching queue jobs on a schedule (see [Delta Sync](delta-sync.md)).

### Signature

```bash
php artisan mailbox:sync {--mailbox=} {--folder=}
```

### Options

| Name | Type | Description |
|---|---|---|
| `--mailbox` | option | Filter to only sync folders belonging to this email address. |
| `--folder` | option | Filter to only sync the monitored folder with this database ID. |

When neither option is provided, the command syncs every active monitored folder in the database.

### Example Usage

```bash
# Sync all active monitored folders
php artisan mailbox:sync

# Sync only folders for a specific mailbox
php artisan mailbox:sync --mailbox=invoices@acme.com

# Sync a single monitored folder by its database ID
php artisan mailbox:sync --folder=42
```

### Sample Output (Success)

```
 Inbox: 3 new, 1 updated, 0 deleted
 Invoices: 0 new, 0 updated, 2 deleted
```

### Sample Output (No Folders)

```
 No matching monitored folders found.
```

### Sample Output (Error)

```
 ERROR  Failed syncing Inbox: Token acquisition failed — client secret may be expired.
```

The command updates each `Folder` model's `sync_status`, `delta_token`, `last_synced_at`, and `last_sync_error` columns as it processes each folder. If a sync fails, the folder's status is set to `error` and the error message is recorded. The command exits with a non-zero status code on failure.

> **Warning** This command runs syncs sequentially and synchronously. For production workloads with many monitored folders, dispatch `SyncMailboxFolder` jobs to your queue instead. See [Delta Sync](delta-sync.md) for a complete queue job example.

## mailbox:status

Displays an overview of all mailbox connections and their monitored mailboxes, including active folder counts, last sync times, and current status. This is your dashboard command for understanding the state of your entire mail integration at a glance.

### Signature

```bash
php artisan mailbox:status
```

This command takes no arguments or options.

### Example Usage

```bash
php artisan mailbox:status
```

### Sample Output

```
 Connection: Production (ms-graph) - connected

  Mailbox                  Active Folders  Last Sync        Status
  invoices@acme.com        3               2 minutes ago    Active
  support@acme.com         2               14 minutes ago   Active
  billing@acme.com         1               Never            Active

 Connection: Gmail Integration (gmail) - connected

  Mailbox                  Active Folders  Last Sync        Status
  billing@vendor.com       1               7 minutes ago    Active
```

### Sample Output (No Connections)

```
 No mailbox connections configured.
```

The status values shown in the **Status** column come from the `Mailbox` model's `is_active` flag (`Active` or `Disabled`). The connection-level status in the header (e.g., `connected`) comes from the `MailboxConnection` model's `ConnectionStatus` enum, which has four possible values: `pending`, `connected`, `error`, and `disabled`.

## Exit Codes

All Mailbox commands follow standard exit code conventions:

| Code | Meaning |
|---|---|
| `0` | Success. The command completed without errors. |
| `1` | Failure. The operation failed (connection error, folder not found, sync error, unhealthy status, etc.). |

You can use these exit codes in shell scripts, CI pipelines, and monitoring tools:

```bash
php artisan mailbox:health --driver=ms-graph || echo "Mailbox health check failed!"
```

## What's Next

- [Delta Sync](delta-sync.md) -- the conceptual guide to incremental synchronization, with a complete queue job example
- [Configuration](configuration.md) -- all configuration options including driver setup and sync-related settings
- [Troubleshooting](troubleshooting.md) -- common issues and how to resolve them

# Model Rename Migration Guide

This guide is for teams upgrading from the old model names:

- `MonitoredMailbox`
- `MonitoredFolder`
- `monitored_mailbox_id`

to the new model names:

- `Mailbox`
- `Folder`
- `mailbox_id`

## What Changed

### Class and table renames

| Old | New |
|---|---|
| `Pyle\Mailbox\Models\MonitoredMailbox` | `Pyle\Mailbox\Models\Mailbox` |
| `Pyle\Mailbox\Models\MonitoredFolder` | `Pyle\Mailbox\Models\Folder` |
| `monitored_mailboxes` | `mailbox_mailboxes` |
| `monitored_folders` | `mailbox_folders` |

### Foreign key renames

| Old | New |
|---|---|
| `mailbox_messages.monitored_mailbox_id` | `mailbox_messages.mailbox_id` |
| `mailbox_folders.monitored_mailbox_id` | `mailbox_folders.mailbox_id` |

### Relationship / API surface changes

- `MailboxMessage::monitoredMailbox()` is now `MailboxMessage::mailbox()`.
- `HasMailbox` now expects `mailbox_id` and relation `mailbox`.
- `HasMailbox::scopeForMailbox()` now filters by `mailbox_id`.
- Facade/manager signatures now use `Mailbox` and `Folder` model types.

## Migration Steps

1. Update your dependencies and pull the new package code.
2. Run database migrations:

```bash
php artisan migrate
```

3. Replace old imports in app code and tests:

```php
use Pyle\Mailbox\Models\MonitoredMailbox;
use Pyle\Mailbox\Models\MonitoredFolder;
```

with:

```php
use Pyle\Mailbox\Models\Mailbox;
use Pyle\Mailbox\Models\Folder;
```

4. Replace old field names in create/update/query code:

- `monitored_mailbox_id` -> `mailbox_id`

5. Replace relationship usage:

- `$message->monitoredMailbox` -> `$message->mailbox`
- `whereHas('monitoredMailbox', ...)` -> `whereHas('mailbox', ...)`

6. If you use `HasMailbox` on your own models, confirm your table has:

- `mailbox_id` column
- foreign key constrained to `mailbox_mailboxes`

## Search-and-Replace Checklist

Run this to find remaining old references:

```bash
rg -n "MonitoredMailbox|MonitoredFolder|monitoredMailbox|monitored_mailbox_id|monitored_mailboxes|monitored_folders" app config database tests
```

Expected result after migration: no matches outside legacy migration files.

## Deployment Checks

Because table and column names are renamed, old code and new schema are not fully compatible at runtime. Deploy code and migration together in one release window.

Before deploy:

- Pause workers/cron jobs that read/write mailbox tables.
- Ensure DB backup/snapshot is available.

During deploy:

- Deploy application code.
- Run `php artisan migrate --force`.
- Restart workers.

After deploy:

- Run `php artisan mailbox:status`.
- Run a targeted `php artisan mailbox:sync --mailbox=<email>`.
- Validate app flows that create/read `Mailbox`, `Folder`, and `MailboxMessage`.

## Data Integrity Checks

After migration, validate counts and FK health:

- Row counts in renamed tables match pre-migration expectations.
- `mailbox_messages.mailbox_id` rows all reference existing `mailbox_mailboxes.id`.
- `mailbox_folders.mailbox_id` rows all reference existing `mailbox_mailboxes.id`.

## Rollback Notes

The migration includes a `down()` path that renames schema objects back to old names. If you rollback the database, you must also rollback application code to the old model names and old foreign-key fields in the same release.

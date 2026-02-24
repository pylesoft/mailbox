# Migration Guide

This guide maps common direct-Graph workflows to package APIs.

## Replace Legacy Actions

| Previous Pattern | Package API |
| --- | --- |
| Find mailbox folder | `Mailbox::mailbox($addr)->folders()->find(...)` |
| List emails | `Mailbox::mailbox($addr)->messages()->inFolder(...)->where(...)->get()` |
| Get email by ID | `Mailbox::mailbox($addr)->message($id)->get()` |
| Download attachments | `Mailbox::mailbox($addr)->message($id)->downloadAttachments()` |
| Move message | `Mailbox::mailbox($addr)->message($id)->moveTo($folderId)` |

## Data Model Alignment

- Store mailbox connection metadata in `mailbox_connections`.
- Store monitored addresses in `monitored_mailboxes`.
- Store folder sync tokens in `monitored_folders.delta_token`.

## Rollout Strategy

1. Introduce package and run migrations.
2. Create monitored mailbox/folder rows for currently watched addresses.
3. Switch ingestion jobs to `Mailbox::forFolder($folder)->delta(...)`.
4. Remove direct provider SDK usage after parity validation.

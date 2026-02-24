# Migration Guide

This guide helps migrate direct provider code to package abstractions.

## API Mapping

| Legacy Pattern | Package API |
| --- | --- |
| Find folder | `Mailbox::mailbox($addr)->folders()->find(...)` |
| List emails | `Mailbox::mailbox($addr)->messages()->...->get()` |
| Get email | `Mailbox::mailbox($addr)->message($id)->get()` |
| Download attachments | `Mailbox::mailbox($addr)->message($id)->downloadAttachments()` |
| Move message | `Mailbox::mailbox($addr)->message($id)->moveTo($folderId)` |

## Data Migration Notes

- keep connection records in `mailbox_connections`
- map watched addresses to `monitored_mailboxes`
- persist sync state in `monitored_folders.delta_token`

## Suggested Cutover Plan

1. deploy package + migrations
2. run connection and access checks
3. backfill monitored mailbox/folder records
4. switch ingestion jobs to package APIs
5. remove legacy direct-provider code after parity validation

## Next

- [Testing](testing.md)
- [Troubleshooting](troubleshooting.md)

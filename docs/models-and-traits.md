# Models and Traits

The package includes three core models:

- `MailboxConnection`
- `MonitoredMailbox`
- `MonitoredFolder`

`HasMailbox` can be added to application models that reference `monitored_mailbox_id`.

## Trait Helpers

- `mailboxResource()` returns a driver-scoped mailbox resource.
- Query scopes for mailbox and connection filtering are included.

## Relationships

- Connection has many monitored mailboxes.
- Mailbox has many monitored folders.
- Folder belongs to a monitored mailbox.

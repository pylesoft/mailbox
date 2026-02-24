# Models and Traits

The package includes three models and one integration trait.

## Models

- `MailboxConnection`
- `MonitoredMailbox`
- `MonitoredFolder`

## Relationships

- connection -> many mailboxes
- mailbox -> many folders
- folder -> belongs to mailbox

## `HasMailbox` Trait

Use this trait on your app model if it stores `monitored_mailbox_id`.

It provides:

- `mailboxResource()` for runtime operations
- `scopeForMailbox(...)`
- `scopeForConnection(...)`

## Practical Example

```php
class EmailInbox extends Model
{
    use \Pyle\Mailbox\Traits\HasMailbox;
}

$resource = EmailInbox::find($id)->mailboxResource();
```

## Next

- [Usage: Mailboxes](usage/mailboxes.md)
- [Usage: Delta Sync](usage/delta-sync.md)

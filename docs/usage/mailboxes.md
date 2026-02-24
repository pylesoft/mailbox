# Mailboxes

`MonitoredMailbox` links an email address to a connection.

## Typical Fields

- `mailbox_connection_id`
- `email_address`
- `display_name`
- `is_active`
- `last_synced_at`

## Scopes

- `active()`
- `forEmail($address)`
- `stale($minutes)`
- `neverSynced()`

## Runtime Usage

```php
use Pyle\Mailbox\Facades\Mailbox;

$resource = Mailbox::forMailbox($monitoredMailbox);
```

## Next

- [Messages](messages.md)
- [Delta Sync](delta-sync.md)

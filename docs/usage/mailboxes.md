# Mailboxes

`MonitoredMailbox` links a mailbox email address to a connection.

## Core Fields

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

## Bridge to Runtime APIs

```php
use Pyle\Mailbox\Facades\Mailbox;

$resource = Mailbox::forMailbox($monitoredMailbox);
```

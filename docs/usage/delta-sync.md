# Delta Sync

Delta sync gives incremental mailbox changes.

## Run Delta

```php
$result = Mailbox::mailbox($email)->folder('inbox')->delta($deltaToken);
```

`DeltaResultDto` includes:

- `created`
- `updated`
- `deleted`
- `deltaLink`
- `fullSyncRequired`

## Sync Loop Pattern

1. set folder status to syncing
2. call `delta($storedToken)`
3. process created/updated/deleted
4. persist new `deltaLink`
5. mark folder idle

## Expired Token

If `fullSyncRequired` is true, rerun with `null` token for a full re-baseline.

## Next

- [Messages](messages.md)
- [Troubleshooting](../troubleshooting.md)

# Connections

`MailboxConnection` stores driver credentials metadata and health state.

## Typical Lifecycle

1. create row for provider config
2. mark status pending/connected/error
3. link monitored mailboxes to this connection

## Useful Scopes

- `active()`
- `forDriver('ms-graph')`
- `withError()`
- `connectedSince($since)`

## Runtime Bridge

```php
$driver = $connection->resolveDriver();
```

## Next

- [Mailboxes](mailboxes.md)
- [Configuration](../configuration.md)

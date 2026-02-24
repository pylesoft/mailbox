# Connections

`MailboxConnection` stores provider connection metadata and state.

## Core Fields

- `name`
- `driver`
- `status`
- `config` (encrypted array cast)
- `last_connected_at`
- `last_error`

## Useful Scopes

- `active()`
- `forDriver('ms-graph')`
- `withError()`
- `connectedSince($since)`

## Driver Resolution

```php
$driver = $connection->resolveDriver();
```

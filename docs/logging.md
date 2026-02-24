# Logging

A dedicated `mailbox` channel is registered by the service provider if missing.

Default channel settings:

- `daily` driver
- `storage/logs/mailbox.log`
- 14-day retention
- Level derived from `mailbox.log_level` (falls back to debug when `app.debug=true`)

## Logged Operations

- Graph request lifecycle
- Retry and rate-limit handling
- Token operations
- Sync and attachment activity

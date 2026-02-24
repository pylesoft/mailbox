# Logging

A dedicated `mailbox` channel is registered if it does not exist.

## Default Channel

- driver: `daily`
- path: `storage/logs/mailbox.log`
- retention: 14 days
- level: `mailbox.log_level` (falls back to debug if `app.debug=true`)

## What Gets Logged

- Graph request lifecycle
- retry and backoff behavior
- rate-limit handling
- token acquisition and failures
- sync and attachment operations

## Operational Tip

Keep mailbox logs separate from app logs in production so mailbox incidents are easy to triage.

## Next

- [Events](events.md)
- [Testing](testing.md)

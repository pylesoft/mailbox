# Logging

A dedicated `mailbox` channel is registered if it does not exist.

## Default Channel

- driver: `daily`
- path: `storage/logs/mailbox.log`
- retention: 14 days
- level: `mailbox.log_level` (falls back to debug if `app.debug=true`)

## What Gets Logged

- Graph request lifecycle (method, endpoint, status, attempt, duration)
- retry and backoff behavior
- rate-limit incidents and queue-release retries
- token cache hits/misses, token acquisition, and refresh failures
- rate-limiter slot acquisition and release
- delta sync lifecycle and item-level change processing
- sync and attachment operations

## Debug vs Production

- `app.debug=true`: verbose debug logs for request internals, token cache behavior, lock slots, and delta item details.
- `app.debug=false`: info-level operational lifecycle logs (token refresh/acquire, sync completions, rate-limit incidents, failures).

## Operational Tip

Keep mailbox logs separate from app logs in production so mailbox incidents are easy to triage.

## Next

- [Events](events.md)
- [Testing](testing.md)

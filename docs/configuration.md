# Configuration

The package configuration is published to `config/mailbox.php`.

## Driver Selection

- `mailbox.default`: default driver name (`ms-graph` by default).
- `mailbox.drivers`: driver-specific configuration arrays.

## Microsoft Graph Settings

- `MS365_TENANT_ID`
- `MS365_CLIENT_ID`
- `MS365_CLIENT_SECRET`
- Optional timeout and API version settings.

## Runtime Behavior

- `token_refresh_buffer`: refresh token before expiry.
- `max_retries` + `retry_backoff_base`: retry behavior for transient failures.
- `queue_retry_strategy`: `release` (queue-friendly) or `sleep`.
- `prefer_immutable_ids`: enables immutable ID preference on Graph calls.

## Attachments

- `attachment_disk`: Laravel filesystem disk.
- `attachment_path`: base directory for downloaded attachments.

## Logging

- `log_channel`: package log channel name (defaults to `mailbox`).
- `log_level`: defaults based on `app.debug` unless explicitly set.

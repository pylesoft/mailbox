# Configuration

Package config lives in `config/mailbox.php`.

## Required Settings

- `mailbox.default`
- `mailbox.drivers.ms-graph.tenant_id`
- `mailbox.drivers.ms-graph.client_id`
- `mailbox.drivers.ms-graph.client_secret`

## Important Runtime Options

- `token_refresh_buffer`: refresh token before expiry
- `max_retries`: retry count for transient API failures
- `retry_backoff_base`: exponential backoff base
- `queue_retry_strategy`: `release` or `sleep`
- `prefer_immutable_ids`: use immutable Graph message IDs

## Attachment Storage

- `attachment_disk`: Laravel filesystem disk
- `attachment_path`: base folder path on disk

## Logging

- `log_channel`: defaults to `mailbox`
- `log_level`: defaults by `app.debug` if unset

## Example `.env`

```env
MAILBOX_DRIVER=ms-graph
MS365_TENANT_ID=...
MS365_CLIENT_ID=...
MS365_CLIENT_SECRET=...
MAILBOX_QUEUE_RETRY_STRATEGY=release
MAILBOX_ATTACHMENT_DISK=local
MAILBOX_ATTACHMENT_PATH=mailbox-attachments
```

## Next

- [Quickstart](quickstart.md)
- [Authentication](authentication/index.md)

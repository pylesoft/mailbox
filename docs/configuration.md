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

## User OAuth Options

- `oauth.enabled`: enables package OAuth routes (default `false`)
- `oauth.route_prefix`: route prefix for redirect/callback endpoints
- `oauth.route_middleware`: middleware list (comma-separated via `MAILBOX_OAUTH_ROUTE_MIDDLEWARE`)
- `oauth.state_ttl_seconds`: cache TTL for OAuth state
- `oauth.default_return_url`: fallback redirect target if no `return_to` was provided
- `oauth.ms_graph.redirect_uri`: optional explicit callback URL override
- `oauth.ms_graph.scopes`: delegated scopes used on the authorize request

## Attachment Storage

- `attachment_disk`: Laravel filesystem disk
- `attachment_path`: base folder path on disk
- dedup strategy: content-addressable (hash-based) with collision-safe hash suffix paths

## Logging

- `log_channel`: defaults to `mailbox`
- `log_level`: defaults by `app.debug` if unset

## Example `.env`

```env
MAILBOX_DRIVER=ms-graph
MS365_TENANT_ID=...
MS365_CLIENT_ID=...
MS365_CLIENT_SECRET=...
MAILBOX_OAUTH_ENABLED=false
MAILBOX_OAUTH_ROUTE_PREFIX=mailbox/oauth
MAILBOX_OAUTH_ROUTE_MIDDLEWARE=web
MAILBOX_OAUTH_MS_GRAPH_REDIRECT_URI=
MAILBOX_QUEUE_RETRY_STRATEGY=release
MAILBOX_ATTACHMENT_DISK=local
MAILBOX_ATTACHMENT_PATH=mailbox-attachments
```

## Next

- [Quickstart](quickstart.md)
- [Authentication](authentication/index.md)

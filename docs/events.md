# Events

The package emits events for observability and operational hooks.

## Connection Lifecycle

- `TokenAcquired`
- `TokenRefreshFailed`
- `SecretExpirationWarning`
- `ConnectionTestCompleted`

## API and Rate Limiting

- `RateLimitHit`
- `AccessDenied`
- `ApiError`

## Sync Lifecycle

- `DeltaSyncStarted`
- `DeltaSyncCompleted`
- `DeltaTokenExpired`

## Attachment Lifecycle

- `AttachmentDownloaded`
- `AttachmentSkipped`

## Example Listener Mapping

Use listeners for:

- alerting on auth failures
- rate-limit monitoring
- sync throughput metrics
- attachment processing analytics

## Next

- [Logging](logging.md)
- [Troubleshooting](troubleshooting.md)

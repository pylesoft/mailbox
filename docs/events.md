# Events

The package dispatches events for connection lifecycle, errors, sync, and attachments.

## Connection Events

- `TokenAcquired`
- `TokenRefreshFailed`
- `SecretExpirationWarning`
- `ConnectionTestCompleted`

## Runtime Events

- `RateLimitHit`
- `AccessDenied`
- `ApiError`
- `DeltaSyncStarted`
- `DeltaSyncCompleted`
- `DeltaTokenExpired`
- `AttachmentDownloaded`
- `AttachmentSkipped`

Consume these events in your application listeners for alerting and observability.

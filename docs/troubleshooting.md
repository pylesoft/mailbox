# Troubleshooting

## Authentication Failures

- Verify `MS365_TENANT_ID`, `MS365_CLIENT_ID`, `MS365_CLIENT_SECRET`.
- Rotate secret if expired.

## Access Denied (403)

- Verify Exchange Application Access Policy includes the mailbox.
- Allow policy propagation time.

## Rate Limiting (429)

- Reduce polling intensity and page size.
- Stagger sync jobs.
- Use `queue_retry_strategy=release` to avoid blocking workers.

## Delta Token Expired

- On `fullSyncRequired`, rerun folder sync with no delta token.

## Attachment Path/Storage Issues

- Confirm disk exists in `filesystems.php`.
- Verify write permissions and configured `attachment_path`.

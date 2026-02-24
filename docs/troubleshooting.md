# Troubleshooting

## `invalid_client`

- verify `MS365_CLIENT_ID` and `MS365_CLIENT_SECRET`
- rotate secret if expired

## `insufficient privileges`

- confirm Graph app permission (`Mail.ReadWrite` application)
- confirm admin consent granted

## `403 Access denied`

- mailbox is not allowed by Exchange app access policy
- verify policy scope and mailbox membership

## Frequent `429`

- reduce sync burst size and page size
- stagger jobs
- use `queue_retry_strategy=release`

## `fullSyncRequired=true`

- delta token expired
- rerun delta with `null` token and store new `deltaLink`

## Attachment Write Failures

- confirm filesystem disk exists
- confirm write permissions
- confirm configured `attachment_path`

## Next

- [Microsoft Graph Setup](authentication/ms-graph.md)
- [Configuration](configuration.md)

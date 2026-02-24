# Microsoft Graph Authentication

The MS Graph driver uses client credentials flow (application permissions).

## Required Graph Permission

- `Mail.ReadWrite` (application)

## Access Scope Control

Use Exchange Application Access Policies to restrict which mailboxes your app can access.

## Environment Variables

- `MS365_TENANT_ID`
- `MS365_CLIENT_ID`
- `MS365_CLIENT_SECRET`

## Token Lifecycle

- Tokens are cached.
- The driver refreshes before expiry.
- Authentication failures emit `TokenRefreshFailed` with actionable guidance.

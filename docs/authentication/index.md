# Authentication

## Choose a Provider

- [Microsoft Graph (App-Only)](ms-graph.md)
- [Microsoft Graph (User OAuth)](user-oauth.md)
- [Gmail (Service Account + User OAuth)](gmail.md)

## Authentication Modes

- Microsoft Graph app-only mode: client credentials.
- Gmail runtime mode: service account with domain-wide delegation.
- User OAuth mode is optional for both providers when you need user consent and refresh tokens.

## Token Storage

- App-only access tokens are cached.
- User OAuth tokens are persisted in `mailbox_oauth_tokens` and encrypted at rest.

## Next

- [Configuration](../configuration.md)
- [Quickstart](../quickstart.md)

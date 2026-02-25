# Authentication

## Choose a Provider

- [Microsoft Graph (App-Only)](ms-graph.md)
- [Microsoft Graph (User OAuth)](user-oauth.md)
- [Gmail (planned)](gmail.md)

## Authentication Modes

- App-only mode (client credentials) is the default path for queue and server jobs.
- User OAuth mode is optional and can be enabled when you need user consent and refresh tokens.
- You can run both in the same application: app-only for background processing, user OAuth for user-linked features.

## Token Storage

- App-only access tokens are cached.
- User OAuth tokens are persisted in `mailbox_oauth_tokens` and encrypted at rest.

## Next

- [Configuration](../configuration.md)
- [Quickstart](../quickstart.md)

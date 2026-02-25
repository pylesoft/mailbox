# Microsoft Graph User OAuth Guide

Use this flow when you want a user to sign in, approve access, and have their token stored in your database.

## When to Use This

- You need user-linked mailbox access.
- You need refresh tokens (`offline_access`) per user.
- You want redirect + callback UX in your app.

For server-only jobs without user interaction, keep using [MS Graph app-only auth](ms-graph.md).

## 1. Configure the Microsoft App

1. Open **Microsoft Entra admin center**.
2. Go to **Identity > Applications > App registrations > New registration**.
3. Create the app (single-tenant is typical for internal apps).
4. Open **Authentication** and add a **Web** redirect URI:
   - `https://your-app.test/mailbox/oauth/ms-graph/callback`
   - use your real domain in production
5. Open **API permissions** and add delegated Microsoft Graph scopes:
   - `openid`
   - `profile`
   - `email`
   - `User.Read` (required for sign-in/profile APIs used by the OAuth flow)
   - `offline_access`
   - `Mail.ReadWrite` (or stricter delegated scope if your use case allows)
   - `Mail.ReadWrite.Shared` (required if users must access shared mailboxes through delegated auth)
6. Click **Grant admin consent** if your tenant requires it.

## 2. Configure Laravel

```env
MAILBOX_DRIVER=ms-graph

MS365_TENANT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MS365_CLIENT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MS365_CLIENT_SECRET=your-secret-value

MAILBOX_OAUTH_ENABLED=true
MAILBOX_OAUTH_ROUTE_PREFIX=mailbox/oauth
MAILBOX_OAUTH_ROUTE_MIDDLEWARE=web,auth
MAILBOX_OAUTH_MS_GRAPH_REDIRECT_URI=https://your-app.test/mailbox/oauth/ms-graph/callback
```

Then publish and migrate:

```bash
php artisan vendor:publish --tag=mailbox-config
php artisan vendor:publish --tag=mailbox-migrations
php artisan migrate
```

## 3. Routes Provided by the Package

- `GET /mailbox/oauth/ms-graph/redirect`
- `GET /mailbox/oauth/ms-graph/callback`

Named routes:

- `mailbox.oauth.ms-graph.redirect`
- `mailbox.oauth.ms-graph.callback`

## 4. Redirect the User

Use a normal link or redirect to the package endpoint:

```php
return redirect()->route('mailbox.oauth.ms-graph.redirect', [
    'mailbox_connection_id' => $connection->id, // optional
    'return_to' => route('settings.mailbox'),   // optional
    'user_reference' => (string) auth()->id(),  // optional
]);
```

## 5. Callback Behavior

On success:

- Token is upserted into `mailbox_oauth_tokens` (`provider = ms-graph-user`).
- `access_token` and `refresh_token` are encrypted via Eloquent casts.
- If `return_to` was provided, user is redirected back with query params:
  - `mailbox_oauth=success`
  - `mailbox_oauth_token_id={id}`
  - `user_reference={value}` (if present)

On failure:

- If `return_to` is available from state, user is redirected back with:
  - `mailbox_oauth=error`
  - `mailbox_oauth_error={code}`
  - `mailbox_oauth_error_description={message}`
- Otherwise a JSON error response is returned.

## 6. Token Model

`Pyle\Mailbox\Models\MailboxOAuthToken` fields include:

- `mailbox_connection_id` (nullable)
- `provider`
- `external_user_id`
- `email`
- `tenant_id`
- `access_token` (encrypted)
- `refresh_token` (encrypted)
- `expires_at`
- `revoked_at`
- `meta`

## Security Notes

- Keep `web` middleware and CSRF/session protections enabled on OAuth routes.
- Add auth middleware on redirect route (`MAILBOX_OAUTH_ROUTE_MIDDLEWARE=web,auth`) for user-scoped flows.
- Restrict scopes to minimum required permissions.
- Rotate client secrets and monitor expiration.

## Next

- [Configuration](../configuration.md)
- [Troubleshooting](../troubleshooting.md)

# User OAuth (Browser Sign-In)

Sometimes your application needs individual users to sign in through a browser, grant consent to their mailbox, and have Mailbox store a per-user refresh token in your database. This is the **user OAuth** flow — a redirect-based authorization code grant that works with both Microsoft Graph and Gmail. It complements the app-only authentication modes by letting you connect mailboxes that belong to external users, personal accounts, or organizations where you do not have admin access.

If your application only needs server-side access to mailboxes you control (your own tenant or Workspace domain), the app-only flows in [Microsoft Graph (App-Only)](ms-graph.md) and [Gmail (Service Account)](gmail.md) are simpler and do not require any browser interaction.

## When to Use User OAuth vs App-Only Auth

Choosing the right authentication mode depends on who owns the mailbox and how your application is deployed. Use this table as a guide:

| Scenario | Recommended Mode | Why |
|---|---|---|
| Your app reads mailboxes in your own Microsoft 365 tenant | [MS Graph App-Only](ms-graph.md) | No user interaction needed; one credential covers all mailboxes |
| Your app reads mailboxes in your own Google Workspace domain | [Gmail Service Account](gmail.md) | Domain-wide delegation; no user interaction needed |
| Users connect their own Microsoft 365 or personal Outlook mailbox | **MS Graph User OAuth** (this page) | User must consent; you store their refresh token |
| Users connect their own Gmail or Google Workspace mailbox | **Gmail User OAuth** (this page) | User must consent; you store their refresh token |
| SaaS app where customers connect mailboxes from any provider | **User OAuth** (this page) | You cannot get admin access to every customer's tenant/domain |
| Background jobs processing mail without any user present | App-Only ([MS Graph](ms-graph.md) or [Gmail](gmail.md)) | No browser available for redirect flows |

> **Tip** You can use both modes in the same application. For example, use app-only credentials for your internal `invoices@acme.com` mailbox and user OAuth for customer-connected mailboxes.

## How the Flow Works

Regardless of whether you connect a Microsoft or Google mailbox, the user OAuth flow follows the same pattern:

1. Your application redirects the user to the provider's authorization page.
2. The user signs in and consents to the requested permissions.
3. The provider redirects back to your application's callback URL with an authorization code.
4. Mailbox exchanges the code for an access token and refresh token.
5. Both tokens are encrypted and stored in the `mailbox_oauth_tokens` table.
6. The user is redirected back to your application with success/failure query parameters.

Mailbox handles steps 3-6 automatically. You only need to initiate the redirect (step 1) and handle the final redirect back to your UI (step 6).

## Prerequisites

Before configuring either provider, publish the Mailbox migrations and run them. The user OAuth flow stores tokens in the `mailbox_oauth_tokens` table:

```bash
php artisan vendor:publish --tag=mailbox-migrations
php artisan migrate
```

Enable the OAuth routes in your `.env`:

```env
MAILBOX_OAUTH_ENABLED=true
MAILBOX_OAUTH_ROUTE_PREFIX=mailbox/oauth
MAILBOX_OAUTH_ROUTE_MIDDLEWARE=web,auth
```

> **Warning** Always include `auth` in `MAILBOX_OAUTH_ROUTE_MIDDLEWARE` for user-scoped flows. Without it, unauthenticated visitors could initiate OAuth flows on behalf of your users.

## Microsoft Graph User OAuth

### Configure the Entra App

If you already have an Entra app registration from the [app-only setup](ms-graph.md), you can reuse it. Otherwise, create a new one:

1. Open the [Microsoft Entra admin center](https://entra.microsoft.com/).
2. Navigate to **Identity > Applications > App registrations > New registration**.
3. Name the app (example: `Acme Mailbox Production`).
4. Supported account type: choose your tenant option (single-tenant for internal, multi-tenant for SaaS).
5. Under **Redirect URI**, select **Web** and enter your callback URL:
   ```
   https://acme.com/mailbox/oauth/ms-graph/callback
   ```
6. Click **Register**.

### Add Delegated Permissions

Go to **API permissions** and add the following **Delegated** permissions for Microsoft Graph:

- `openid`
- `profile`
- `email`
- `User.Read`
- `offline_access` (required for refresh tokens)
- `Mail.ReadWrite`
- `Mail.ReadWrite.Shared` (if users need to access shared mailboxes)

Click **Grant admin consent** if your tenant requires it.

> **Note** For multi-tenant apps (SaaS), each customer's admin grants consent when their first user signs in. You do not need to pre-consent across tenants.

### Add Environment Variables

```env
MAILBOX_DRIVER=ms-graph

MS365_TENANT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MS365_CLIENT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MS365_CLIENT_SECRET=your-secret-value

MAILBOX_OAUTH_ENABLED=true
MAILBOX_OAUTH_ROUTE_PREFIX=mailbox/oauth
MAILBOX_OAUTH_ROUTE_MIDDLEWARE=web,auth
MAILBOX_OAUTH_MS_GRAPH_REDIRECT_URI=https://acme.com/mailbox/oauth/ms-graph/callback
```

> **Tip** The `MAILBOX_OAUTH_MS_GRAPH_REDIRECT_URI` is optional. If omitted, Mailbox generates the callback URL from the named route automatically. Set it explicitly when your public URL differs from what `route()` generates (e.g., behind a load balancer or reverse proxy).

### Routes

Mailbox registers these routes automatically when `MAILBOX_OAUTH_ENABLED=true`:

| Method | URI | Named Route |
|---|---|---|
| `GET` | `/mailbox/oauth/ms-graph/redirect` | `mailbox.oauth.ms-graph.redirect` |
| `GET` | `/mailbox/oauth/ms-graph/callback` | `mailbox.oauth.ms-graph.callback` |

The prefix is configurable via `MAILBOX_OAUTH_ROUTE_PREFIX`.

### Redirect the User

Send the user to the redirect endpoint when they click "Connect Microsoft Mailbox" in your UI:

```php
use Illuminate\Support\Facades\Route;

return redirect()->route('mailbox.oauth.ms-graph.redirect', [
    'mailbox_connection_id' => $connection->id,   // optional: link token to a connection
    'return_to' => route('settings.mailbox'),      // optional: where to send the user after
    'user_reference' => (string) auth()->id(),     // optional: your internal user identifier
]);
```

All three query parameters are optional. Mailbox stores them in a short-lived cache entry (default: 10 minutes) and includes them in the callback redirect.

### Handle the Callback

On success, Mailbox redirects the user back to your `return_to` URL with these query parameters:

```
?mailbox_oauth=success&mailbox_oauth_token_id=42&user_reference=7
```

On failure:

```
?mailbox_oauth=error&mailbox_oauth_error=access_denied&mailbox_oauth_error_description=User+declined+consent
```

Here is how you might handle the response in your settings controller:

```php
use Pyle\Mailbox\Models\MailboxOAuthToken;

public function mailboxSettings(Request $request)
{
    if ($request->query('mailbox_oauth') === 'success') {
        $token = MailboxOAuthToken::find($request->query('mailbox_oauth_token_id'));

        session()->flash('status', "Connected {$token->email} successfully.");
    }

    if ($request->query('mailbox_oauth') === 'error') {
        session()->flash('error', $request->query('mailbox_oauth_error_description', 'Connection failed.'));
    }

    return view('settings.mailbox');
}
```

## Gmail User OAuth

### Create Google OAuth Credentials

1. Open the [Google Cloud Console](https://console.cloud.google.com/).
2. Select your project (or create a new one).
3. Navigate to **APIs & Services > Credentials**.
4. Click **Create Credentials > OAuth client ID**.
5. Application type: **Web application**.
6. Name it (example: `Acme Mailbox OAuth`).
7. Under **Authorized redirect URIs**, add:
   ```
   https://acme.com/mailbox/oauth/gmail/callback
   ```
8. Click **Create**.
9. Copy the **Client ID** and **Client secret**.

> **Note** These are separate credentials from the service account used for app-only Gmail access. The OAuth client ID is for the browser redirect flow; the service account is for server-to-server delegation.

### Configure the Consent Screen

If you have not configured the OAuth consent screen for this project yet:

1. Go to **APIs & Services > OAuth consent screen**.
2. Select **External** (for any Google account) or **Internal** (for your Workspace domain only).
3. Fill in the required fields: app name, user support email, and developer contact.
4. Add scopes: `openid`, `profile`, `email`, and `https://www.googleapis.com/auth/gmail.modify`.
5. Save and continue.

> **Warning** External apps start in "Testing" mode and can only be used by test users you explicitly add. To allow any Google user to connect, you must submit your app for verification. This process can take several weeks.

### Add Environment Variables

```env
MAILBOX_DRIVER=gmail

MAILBOX_OAUTH_ENABLED=true
MAILBOX_OAUTH_ROUTE_PREFIX=mailbox/oauth
MAILBOX_OAUTH_ROUTE_MIDDLEWARE=web,auth

MAILBOX_OAUTH_GMAIL_CLIENT_ID=123456789-abcdefg.apps.googleusercontent.com
MAILBOX_OAUTH_GMAIL_CLIENT_SECRET=GOCSPX-your-secret-value
MAILBOX_OAUTH_GMAIL_REDIRECT_URI=https://acme.com/mailbox/oauth/gmail/callback
```

> **Tip** Like the MS Graph redirect URI, `MAILBOX_OAUTH_GMAIL_REDIRECT_URI` is optional when your public URL matches what `route()` generates.

### Routes

Mailbox registers these routes automatically when `MAILBOX_OAUTH_ENABLED=true`:

| Method | URI | Named Route |
|---|---|---|
| `GET` | `/mailbox/oauth/gmail/redirect` | `mailbox.oauth.gmail.redirect` |
| `GET` | `/mailbox/oauth/gmail/callback` | `mailbox.oauth.gmail.callback` |

### Redirect the User

The redirect works identically to the Microsoft flow:

```php
return redirect()->route('mailbox.oauth.gmail.redirect', [
    'mailbox_connection_id' => $connection->id,
    'return_to' => route('settings.mailbox'),
    'user_reference' => (string) auth()->id(),
]);
```

### Handle the Callback

The callback query parameters and behavior are identical to the Microsoft flow described above. Your `return_to` URL receives `mailbox_oauth=success` or `mailbox_oauth=error` with the same parameter names.

> **Tip** Since both providers use the same callback parameter format, you can handle Microsoft and Gmail callbacks with a single controller method.

## Token Refresh Lifecycle

After a user completes the OAuth flow, Mailbox stores the access token and refresh token in the `mailbox_oauth_tokens` table. Both are encrypted at rest using Laravel's `encrypted` cast.

### How Token Refresh Works

Access tokens are short-lived (typically 1 hour). When a token expires, Mailbox uses the stored refresh token to obtain a new access token without any user interaction:

1. Mailbox checks `expires_at` on the `MailboxOAuthToken` model before making an API call.
2. If the token has expired (or is within the refresh buffer window), Mailbox sends the `refresh_token` to the provider's token endpoint.
3. The provider returns a new `access_token` (and sometimes a new `refresh_token`).
4. Mailbox updates the `mailbox_oauth_tokens` row with the fresh values and the new `expires_at` timestamp.

This happens transparently — your application code does not need to handle token refresh.

### When Refresh Tokens Expire

Refresh tokens can be revoked or expire under certain conditions:

| Provider | Refresh Token Lifetime | Revocation Triggers |
|---|---|---|
| Microsoft Graph | 90 days of inactivity (configurable by tenant policy) | User changes password, admin revokes consent, token unused for 90 days |
| Gmail | Indefinite (with caveats) | User revokes access in Google Account settings, app is unverified and token is older than 7 days, Google detects suspicious activity |

When a refresh token becomes invalid, Mailbox cannot silently re-authenticate. Your application should handle this gracefully:

```php
use Pyle\Mailbox\Models\MailboxOAuthToken;

$token = MailboxOAuthToken::where('email', 'invoices@acme.com')
    ->active()
    ->first();

if ($token === null || $token->revoked_at !== null) {
    // Prompt the user to reconnect their mailbox
    return redirect()->route('settings.mailbox')
        ->with('warning', 'Your mailbox connection has expired. Please reconnect.');
}
```

> **Tip** Query `MailboxOAuthToken::expiringSoon(300)` to find tokens that will expire within 5 minutes. You can use this in a scheduled command to proactively refresh tokens or notify users.

## The Token Model

Mailbox stores user OAuth tokens in the `mailbox_oauth_tokens` table using the `MailboxOAuthToken` Eloquent model:

```php
use Pyle\Mailbox\Models\MailboxOAuthToken;
```

### Key Fields

| Column | Type | Description |
|---|---|---|
| `mailbox_connection_id` | `int\|null` | Optional link to a `MailboxConnection` model |
| `provider` | `string` | `ms-graph-user` or `gmail-user` |
| `external_user_id` | `string\|null` | The provider's unique user ID (Entra OID or Google sub) |
| `email` | `string\|null` | The email address of the connected mailbox |
| `tenant_id` | `string\|null` | Microsoft tenant ID (null for Gmail) |
| `access_token` | `encrypted` | Current access token |
| `refresh_token` | `encrypted` | Long-lived refresh token |
| `token_type` | `string` | Typically `Bearer` |
| `scopes` | `array` | The scopes granted by the user |
| `expires_at` | `datetime` | When the current access token expires |
| `last_refreshed_at` | `datetime` | When the token was last refreshed |
| `revoked_at` | `datetime\|null` | Set when the token is revoked; null while active |
| `meta` | `array\|null` | Additional data (display name, raw token fields) |

### Useful Scopes

```php
// Find all active MS Graph user tokens
$tokens = MailboxOAuthToken::provider('ms-graph-user')
    ->active()
    ->get(); // Collection<int, MailboxOAuthToken>

// Find tokens expiring within 5 minutes
$expiring = MailboxOAuthToken::expiringSoon(300)->get();

// Find the token for a specific email
$token = MailboxOAuthToken::where('email', 'invoices@acme.com')
    ->active()
    ->first();
```

For more details on Mailbox's Eloquent models, see [Eloquent Models](../eloquent-models.md).

## Security Best Practices

### Protect the OAuth Routes

Always apply `auth` middleware to the OAuth routes so only authenticated users can initiate the flow:

```env
MAILBOX_OAUTH_ROUTE_MIDDLEWARE=web,auth
```

Mailbox also validates the `return_to` URL against an allowlist of hosts configured in `MAILBOX_OAUTH_ALLOWED_RETURN_HOSTS`. By default, this is set to your `APP_URL` host. If your application runs on multiple domains, add them:

```env
MAILBOX_OAUTH_ALLOWED_RETURN_HOSTS=acme.com,app.acme.com
```

### Encrypt Tokens at Rest

Mailbox encrypts `access_token` and `refresh_token` using Laravel's `encrypted` Eloquent cast. Make sure your `APP_KEY` is set and that you have a backup rotation strategy. If `APP_KEY` changes without re-encrypting stored tokens, all existing OAuth tokens become unreadable.

### Use HTTPS in Production

OAuth redirect URIs must use HTTPS in production. Both Microsoft and Google reject HTTP callback URLs for web applications (with limited exceptions for `localhost` during development).

### Restrict Scopes to Minimum Required

Only request the permissions your application needs. For read-only mailbox access, use `Mail.Read` (Microsoft) or `gmail.readonly` (Google) instead of the broader read-write scopes.

### Monitor Token Health

Use the `expiringSoon` scope and `revoked_at` column to build monitoring:

```php
// In a scheduled command
$troubled = MailboxOAuthToken::active()
    ->where(function ($query) {
        $query->expiringSoon(300)
              ->orWhereNotNull('revoked_at');
    })
    ->get();

foreach ($troubled as $token) {
    // Notify the user or your ops team
}
```

## Troubleshooting

### `OAuth state is missing or expired. Restart the sign-in flow.`

The user took too long to complete the sign-in, or their browser blocked cookies needed for state tracking.

- The state cache entry expires after 10 minutes (configurable via `mailbox.oauth.state_ttl_seconds`).
- Ensure your cache driver is working correctly (not `array` in production).
- Verify the user's browser accepts cookies from your domain.

### `Failed to exchange OAuth code for token`

The authorization code could not be exchanged for tokens. Common causes:

- **Redirect URI mismatch**: The `MAILBOX_OAUTH_MS_GRAPH_REDIRECT_URI` or `MAILBOX_OAUTH_GMAIL_REDIRECT_URI` must exactly match what is registered with the provider, including trailing slashes and protocol.
- **Expired code**: Authorization codes are single-use and expire quickly (typically 10 minutes). If the user's browser is slow or the callback request is delayed, the code may have expired.
- **Wrong credentials**: Verify the client ID and secret match the provider's app registration.

### `access_denied` Error from Microsoft

The user declined the consent prompt, or a tenant admin has restricted third-party app consent.

- Check the tenant's **Enterprise applications > Consent and permissions** settings in Entra.
- For managed tenants, an admin may need to grant consent on behalf of users.

### `access_denied` Error from Google

The user declined the consent prompt, or the app is in "Testing" mode and the user is not on the test users list.

- Go to **APIs & Services > OAuth consent screen** in the Cloud Console.
- Add the user's email to the test users list, or submit the app for verification.

### No `refresh_token` in the Response

For Microsoft: Ensure `offline_access` is in your configured scopes.

For Gmail: Google only issues a refresh token on the first consent. If the user has previously authorized your app, Google may skip the refresh token. Mailbox requests `prompt=consent` and `access_type=offline` to ensure a refresh token is always included, but if you still do not receive one:

- Have the user revoke your app's access at [myaccount.google.com/permissions](https://myaccount.google.com/permissions), then re-authorize.

### Tokens Become Invalid After `APP_KEY` Rotation

Because `access_token` and `refresh_token` are encrypted with Laravel's `APP_KEY`, rotating the key without re-encrypting existing tokens makes them unreadable. If this happens, affected users will need to reconnect their mailboxes through the OAuth flow again.

## What's Next

- [Microsoft Graph (App-Only)](ms-graph.md) -- client credentials setup for server-side Microsoft 365 access.
- [Gmail (Google Workspace)](gmail.md) -- service account setup for Google Workspace mailboxes.
- [Eloquent Models](../eloquent-models.md) -- working with `MailboxOAuthToken` and other Mailbox models.

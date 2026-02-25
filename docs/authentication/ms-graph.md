# Microsoft Graph (App-Only) Authentication

Mailbox connects to Microsoft 365 mailboxes through the OAuth 2.0 **client credentials flow** — the recommended path for server-side and background workloads where your application reads, modifies, and organizes mail without any interactive user sign-in. You register an app in Microsoft Entra, grant it the right permissions, and Mailbox handles token acquisition and caching automatically at runtime.

If you need a browser-based sign-in flow where individual users grant consent and Mailbox stores per-user refresh tokens, see [User OAuth](user-oauth.md) instead.

## What You Need

- A Microsoft 365 tenant with Exchange Online mailboxes.
- Azure / Entra admin access to register an application.
- Exchange admin access to scope mailbox access (recommended).

## Which Values You Need for `.env`

- `MS365_TENANT_ID`
- `MS365_CLIENT_ID`
- `MS365_CLIENT_SECRET`

Mailbox obtains and caches access tokens automatically at runtime. There is no manual token management required.

## Step 1: Register the Application in Entra

1. Open the [Microsoft Entra admin center](https://entra.microsoft.com/).
2. Navigate to **Identity > Applications > App registrations > New registration**.
3. Name your app (example: `Acme Mailbox Production`).
4. Supported account type: choose your tenant option (single-tenant is typical for internal apps).
5. Redirect URI: leave blank for client credentials — it is not required in this mode.
6. Click **Register**.

After creation, copy two values from the app overview page:

- **Application (client) ID** -- this becomes `MS365_CLIENT_ID`
- **Directory (tenant) ID** -- this becomes `MS365_TENANT_ID`

> **Tip** Bookmark the app's overview page. You will return here several times during setup.

## Step 2: Create a Client Secret

1. In the app registration, go to **Certificates & secrets**.
2. Click **New client secret**.
3. Enter a description and choose an expiration period.
4. Click **Add**.
5. Copy the **Value** column immediately.

> **Warning** The secret value is only shown once. If you navigate away before copying it, you will need to create a new secret. Copy the **Value**, not the **Secret ID**.

Use that value as `MS365_CLIENT_SECRET` in your environment.

> **Tip** Set a calendar reminder to rotate your client secret before it expires. Mailbox dispatches a `SecretExpirationWarning` event when the secret is within the configured warning window (default: 30 days). Listen for this event to send Slack alerts or emails automatically. See [Events](../events.md) for details.

## Step 3: Add API Permissions

1. In the app registration, go to **API permissions**.
2. Click **Add a permission > Microsoft Graph > Application permissions**.
3. Add the following permissions:
   - `Mail.ReadWrite` (required for mailbox read/write operations)
   - `User.Read.All` (recommended for tenant-wide user/directory reads and legacy `/users` probes)
4. Click **Grant admin consent for [your tenant]**.

> **Warning** Without admin consent, token requests may succeed but every mailbox API call will fail with `403 Forbidden`. This is the most common setup oversight.

If you only call mailbox endpoints and do not need directory or user listing, you can omit `User.Read.All`. The health check will still pass by probing the Graph service root instead.

### Sharing One App for Both Flows

If you use one Entra app registration for both app-only and [user OAuth](user-oauth.md) flows, also add these **Delegated** permissions:

- `openid`
- `profile`
- `email`
- `User.Read`
- `offline_access`
- `Mail.ReadWrite`
- `Mail.ReadWrite.Shared` (for shared mailbox delegated access)

## Step 4: Restrict Mailbox Access (Recommended)

By default, application permissions grant access to every mailbox in your tenant. You should restrict the app to only the mailboxes it needs.

> **Warning** Skipping this step means your application can read every mailbox in the organization. Always scope access in production.

### Exchange Application Access Policy

Connect to Exchange Online PowerShell and create a policy:

```powershell
Connect-ExchangeOnline

# Create a mail-enabled security group to serve as the policy scope
New-DistributionGroup -Name "PyleMailboxAccess" -Type Security

# Add only the mailboxes your application needs
Add-DistributionGroupMember -Identity "PyleMailboxAccess" -Member "invoices@acme.com"
Add-DistributionGroupMember -Identity "PyleMailboxAccess" -Member "billing@acme.com"

# Restrict your app to that group
New-ApplicationAccessPolicy `
  -AppId "<MS365_CLIENT_ID>" `
  -PolicyScopeGroupId "PyleMailboxAccess@acme.com" `
  -AccessRight RestrictAccess `
  -Description "Restrict Pyle Mailbox app to approved mailboxes"

# Validate the policy for a specific mailbox
Test-ApplicationAccessPolicy `
  -AppId "<MS365_CLIENT_ID>" `
  -Identity "invoices@acme.com"
```

> **Note** Application Access Policies can take up to 30 minutes to propagate. If `Test-ApplicationAccessPolicy` returns "Denied" immediately after creation, wait and retry.

If your tenant uses newer Exchange authorization models (e.g., RBAC for Applications), use the equivalent scoping mechanism there. The requirement is the same: only grant access to the mailboxes your application needs.

## Step 5: Configure Laravel Environment

Add the following to your `.env` file:

```env
MAILBOX_DRIVER=ms-graph

MS365_TENANT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MS365_CLIENT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MS365_CLIENT_SECRET=your-secret-value
```

### Optional Runtime Tuning

```env
MAILBOX_CACHE_STORE=redis
MAILBOX_ATTACHMENT_DISK=local
MAILBOX_ATTACHMENT_PATH=mailbox-attachments
MAILBOX_LOG_LEVEL=info
MAILBOX_QUEUE_RETRY_STRATEGY=release
```

For a complete reference of every available configuration key, see [Configuration](../configuration.md).

## Step 6: Verify the Connection

Run the built-in test commands to confirm that authentication and mailbox access are working end-to-end:

```bash
php artisan mailbox:test-access invoices@acme.com --driver=ms-graph
```

Expected output on success:

```
 Connected successfully (245ms)
 Access to invoices@acme.com: Granted
```

Then run the health check:

```bash
php artisan mailbox:health --driver=ms-graph
```

Expected output:

```
 Token: Valid (expires in 3312s)
 API: Reachable (134ms)
 Status: Healthy
```

If either command fails, jump to [Troubleshooting](#troubleshooting) below.

## How Client Credentials Tokens Work

Understanding the token flow helps when debugging authentication issues. Here is what happens under the hood:

1. **Request a token.** The `TokenManager` sends a `POST` to the Entra token endpoint (`https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`) with the client ID, client secret, and the `https://graph.microsoft.com/.default` scope.

2. **Cache the token.** Mailbox caches the access token using Laravel's cache system. The default token lifetime is 3600 seconds (1 hour), and Mailbox subtracts a configurable buffer (default 300 seconds) to refresh proactively before expiry.

3. **Make API calls.** Every Graph request includes the token as a `Bearer` header.

4. **Handle expiry.** On `401` responses, Mailbox invalidates the cached token and re-authenticates once before throwing an exception. There is no refresh token in client credentials mode — Mailbox simply requests a new access token.

> **Tip** You can customize the cache store and refresh buffer in `config/mailbox.php` via the `cache_store` and `token_refresh_buffer` keys.

## Optional: Manually Request a Token (Debug)

If you need to verify your credentials outside of Laravel, you can request a token directly with `curl`:

```bash
curl -sS -X POST "https://login.microsoftonline.com/${MS365_TENANT_ID}/oauth2/v2.0/token" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "client_id=${MS365_CLIENT_ID}" \
  -d "client_secret=${MS365_CLIENT_SECRET}" \
  -d "scope=https://graph.microsoft.com/.default" \
  -d "grant_type=client_credentials"
```

A successful response returns a JSON object with `access_token`, `expires_in`, and `token_type`. If this fails, the issue is in your Entra configuration — not in Mailbox.

## Security Best Practices

### Store Secrets Securely

Never commit `MS365_CLIENT_SECRET` to version control. Use your deployment platform's secrets manager (Vault, AWS Secrets Manager, Laravel Forge environment variables, etc.) to inject the value at runtime.

### Rotate Client Secrets Before Expiry

Client secrets in Entra have a configurable expiration (default: 24 months). Create a rotation schedule:

1. Generate a new secret in **Certificates & secrets**.
2. Update `MS365_CLIENT_SECRET` in your environment.
3. Run `php artisan mailbox:test-access` to verify.
4. Delete the old secret from Entra.

> **Tip** Consider using a **certificate** instead of a client secret for even stronger security. Certificates eliminate the risk of secret leakage in logs or environment dumps.

### Keep Permissions Minimal

Only request the Graph permissions your application actually uses. If you only read and move mail, `Mail.ReadWrite` is sufficient. Avoid `Mail.ReadWrite` + `User.Read.All` unless you genuinely need directory queries.

### Restrict Mailbox Scope

Always configure an Application Access Policy (Step 4) in production. Without it, a compromised credential grants access to every mailbox in your organization.

### Monitor Token Events

Mailbox dispatches events at key moments in the authentication lifecycle:

- `TokenAcquired` — a new access token was obtained
- `TokenRefreshFailed` — token acquisition failed (alert on this)
- `SecretExpirationWarning` — the client secret is nearing expiry

Listen for these events to build proactive alerting. See [Events](../events.md) for details.

## Troubleshooting

### `invalid_client`

The client ID or secret is wrong, or the secret has expired.

- Verify `MS365_CLIENT_ID` matches the **Application (client) ID** on the Entra app overview page.
- Verify `MS365_CLIENT_SECRET` is the secret **Value** (not the Secret ID).
- Check the secret's expiration date in **Certificates & secrets**. If expired, create a new one.

### `invalid_grant` or `AADSTS700016`

The tenant ID does not match, or the app registration does not exist in the specified tenant.

- Verify `MS365_TENANT_ID` matches the **Directory (tenant) ID** on the Entra app overview page.
- If using a multi-tenant app, ensure the app has been consented in the target tenant.

### `insufficient privileges` or `Authorization_RequestDenied`

The app has the right permissions listed, but admin consent has not been granted.

- Go to **API permissions** in the app registration and click **Grant admin consent**.
- Wait a few minutes for consent to propagate, then retry.

### `403 Access Denied` When Accessing a Mailbox

Authentication succeeds (you get a token), but the mailbox API call is rejected.

- If you configured an Application Access Policy, verify the target mailbox is a member of the security group.
- Run `Test-ApplicationAccessPolicy` in Exchange PowerShell to confirm.
- Remember that policy propagation can take up to 30 minutes.

### `403 MailboxNotEnabledForRESTAPI`

The target mailbox is not hosted on Exchange Online, or it is an on-premises mailbox in a hybrid environment.

- Verify the mailbox is a full Exchange Online mailbox (not on-premises or a shared mailbox without a license).

### Intermittent `429 Too Many Requests`

Microsoft Graph enforces per-app and per-mailbox throttling limits. Mailbox handles `429` responses automatically with retry logic. If you consistently hit limits:

- Spread requests across mailboxes rather than targeting a single one.
- Use [delta sync](../delta-sync.md) to reduce the number of full-list calls.
- Set `MAILBOX_QUEUE_RETRY_STRATEGY=release` to release queue jobs back for later processing instead of blocking workers with inline retries.

### `401 Unauthorized` After Previously Working

The cached token may have been invalidated server-side. Mailbox automatically retries once after clearing its token cache. If the error persists:

- Verify the client secret has not been rotated or deleted.
- Check the Entra app registration still has the correct permissions and admin consent.
- Clear the Mailbox token cache: run `php artisan cache:forget mailbox_token:{tenant}:{client}` or flush the configured cache store.

## What's Next

- [User OAuth](user-oauth.md) -- browser-based sign-in with per-user refresh tokens for MS Graph and Gmail.
- [Gmail (Google Workspace) Authentication](gmail.md) -- service account setup for Google Workspace mailboxes.
- [Configuration Reference](../configuration.md) -- all configuration keys, cache tuning, and runtime options.

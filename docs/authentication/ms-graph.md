# Microsoft Graph Setup Guide (Client Credentials)

This package uses OAuth 2.0 **client credentials flow** for Microsoft Graph.

This guide covers the app-only path for server/background workloads.

If you need user consent redirects and per-user refresh tokens, use [Microsoft Graph User OAuth](user-oauth.md).

## What You Need

- A Microsoft 365 tenant with Exchange Online mailboxes.
- Azure/Entra admin access to register an app.
- Exchange admin access to scope mailbox access.

## Which Values You Need for `.env`

- `MS365_TENANT_ID`
- `MS365_CLIENT_ID`
- `MS365_CLIENT_SECRET`

The package will obtain and cache access tokens automatically at runtime.

## Step 1: Register the Microsoft App

1. Open Microsoft Entra admin center.
2. Go to **Identity > Applications > App registrations > New registration**.
3. Name your app (example: `Pyle Mailbox Production`).
4. Supported account type: choose your tenant option.
5. Redirect URI: optional for this mode (not required for client credentials).
6. Create the app.

After creation, copy:

- **Application (client) ID** -> `MS365_CLIENT_ID`
- **Directory (tenant) ID** -> `MS365_TENANT_ID`

## Step 2: Create a Client Secret

1. In the app, go to **Certificates & secrets**.
2. Create **New client secret**.
3. Copy the **Value** immediately (not the Secret ID).

Use that value as:

- `MS365_CLIENT_SECRET`

## Step 3: Add API Permissions

1. In the app, go to **API permissions**.
2. Add Microsoft Graph **Application** permissions:
   - `Mail.ReadWrite` (required for mailbox read/write operations)
   - `User.Read.All` (recommended for tenant-wide user/directory reads and legacy `/users` probes)
3. Click **Grant admin consent**.

Without admin consent, token requests may succeed but mailbox calls will fail.

If you only call mailbox endpoints and do not need directory/user listing, you can run without `User.Read.All`.

If you use one Entra app for both app-only and user OAuth flows, also add these **Delegated** permissions:

- `openid`
- `profile`
- `email`
- `User.Read`
- `offline_access`
- `Mail.ReadWrite`
- `Mail.ReadWrite.Shared` (for shared mailbox delegated access)

## Step 4: Restrict Mailbox Access (Recommended)

By default, app permissions can target broad tenant scope. Restrict access to specific mailboxes.

### Exchange Application Access Policy (Common setup)

```powershell
Connect-ExchangeOnline

# Create a security group used as policy scope
New-DistributionGroup -Name "PyleMailboxAccess" -Type Security

# Add only approved mailboxes
Add-DistributionGroupMember -Identity "PyleMailboxAccess" -Member "invoices@yourdomain.com"
Add-DistributionGroupMember -Identity "PyleMailboxAccess" -Member "billing@yourdomain.com"

# Restrict app to that group
New-ApplicationAccessPolicy \
  -AppId "<MS365_CLIENT_ID>" \
  -PolicyScopeGroupId "PyleMailboxAccess@yourdomain.com" \
  -AccessRight RestrictAccess \
  -Description "Restrict mailbox app access"

# Validate policy for a mailbox
Test-ApplicationAccessPolicy \
  -AppId "<MS365_CLIENT_ID>" \
  -Identity "invoices@yourdomain.com"
```

If your tenant uses newer Exchange authorization models, use equivalent app scoping there. The requirement is the same: only grant access to intended mailboxes.

## Step 5: Configure Laravel Environment

```env
MAILBOX_DRIVER=ms-graph

MS365_TENANT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MS365_CLIENT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MS365_CLIENT_SECRET=your-secret-value
```

Optional runtime tuning:

```env
MAILBOX_CACHE_STORE=
MAILBOX_ATTACHMENT_DISK=local
MAILBOX_ATTACHMENT_PATH=mailbox-attachments
MAILBOX_LOG_LEVEL=info
MAILBOX_QUEUE_RETRY_STRATEGY=release
```

## Step 6: Verify from the Package

```bash
php artisan mailbox:test-access invoices@yourdomain.com --driver=ms-graph
php artisan mailbox:health --driver=ms-graph
```

Expected outcomes:

- `mailbox:test-access` reports authentication + mailbox access success.
- `mailbox:health` reports token/API healthy.

## Optional: Manually Request a Token (Debug)

```bash
curl -sS -X POST "https://login.microsoftonline.com/${MS365_TENANT_ID}/oauth2/v2.0/token" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "client_id=${MS365_CLIENT_ID}" \
  -d "client_secret=${MS365_CLIENT_SECRET}" \
  -d "scope=https://graph.microsoft.com/.default" \
  -d "grant_type=client_credentials"
```

If this fails, credentials/consent are not valid yet.

## How Tokens Work in This Package

- `TokenManager` requests access tokens from Entra.
- Access tokens are cached until near expiry.
- Graph calls are made with `Authorization: Bearer <token>`.
- On 401, the package invalidates token cache and re-authenticates.

No refresh token is used in this mode. For refresh-token based delegated auth, see [user OAuth](user-oauth.md).

## Troubleshooting

- `invalid_client`: client ID/secret mismatch or expired secret.
- `insufficient privileges`: missing Graph application permission or no admin consent.
- `403 Access denied`: mailbox not allowed by Exchange app access policy.
- Intermittent `429`: expected under load; package retries and can release queue jobs for retry.

## Security Notes

- Store secrets only in secure environment managers.
- Rotate client secrets periodically.
- Keep app permissions minimal and mailbox scope restricted.

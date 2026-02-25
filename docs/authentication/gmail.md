# Gmail (Google Workspace) Authentication

Mailbox connects to Google Workspace mailboxes through a **service account with domain-wide delegation**. This is the recommended path for server-side and background workloads where your application needs to read, modify, and organize mail on behalf of workspace users without any interactive sign-in. The service account authenticates itself with a signed JWT, then impersonates whichever mailbox you target at runtime.

If you need an interactive sign-in flow where individual users grant consent through a browser redirect, see [Gmail User OAuth](user-oauth.md) instead.

## What You Need

- A Google Cloud project with the Gmail API enabled.
- A service account with a JSON key file.
- Domain-wide delegation enabled on the service account.
- Google Workspace admin access to authorize the service account's client ID and scopes.

## Which Values You Need for `.env`

- `GMAIL_SERVICE_ACCOUNT_JSON_PATH` — path to the downloaded JSON key file
- `GMAIL_SUBJECT_EMAIL` — a default mailbox for health probes (optional but recommended)

Mailbox obtains and caches access tokens automatically at runtime. There is no client secret to rotate — authentication is based on the service account's RSA private key.

## Step 1: Create a Google Cloud Project

1. Open the [Google Cloud Console](https://console.cloud.google.com/).
2. Click the project selector in the top bar and choose **New Project**.
3. Name your project (example: `Acme Mailbox Production`).
4. Select your billing account and organization if prompted, then click **Create**.

If you already have a project for your application, you can reuse it. A dedicated project makes it easier to audit API access and manage credentials independently.

## Step 2: Enable the Gmail API

1. In the Cloud Console, navigate to **APIs & Services > Library**.
2. Search for **Gmail API**.
3. Click **Gmail API** in the results, then click **Enable**.

Without this step, all Gmail API calls will return `403 Access Not Configured`.

## Step 3: Create a Service Account

1. Navigate to **IAM & Admin > Service Accounts**.
2. Click **Create Service Account**.
3. Enter a name (example: `mailbox-service`) and an optional description.
4. Click **Create and Continue**. You can skip the optional "Grant this service account access to project" and "Grant users access to this service account" steps for now.
5. Click **Done**.

### Download the JSON Key

1. In the service accounts list, click the service account you just created.
2. Go to the **Keys** tab.
3. Click **Add Key > Create new key**.
4. Select **JSON** and click **Create**.
5. The browser downloads a `.json` file. This file contains the private key that Mailbox uses to sign JWTs.

> **Warning** This key file grants full access to every mailbox you delegate. Treat it like a database password. Never commit it to version control.

Move the key file to a secure location on your server:

```bash
mv ~/Downloads/acme-mailbox-production-a1b2c3d4e5f6.json /etc/secrets/gmail-service-account.json
chmod 600 /etc/secrets/gmail-service-account.json
```

## Step 4: Enable Domain-Wide Delegation

Domain-wide delegation allows the service account to impersonate any user in your Google Workspace domain. Without it, the service account can only access its own (empty) mailbox.

1. In the Cloud Console, go to **IAM & Admin > Service Accounts**.
2. Click the service account you created in Step 3.
3. Click **Show advanced settings** (or scroll to the bottom of the details page).
4. Check **Enable Google Workspace Domain-wide Delegation**.
5. If prompted, configure a consent screen — select **Internal** and fill in the required fields.
6. Click **Save**.
7. Copy the **Client ID** (a numeric string, e.g. `114208924637529744935`). You will need this for the admin console in the next step.

> **Note** The Client ID is not the same as the `client_email`. The Client ID is the numeric unique identifier shown on the service account detail page.

## Step 5: Authorize Scopes in Google Workspace Admin

This step connects the Cloud project to your Workspace domain. A Google Workspace super-admin must complete it.

1. Open the [Google Workspace Admin Console](https://admin.google.com/).
2. Navigate to **Security > Access and data control > API controls**.
3. In the **Domain-wide delegation** section, click **Manage Domain Wide Delegation**.
4. Click **Add new**.
5. In the **Client ID** field, paste the numeric Client ID from Step 4.
6. In the **OAuth scopes** field, enter the scopes your application requires, comma-separated:

```
https://www.googleapis.com/auth/gmail.modify
```

7. Click **Authorize**.

### Choosing Scopes

| Scope | Access Level |
|---|---|
| `https://www.googleapis.com/auth/gmail.modify` | Read, send, and modify messages and labels (recommended) |
| `https://www.googleapis.com/auth/gmail.readonly` | Read-only access to messages and labels |
| `https://www.googleapis.com/auth/gmail.labels` | Manage labels only |
| `https://mail.google.com/` | Full access including permanent deletion |

Mailbox defaults to `gmail.modify`, which covers reading messages, moving between folders (labels), marking as read/unread, and managing labels. Use the narrowest scope that satisfies your requirements.

> **Tip** If you change scopes later, you must update them in both the `.env` / config and the Workspace Admin Console. The two must match exactly.

## Step 6: Configure Laravel Environment

Set your driver to `gmail` and point Mailbox at the key file:

```env
MAILBOX_DRIVER=gmail

GMAIL_SERVICE_ACCOUNT_JSON_PATH=/etc/secrets/gmail-service-account.json
GMAIL_SUBJECT_EMAIL=invoices@acme.com
```

The `GMAIL_SUBJECT_EMAIL` value is used as the default mailbox for `mailbox:test-access` and `mailbox:health` probes. At runtime, you specify which mailbox to impersonate through the `mailbox()` method.

### All Gmail Configuration Keys

| Environment Variable | Config Key | Default | Description |
|---|---|---|---|
| `MAILBOX_DRIVER` | `mailbox.default` | `ms-graph` | Set to `gmail` or `google-workspace` |
| `GMAIL_SERVICE_ACCOUNT_JSON_PATH` | `mailbox.drivers.gmail.service_account_json_path` | `null` | Absolute path to the service account JSON key file |
| `GMAIL_SERVICE_ACCOUNT_JSON` | `mailbox.drivers.gmail.service_account_json` | `null` | Inline JSON string (alternative to file path) |
| `GMAIL_SUBJECT_EMAIL` | `mailbox.drivers.gmail.subject_email` | `null` | Default mailbox for health probes |
| `GMAIL_SCOPES` | `mailbox.drivers.gmail.scopes` | `gmail.modify` | Comma-separated OAuth scopes |
| `GMAIL_TOKEN_URI` | `mailbox.drivers.gmail.token_uri` | `https://oauth2.googleapis.com/token` | Google OAuth token endpoint |
| `GMAIL_API_BASE_URI` | `mailbox.drivers.gmail.api_base_uri` | `https://gmail.googleapis.com/gmail/v1/` | Gmail API base URL |

### Providing Credentials Inline

If your deployment platform uses environment variables rather than files (e.g. container secrets, Vapor, or encrypted `.env`), you can provide the entire JSON key as a string:

```env
GMAIL_SERVICE_ACCOUNT_JSON='{"type":"service_account","project_id":"acme-prod","private_key_id":"abc123","private_key":"-----BEGIN RSA PRIVATE KEY-----\n...\n-----END RSA PRIVATE KEY-----\n","client_email":"mailbox-service@acme-prod.iam.gserviceaccount.com","client_id":"114208924637529744935","auth_uri":"https://accounts.google.com/o/oauth2/auth","token_uri":"https://oauth2.googleapis.com/token"}'
```

When both `GMAIL_SERVICE_ACCOUNT_JSON_PATH` and `GMAIL_SERVICE_ACCOUNT_JSON` are set, the file path takes precedence.

### Driver Alias

The `google-workspace` driver name is an alias that resolves to the same Gmail driver class:

```env
# These are equivalent
MAILBOX_DRIVER=gmail
MAILBOX_DRIVER=google-workspace
```

### Optional Runtime Tuning

```env
MAILBOX_CACHE_STORE=redis
MAILBOX_LOG_LEVEL=info
MAILBOX_QUEUE_RETRY_STRATEGY=release
```

## Step 7: Verify the Connection

Run the built-in test commands to confirm that authentication, delegation, and API access are working end-to-end:

```bash
php artisan mailbox:test-access invoices@acme.com --driver=gmail
```

Expected output on success:

```
 Connected successfully (312ms)
 Access to invoices@acme.com: Granted
```

Then run the health check:

```bash
php artisan mailbox:health --driver=gmail
```

Expected output:

```
 Token: Valid (expires in 2847s)
 API: Reachable (189ms)
 Status: Healthy
```

If either command fails, jump to [Troubleshooting](#troubleshooting) below.

## How the JWT Token Flow Works

Understanding the authentication flow helps when debugging delegation issues. Mailbox uses Google's **two-legged OAuth** (JWT bearer assertion) to obtain access tokens without any browser interaction.

### The Flow in Detail

1. **Build a JWT assertion.** `GmailTokenManager` constructs a JWT with:
   - `iss` (issuer) — the service account's `client_email`
   - `sub` (subject) — the mailbox being impersonated (e.g. `invoices@acme.com`)
   - `aud` (audience) — the token endpoint (`https://oauth2.googleapis.com/token`)
   - `scope` — the authorized OAuth scopes
   - `iat` / `exp` — issued-at and expiration timestamps (1-hour window)

2. **Sign the JWT.** The assertion is signed with the service account's RSA private key using RS256. This is why the JSON key file is sensitive — it contains the signing key.

3. **Exchange for an access token.** Mailbox POSTs the signed JWT to Google's token endpoint using the `urn:ietf:params:oauth:grant-type:jwt-bearer` grant type. Google validates the signature, checks the delegation authorization in Workspace Admin, and returns a short-lived access token.

4. **Cache the token.** Mailbox caches the access token using Laravel's cache system. The default token lifetime is 3600 seconds (1 hour), and Mailbox subtracts a configurable buffer (default 300 seconds) to refresh before expiry.

5. **Make API calls.** Every Gmail API request includes the token as a `Bearer` header. If a request returns `401`, Mailbox invalidates the cached token and re-authenticates once before throwing an exception.

### Per-Mailbox Tokens

Unlike the MS Graph client-credentials flow where a single token covers all mailboxes, Gmail issues a separate token for each impersonated mailbox. Mailbox caches tokens independently per mailbox address. When you call `$driver->mailbox('invoices@acme.com')` and then `$driver->mailbox('billing@acme.com')`, each gets its own cached token.

### No Refresh Tokens

The JWT flow does not use refresh tokens. When a cached token expires, Mailbox signs a new JWT and exchanges it for a fresh access token. This means there is no long-lived credential stored in the database — only the JSON key file on disk (or in the environment).

## Optional: Manually Request a Token (Debug)

If you need to verify the service account credentials outside of Laravel, you can construct the JWT and request a token manually. This is useful for isolating whether an issue is in the Google configuration or in your Laravel environment.

```php
use Pyle\Mailbox\Drivers\Gmail\GmailTokenManager;

$tokenManager = new GmailTokenManager([
    'service_account_json_path' => '/etc/secrets/gmail-service-account.json',
    'scopes' => ['https://www.googleapis.com/auth/gmail.modify'],
    'token_uri' => 'https://oauth2.googleapis.com/token',
]);

$token = $tokenManager->getToken('invoices@acme.com');
// string — the raw Bearer token
```

You can then test the token directly with `curl`:

```bash
curl -sS -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  "https://gmail.googleapis.com/gmail/v1/users/invoices%40acme.com/labels/INBOX"
```

A successful response returns the INBOX label metadata as JSON.

## Security Best Practices

### Protect the Key File

The service account JSON key is the single credential that grants access to every delegated mailbox. Follow these rules:

- **File permissions**: Set `600` (owner read/write only) on the key file. Never use world-readable permissions.
- **Never commit to Git**: Add the key file path to `.gitignore`. Consider using a secrets manager instead of files on disk.
- **Separate keys per environment**: Use different service accounts (and different key files) for development, staging, and production.

### Use a Secrets Manager

For production deployments, store the JSON key in a secrets manager and inject it via the `GMAIL_SERVICE_ACCOUNT_JSON` environment variable:

```bash
# Example: pulling from AWS Secrets Manager at deploy time
export GMAIL_SERVICE_ACCOUNT_JSON=$(aws secretsmanager get-secret-value \
  --secret-id prod/gmail-service-account \
  --query SecretString --output text)
```

### Limit Scopes

Only authorize the scopes your application actually needs. If you only read mail, use `gmail.readonly` instead of `gmail.modify`. Update both the Workspace Admin delegation and your `.env` in tandem.

### Restrict Impersonation

Domain-wide delegation grants access to every mailbox in the domain by default. Google Workspace does not natively support restricting which users a service account can impersonate. To limit exposure:

- Use a dedicated service account per application.
- Audit impersonation activity in the Google Workspace Admin Console under **Reports > Audit and investigation > OAuth log events**.
- Consider using [Organizational Units (OUs)](https://support.google.com/a/answer/4352075) to segment users, although OUs do not restrict delegation directly — they help with monitoring.

> **Warning** Unlike Microsoft Graph's Application Access Policies, Google Workspace does not offer a built-in mechanism to restrict domain-wide delegation to specific mailboxes. Treat the service account key with the same gravity as a database root password.

### Rotate Keys Periodically

Service account keys do not expire automatically, but you should rotate them on a regular schedule:

1. Create a new key in **IAM & Admin > Service Accounts > Keys**.
2. Deploy the new key file to your servers.
3. Update `GMAIL_SERVICE_ACCOUNT_JSON_PATH` (or the inline JSON).
4. Verify with `php artisan mailbox:test-access`.
5. Delete the old key from the Cloud Console.

## Troubleshooting

### `Failed to authenticate with Gmail. Verify GMAIL_SERVICE_ACCOUNT_JSON or GMAIL_SERVICE_ACCOUNT_JSON_PATH.`

The package cannot find or parse the service account credentials.

- Verify the file exists at the configured path and the web server user can read it.
- If using inline JSON, make sure the value is valid JSON (no truncation, proper escaping).
- Check that the JSON contains both `client_email` and `private_key` fields.

### `Failed to sign Gmail service account assertion.`

OpenSSL cannot sign the JWT with the private key in the JSON file.

- Ensure the `private_key` field in the JSON is a valid RSA private key (begins with `-----BEGIN RSA PRIVATE KEY-----` or `-----BEGIN PRIVATE KEY-----`).
- Verify your PHP installation has the OpenSSL extension enabled: `php -m | grep openssl`.

### `Failed to authenticate with Gmail using service account delegation.`

The signed JWT was rejected by Google's token endpoint. This is the most common setup error.

- **Delegation not enabled**: Go back to Step 4 and confirm domain-wide delegation is checked on the service account.
- **Scopes not authorized**: Go back to Step 5 and verify the Client ID and scopes in the Workspace Admin Console match exactly.
- **Wrong Client ID**: The Workspace Admin delegation uses the numeric Client ID, not the `client_email`. Double-check you pasted the correct value.
- **Propagation delay**: After authorizing scopes in the Admin Console, changes can take up to 24 hours to propagate, though most take effect within minutes.

### `Access denied to mailbox 'invoices@acme.com'. Ensure domain-wide delegation is configured.`

Authentication succeeded (the service account got a token), but the Gmail API rejected the impersonation attempt.

- Verify the email address belongs to your Google Workspace domain.
- Confirm the user account is active (not suspended or deleted).
- Check that the authorized scopes in the Admin Console include the scope your application requests.
- If the mailbox is a Google Group or shared mailbox, note that service accounts impersonate user accounts — not groups.

### `403 Access Not Configured` or `Gmail API has not been used in project`

The Gmail API is not enabled in your Cloud project. Go back to Step 2 and enable it.

### `429 Rate Limit` Errors

Google enforces per-user and per-project rate limits on the Gmail API. Mailbox handles `429` responses automatically with retry logic and exponential backoff. If you consistently hit limits:

- Spread requests across mailboxes rather than hammering a single one.
- Use [delta sync](../delta-sync.md) to reduce the number of full-list calls.
- Consider requesting a quota increase in the Cloud Console under **APIs & Services > Gmail API > Quotas**.

### `401 Unauthorized` After Previously Working

The cached token may have been invalidated server-side. Mailbox automatically retries once after invalidating its token cache. If the error persists:

- Verify the service account key has not been deleted from the Cloud Console.
- Check that domain-wide delegation has not been revoked in the Workspace Admin Console.
- Clear the Mailbox token cache manually: `Cache::store(config('mailbox.cache_store'))->flush()` or target the specific prefix.

## User OAuth Alternative

For scenarios where end users must grant consent through a browser (e.g. connecting personal Gmail accounts, or SaaS applications where you do not control the Workspace domain), Mailbox provides a separate user OAuth flow with redirect and callback routes. This flow stores per-user refresh tokens in your database.

See [Gmail User OAuth](user-oauth.md) for the full setup guide.

## What's Next

- [Configuration Reference](../configuration.md) — all configuration keys, cache tuning, and runtime options.
- [Artisan Commands](../artisan-commands.md) — `mailbox:test-access`, `mailbox:health`, and other CLI tools.
- [Error Handling](../error-handling.md) — how Mailbox maps provider errors to typed exceptions.

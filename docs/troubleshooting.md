# Troubleshooting

Even with a well-configured setup, you will occasionally encounter authentication errors, permission denials, or rate-limit responses from mail providers. This page catalogues the most common issues you are likely to see with Mailbox, organized by provider and then by general scenarios. Each entry follows a consistent format: the symptom you observe, the underlying cause, and the concrete steps to fix it.

## Microsoft Graph Issues

### `invalid_client` -- AADSTS7000215

**Symptom:** Mailbox throws an `AuthenticationException` with the message `AADSTS7000215: Invalid client secret provided` when attempting to acquire a token.

**Cause:** The client secret in your environment is either incorrect, expired, or belongs to a different app registration.

**Solution:**

1. Open the Azure Portal and navigate to **App registrations** > your app > **Certificates & secrets**.
2. Check the expiry date on your secret. If it has expired, create a new one.
3. Copy the new secret **value** (not the secret ID) into your environment file.

```env
MS365_CLIENT_SECRET=your-new-secret-value
```

4. Clear the cached config:

```bash
php artisan config:clear
```

> **Tip** Mailbox dispatches a `SecretExpirationWarning` event when your secret is within the configured warning window (`secret_expiry_warning_days`). Listen for this event to rotate secrets before they expire.

### `insufficient privileges` -- 403 Forbidden

**Symptom:** API calls return a 403 with the error code `Authorization_RequestDenied` or `insufficient privileges`. The `AccessDenied` event fires.

**Cause:** The app registration is missing the required Microsoft Graph application permissions, or admin consent has not been granted.

**Solution:**

1. In the Azure Portal, go to **App registrations** > your app > **API permissions**.
2. Confirm these **Application** permissions are present:
   - `Mail.ReadWrite`
   - `Mail.Send` (if you send mail)
3. Click **Grant admin consent for [your tenant]**.
4. Wait 1-2 minutes for consent to propagate, then retry.

```php
use Pyle\Mailbox\Facades\Mailbox;

$result = Mailbox::testConnection('invoices@acme.com');
// ConnectionTestResult { success: true, latencyMs: 85, ... }
```

### `403 Access denied` -- Mailbox Not in Scope

**Symptom:** Authentication succeeds, but accessing a specific mailbox returns `403 Access is denied. Check credentials and try again.`

**Cause:** An Exchange Online application access policy restricts which mailboxes your app can access, and the target mailbox is not in the allowed scope.

**Solution:**

1. Confirm the target mailbox is a member of the mail-enabled security group referenced in your access policy.
2. Use the Exchange Online PowerShell module to verify:

```bash
Get-ApplicationAccessPolicy -Identity "your-app-client-id"
```

3. If needed, add the mailbox to the security group or update the policy:

```bash
New-ApplicationAccessPolicy -AppId "your-client-id" \
    -PolicyScopeGroupId "allowed-mailboxes@acme.com" \
    -AccessRight RestrictAccess \
    -Description "Limit Mailbox package access"
```

> **Warning** Application access policy changes can take up to 30 minutes to propagate. Do not assume a fix failed if the 403 persists immediately after the change.

### Rate Limiting -- 429 Too Many Requests

**Symptom:** Mailbox throws a `RateLimitException` or you see `429` responses in your logs. The `RateLimitHit` event fires with a `retryAfter` value.

**Cause:** Your application is exceeding Microsoft Graph's per-mailbox or per-tenant throttling limits, typically during high-volume sync operations.

**Solution:**

1. Reduce your page size and sync burst volume in `config/mailbox.php`:

```php
'default_page_size' => 25, // Down from 50
'max_concurrent_per_mailbox' => 2, // Down from 4
```

2. Switch to the `release` retry strategy so queue workers do not block:

```env
MAILBOX_QUEUE_RETRY_STRATEGY=release
```

3. Stagger your sync jobs across mailboxes rather than running them all simultaneously.
4. Listen for the `RateLimitHit` event to monitor throttling:

```php
use Pyle\Mailbox\Events\RateLimitHit;

Event::listen(RateLimitHit::class, function (RateLimitHit $event) {
    Log::warning('Rate limited', [
        'mailbox' => $event->mailbox,
        'retry_after' => $event->retryAfter,
        'endpoint' => $event->endpoint,
    ]);
});
```

### Token Refresh Failures

**Symptom:** Mailbox throws an `AuthenticationException` during a token refresh. The `TokenRefreshFailed` event fires with an `error` and optional `guidance` message.

**Cause:** The OAuth token could not be refreshed, usually because the refresh token has expired, the client secret has been rotated, or the tenant configuration changed.

**Solution:**

1. Check the `guidance` field on the event or exception -- it often contains a provider-specific hint.
2. Verify your credentials have not changed since the token was issued.
3. If using delegated (user OAuth) auth, the user may need to re-authorize.
4. Clear cached tokens:

```bash
php artisan cache:forget mailbox_token:ms-graph
```

### `fullSyncRequired` -- Delta Token Expired

**Symptom:** A delta sync throws a `DeltaTokenExpiredException` or you see `fullSyncRequired=true` in the response. The `DeltaTokenExpired` event fires.

**Cause:** The stored delta token has expired. Microsoft Graph delta tokens are valid for a limited time, and if your sync interval is too long, the token becomes stale.

**Solution:**

1. Clear the expired token and run a fresh full sync:

```php
$folder->update(['delta_token' => null]);
```

2. Run the sync command:

```bash
php artisan mailbox:sync --mailbox=invoices@acme.com
```

3. To prevent this from recurring, sync more frequently. A good rule of thumb is to sync at least every 24 hours for active mailboxes.

## Gmail Issues

### Domain-Wide Delegation Not Configured

**Symptom:** Mailbox throws an `AuthenticationException` with a message like `unauthorized_client` or `Client is unauthorized to retrieve access tokens using this method`.

**Cause:** The Google Workspace service account does not have domain-wide delegation enabled, or the required scopes have not been granted in the admin console.

**Solution:**

1. In the Google Cloud Console, go to **IAM & Admin** > **Service Accounts** > your service account.
2. Enable **Domain-wide delegation** if not already checked.
3. Copy the **Client ID** (numeric) from the service account details.
4. In the Google Workspace Admin console, go to **Security** > **API controls** > **Manage Domain-wide Delegation**.
5. Add a new entry with your service account's client ID and the scopes:

```
https://www.googleapis.com/auth/gmail.modify
```

> **Warning** Scope changes in the Google Workspace admin console can take up to 24 hours to propagate, though they typically apply within a few minutes.

### Invalid Service Account Key

**Symptom:** Mailbox throws an `AuthenticationException` when acquiring a JWT token. The error message references an invalid key, missing fields, or JSON parse errors.

**Cause:** The service account JSON key file is malformed, has been truncated, or the environment variable contains escaped characters.

**Solution:**

You can provide the key as either a file path or an inline JSON string. Use one approach, not both:

```env
# Option A: Path to the key file
GMAIL_SERVICE_ACCOUNT_JSON_PATH=/etc/secrets/gmail-sa.json

# Option B: Inline JSON (useful in container environments)
GMAIL_SERVICE_ACCOUNT_JSON='{"type":"service_account","project_id":"acme-mail",...}'
```

Verify the key file is valid JSON:

```bash
cat /etc/secrets/gmail-sa.json | python3 -m json.tool
```

If using the inline approach, make sure the value is not double-escaped. Single quotes around the entire value in your `.env` file prevent shell interpolation.

### Missing or Incorrect Subject Email

**Symptom:** Authentication succeeds but API calls return `403 Delegation denied` or `400 Bad Request` with `failedPrecondition`.

**Cause:** The `GMAIL_SUBJECT_EMAIL` is either missing, refers to a user outside the Google Workspace domain, or the user account is suspended.

**Solution:**

Set the subject email to a valid, active Google Workspace user:

```env
GMAIL_SUBJECT_EMAIL=admin@acme.com
```

This user does not need special admin privileges, but their account must be active and within the domain that granted delegation to your service account.

### Gmail Rate Limiting

**Symptom:** API calls return `429 Too Many Requests` or `403 Rate Limit Exceeded`. The `RateLimitHit` event fires.

**Cause:** Gmail enforces per-user rate limits (250 quota units per second) and daily sending limits.

**Solution:**

The same strategies that work for Microsoft Graph apply here:

1. Reduce `default_page_size` and `max_concurrent_per_mailbox`.
2. Use the `release` queue retry strategy.
3. Stagger sync jobs across mailboxes.
4. For batch operations, add delays between requests.

### Insufficient Scopes

**Symptom:** API calls return `403 Insufficient Permission`.

**Cause:** The OAuth scopes granted to the service account do not include the operations you are attempting.

**Solution:**

Verify your scopes in `config/mailbox.php` match what is delegated in the admin console:

```php
'gmail' => [
    'scopes' => [
        'https://www.googleapis.com/auth/gmail.modify',
    ],
],
```

The `gmail.modify` scope covers reading, labeling, and moving messages. If you only need read access, `gmail.readonly` is sufficient. If you need to send mail, add `gmail.send`.

## General Issues

### Driver Not Found

**Symptom:** Mailbox throws a `DriverNotConfiguredException` with the message: `No configuration found for driver 'xyz'. Add it to config/mailbox.php. Available drivers: ms-graph, gmail.`

**Cause:** The driver name you requested does not match any key in the `drivers` array of your published config.

**Solution:**

1. Verify the driver name matches exactly:

```php
// Correct
Mailbox::driver('ms-graph');
Mailbox::driver('gmail');

// These will fail unless configured
Mailbox::driver('microsoft');
Mailbox::driver('google');
```

2. If you have not published the config yet:

```bash
php artisan vendor:publish --tag=mailbox-config
```

3. Check that `MAILBOX_DRIVER` in your `.env` matches a configured driver name:

```env
MAILBOX_DRIVER=ms-graph
```

### Configuration Not Loading

**Symptom:** Mailbox uses default values instead of your configured ones, or driver config arrays appear empty.

**Cause:** The configuration has not been published, or a cached config is stale.

**Solution:**

```bash
php artisan config:clear
php artisan vendor:publish --tag=mailbox-config --force
```

> **Warning** Using `--force` will overwrite your existing `config/mailbox.php`. If you have customized it, back up the file first or merge changes manually.

### Migration Failures

**Symptom:** Running `php artisan migrate` throws a SQL error about duplicate tables or missing columns.

**Cause:** Migrations were run out of order, or you are upgrading from a version that changed the schema.

**Solution:**

1. Check which Mailbox migrations have run:

```bash
php artisan migrate:status | grep mailbox
```

2. If tables already exist from a manual setup, publish and adjust the migration files:

```bash
php artisan vendor:publish --tag=mailbox-migrations
```

3. Edit the published migrations to wrap table creation in `Schema::hasTable()` checks if needed.

### Attachment Write Failures

**Symptom:** Downloading attachments throws a filesystem exception or silently fails. The `AttachmentSkipped` event may fire.

**Cause:** The configured disk does not exist, the path is not writable, or the disk driver is misconfigured.

**Solution:**

1. Confirm the disk exists in `config/filesystems.php`:

```env
MAILBOX_ATTACHMENT_DISK=local
MAILBOX_ATTACHMENT_PATH=mailbox-attachments
```

2. Verify write permissions on the target directory:

```bash
ls -la storage/app/mailbox-attachments/
```

3. If using S3 or another cloud disk, verify your credentials and bucket configuration.

## Debugging Checklist

When you encounter an issue that does not match the scenarios above, work through this checklist:

1. **Check credentials** -- Run `php artisan mailbox:test-connection` to verify authentication.
2. **Clear caches** -- Run `php artisan config:clear` and `php artisan cache:clear`.
3. **Check logs** -- Mailbox logs to the `mailbox` channel. Review `storage/logs/` or your configured log destination.
4. **Enable debug logging** -- Set `MAILBOX_LOG_LEVEL=debug` in your `.env` for verbose output.
5. **Listen for events** -- Register temporary listeners for `ApiError` and `TokenRefreshFailed` to capture details:

```php
use Pyle\Mailbox\Events\ApiError;

Event::listen(ApiError::class, function (ApiError $event) {
    Log::error('Mailbox API error', [
        'driver' => $event->driver,
        'mailbox' => $event->mailbox,
        'status' => $event->status,
        'error' => $event->error,
        'endpoint' => $event->endpoint,
    ]);
});
```

6. **Test with a minimal script** -- Isolate the issue in a `php artisan tinker` session:

```php
use Pyle\Mailbox\Facades\Mailbox;

// Test basic connectivity
Mailbox::testConnection('invoices@acme.com');

// Test a simple read operation
Mailbox::mailbox('invoices@acme.com')->messages()->take(1)->get();
```

7. **Check provider status** -- Verify the provider is not experiencing an outage:
   - Microsoft: [status.office.com](https://status.office.com)
   - Google: [status.cloud.google.com](https://status.cloud.google.com)

## What's Next

- [Configuration](configuration.md) -- review all available configuration options
- [Error Handling](error-handling.md) -- learn about the exception hierarchy and retry strategies
- [Authentication: Microsoft Graph](authentication/ms-graph.md) -- detailed setup guide for MS Graph

# Gmail Authentication Guide

This package supports Google Workspace with two auth paths:

- runtime mailbox operations: service account + domain-wide delegation
- optional user OAuth redirect/callback flow

## 1) Runtime Auth (Service Account)

Configure the canonical Gmail driver:

```env
MAILBOX_DRIVER=gmail

GMAIL_SERVICE_ACCOUNT_JSON=
GMAIL_SERVICE_ACCOUNT_JSON_PATH=/secure/path/service-account.json
GMAIL_SUBJECT_EMAIL=invoices@yourdomain.com
GMAIL_SCOPES=https://www.googleapis.com/auth/gmail.modify
```

You can also use `MAILBOX_DRIVER=google-workspace`; it resolves to the same Gmail driver.

### Google Workspace Admin Requirements

1. Create a Google Cloud service account.
2. Enable Gmail API in the project.
3. In Google Workspace Admin, configure domain-wide delegation for the service account client ID.
4. Grant Gmail scopes required by your workload (for example `https://www.googleapis.com/auth/gmail.modify`).
5. Use mailbox impersonation emails in runtime calls (or configure `GMAIL_SUBJECT_EMAIL` for health probes).

### Verify

```bash
php artisan mailbox:test-access invoices@yourdomain.com --driver=gmail
php artisan mailbox:health --driver=gmail
```

## 2) User OAuth (Optional)

Enable OAuth routes:

```env
MAILBOX_OAUTH_ENABLED=true
MAILBOX_OAUTH_ROUTE_PREFIX=mailbox/oauth
MAILBOX_OAUTH_ROUTE_MIDDLEWARE=web,auth

MAILBOX_OAUTH_GMAIL_CLIENT_ID=...
MAILBOX_OAUTH_GMAIL_CLIENT_SECRET=...
MAILBOX_OAUTH_GMAIL_REDIRECT_URI=https://your-app.test/mailbox/oauth/gmail/callback
```

Routes provided:

- `GET /mailbox/oauth/gmail/redirect`
- `GET /mailbox/oauth/gmail/callback`

Named routes:

- `mailbox.oauth.gmail.redirect`
- `mailbox.oauth.gmail.callback`

### Redirect Example

```php
return redirect()->route('mailbox.oauth.gmail.redirect', [
    'mailbox_connection_id' => $connection->id, // optional
    'return_to' => route('settings.mailbox'),   // optional
    'user_reference' => (string) auth()->id(),  // optional
]);
```

### Callback Behavior

On success, token data is upserted to `mailbox_oauth_tokens` with `provider = gmail-user`.

On redirect success:

- `mailbox_oauth=success`
- `mailbox_oauth_token_id={id}`
- `user_reference={value}` (if provided)

On redirect failure:

- `mailbox_oauth=error`
- `mailbox_oauth_error={code}`
- `mailbox_oauth_error_description={message}`

## Notes

- Runtime Gmail driver auth remains service-account based.
- User OAuth tokens are stored for app workflows and user-linked features.

## Next

- [Configuration](../configuration.md)
- [Troubleshooting](../troubleshooting.md)

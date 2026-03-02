# Installation

Mailbox installs like any standard Laravel package -- a single Composer command, a config publish, and a migration. The whole process takes about a minute, and you can verify everything with a built-in health check command before writing a single line of application code.

## Requirements

| Dependency | Version |
|---|---|
| PHP | 8.2 or higher |
| Laravel | 12.0 or higher |
| Guzzle | 7.0 or higher (pulled in automatically) |

You will also need credentials for at least one mail provider:

- **Microsoft 365 / Outlook** -- an Azure AD app registration with application or delegated permissions. See [Microsoft Graph Setup](authentication/ms-graph.md).
- **Gmail / Google Workspace** -- a Google Cloud service account with domain-wide delegation, or delegated OAuth credentials. See [Gmail Setup](authentication/gmail.md).

## Install the Package

```bash
composer require pylesoft/mailbox
```

Mailbox registers its service provider and `Mailbox` facade automatically via Laravel's package discovery. No manual provider registration is needed.

## Publish the Configuration

```bash
php artisan vendor:publish --tag=mailbox-config
```

This creates `config/mailbox.php` where you configure drivers, cache, retry behaviour, attachment storage, and more. See [Configuration](configuration.md) for a full reference.

## Package Migrations

Mailbox auto-loads its migration files and they create/normalize the tables it needs for connections, mailboxes, folders, OAuth tokens, messages, and attachments:

- `create_mailbox_connections_table`
- `create_monitored_mailboxes_table`
- `create_monitored_folders_table`
- `create_mailbox_oauth_tokens_table`
- `create_mailbox_messages_table`
- `create_mailbox_attachments_table`
- `rename_monitored_entities_to_mailbox_entities`

## Run the Migrations

```bash
php artisan migrate
```

## Set Your Environment Variables

Add the credentials for your default driver to `.env`. Here is a minimal example for Microsoft Graph:

```env
MAILBOX_DRIVER=ms-graph

MS365_TENANT_ID=your-tenant-id
MS365_CLIENT_ID=your-client-id
MS365_CLIENT_SECRET=your-client-secret
```

And for Gmail with a service account:

```env
MAILBOX_DRIVER=gmail

GMAIL_SERVICE_ACCOUNT_JSON_PATH=/path/to/service-account.json
GMAIL_SUBJECT_EMAIL=invoices@acme.com
```

> **Note** You only need to configure the driver you plan to use. If you use both, set the `MAILBOX_DRIVER` to whichever should be the default and configure the other driver's variables alongside it.

## Verify the Installation

Mailbox includes a health check command that validates your credentials, token acquisition, and API connectivity in a single step:

```bash
php artisan mailbox:health --driver=ms-graph
```

You will see a table summarising the results:

```
 Mailbox Health Check

 ┌───────────────────┬───────────┐
 │ Metric            │ Value     │
 ├───────────────────┼───────────┤
 │ Driver            │ ms-graph  │
 │ Token             │ Valid     │
 │ Token Expires In  │ 3599s     │
 │ API               │ Reachable │
 │ Latency           │ 142ms     │
 │ Secret Expiration │ 2027-01-15│
 │ Warning           │ No        │
 │ Overall Status    │ Healthy   │
 └───────────────────┴───────────┘
```

If the overall status shows **Healthy**, you are ready to go. If not, double-check your environment variables and review the provider-specific setup guide:

- [Microsoft Graph Setup](authentication/ms-graph.md)
- [Gmail Setup](authentication/gmail.md)

> **Warning** The health check makes real API calls to your provider. Make sure you are running it against the correct tenant or Google project, especially in CI environments.

## Optional: Publish Stubs

If you plan to extend Mailbox with custom drivers, you can publish the package stubs for a head start:

```bash
php artisan vendor:publish --tag=mailbox-stubs
```

This copies stub files into `stubs/mailbox` at your project root. See [Extending Mailbox](extending/custom-drivers.md) for details.

## What's Next

- [Configuration](configuration.md) -- explore every option in `config/mailbox.php` and tune Mailbox for your application.
- [Quickstart](quickstart.md) -- fetch your first messages in under five minutes.
- [Microsoft Graph Setup](authentication/ms-graph.md) or [Gmail Setup](authentication/gmail.md) -- complete the authentication setup for your provider.

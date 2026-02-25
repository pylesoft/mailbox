# Configuration

Mailbox ships with a single configuration file that controls driver selection, provider credentials, retry behavior, attachment storage, caching, logging, and optional OAuth routes. Every setting has a sensible default so you can get started with just three environment variables, then fine-tune as your application grows.

After publishing the config file with `php artisan vendor:publish --tag=mailbox-config`, you will find it at `config/mailbox.php`. The sections below walk through every key in the file.

## Publishing the Config

```bash
php artisan vendor:publish --tag=mailbox-config
```

This copies the package's config file to `config/mailbox.php`. You can also publish migrations and stubs separately:

```bash
php artisan vendor:publish --tag=mailbox-migrations
php artisan vendor:publish --tag=mailbox-stubs
```

## Minimal `.env` Examples

Most applications only need one provider. Here is the bare minimum for each.

### Microsoft Graph

```env
MAILBOX_DRIVER=ms-graph
MS365_TENANT_ID=your-azure-tenant-id
MS365_CLIENT_ID=your-azure-client-id
MS365_CLIENT_SECRET=your-azure-client-secret
```

### Gmail (Google Workspace)

```env
MAILBOX_DRIVER=gmail
GMAIL_SERVICE_ACCOUNT_JSON_PATH=/etc/secrets/service-account.json
GMAIL_SUBJECT_EMAIL=invoices@acme.com
```

That is all you need to start reading mailboxes. The rest of this page documents every available key for when you need to go further.

## Driver Selection

```php
'default' => env('MAILBOX_DRIVER', 'ms-graph'),
```

| Key | Env | Default | Description |
|---|---|---|---|
| `default` | `MAILBOX_DRIVER` | `ms-graph` | The driver used when you call `Mailbox::mailbox()` without specifying a driver. Accepted values are `ms-graph`, `gmail`, and `google-workspace`. |

You can always override the default at runtime:

```php
use Pyle\Mailbox\Facades\Mailbox;

// Uses the default driver
$mailbox = Mailbox::mailbox('invoices@acme.com');

// Explicitly selects a driver
$mailbox = Mailbox::driver('gmail')->mailbox('billing@vendor.com');
```

## Driver Class Map

```php
'driver_classes' => [
    'ms-graph' => \Pyle\Mailbox\Drivers\MsGraph\MsGraphDriver::class,
    'gmail'    => \Pyle\Mailbox\Drivers\Gmail\GmailDriver::class,
],
```

| Key | Default | Description |
|---|---|---|
| `driver_classes` | See above | Maps canonical driver names to their PHP class. You rarely need to change this unless you are registering a [custom driver](architecture.md). |

The `driver_classes` map is separate from the `drivers` config blocks so that multiple config blocks (like `gmail` and `google-workspace`) can share the same underlying driver class.

## Credentials

Each driver has its own configuration block under the `drivers` key. Mailbox resolves these blocks at runtime based on the driver name you request.

### Microsoft Graph

```php
'drivers' => [
    'ms-graph' => [
        'driver'        => 'ms-graph',
        'tenant_id'     => env('MS365_TENANT_ID'),
        'client_id'     => env('MS365_CLIENT_ID'),
        'client_secret' => env('MS365_CLIENT_SECRET'),
        'api_version'   => 'v1.0',
        'timeout'       => 30,
    ],
],
```

| Key | Env | Default | Description |
|---|---|---|---|
| `drivers.ms-graph.driver` | -- | `ms-graph` | Identifies which driver class to resolve from the `driver_classes` map. |
| `drivers.ms-graph.tenant_id` | `MS365_TENANT_ID` | `null` | Your Azure AD tenant ID. Required. |
| `drivers.ms-graph.client_id` | `MS365_CLIENT_ID` | `null` | The Application (client) ID from your Azure AD app registration. Required. |
| `drivers.ms-graph.client_secret` | `MS365_CLIENT_SECRET` | `null` | A client secret generated in the Azure AD portal. Required. Keep this out of version control. |
| `drivers.ms-graph.api_version` | -- | `v1.0` | The Microsoft Graph API version. Use `v1.0` for production and `beta` only when you need preview features. |
| `drivers.ms-graph.timeout` | -- | `30` | HTTP connection timeout in seconds for Graph API requests. |

> **Warning** Never commit your `MS365_CLIENT_SECRET` to version control. Use environment variables or a secrets manager.

### Gmail

```php
'drivers' => [
    'gmail' => [
        'driver'                     => 'gmail',
        'service_account_json'       => env('GMAIL_SERVICE_ACCOUNT_JSON'),
        'service_account_json_path'  => env('GMAIL_SERVICE_ACCOUNT_JSON_PATH'),
        'subject_email'              => env('GMAIL_SUBJECT_EMAIL'),
        'token_uri'                  => env('GMAIL_TOKEN_URI', 'https://oauth2.googleapis.com/token'),
        'api_base_uri'               => env('GMAIL_API_BASE_URI', 'https://gmail.googleapis.com/gmail/v1/'),
        'scopes'                     => ['https://www.googleapis.com/auth/gmail.modify'],
        'timeout'                    => 30,
    ],
],
```

| Key | Env | Default | Description |
|---|---|---|---|
| `drivers.gmail.driver` | -- | `gmail` | Identifies which driver class to resolve. |
| `drivers.gmail.service_account_json` | `GMAIL_SERVICE_ACCOUNT_JSON` | `null` | The raw JSON string of your Google service account key. Use this **or** `service_account_json_path`, not both. Useful for environments where you inject secrets as env vars (e.g., Kubernetes). |
| `drivers.gmail.service_account_json_path` | `GMAIL_SERVICE_ACCOUNT_JSON_PATH` | `null` | Absolute path to the service account JSON key file on disk. Use this **or** `service_account_json`, not both. |
| `drivers.gmail.subject_email` | `GMAIL_SUBJECT_EMAIL` | `null` | The email address the service account impersonates via domain-wide delegation. Required for health probes and used as the default mailbox for connection tests. |
| `drivers.gmail.token_uri` | `GMAIL_TOKEN_URI` | `https://oauth2.googleapis.com/token` | The OAuth2 token endpoint. Override this only for testing or custom OAuth proxies. |
| `drivers.gmail.api_base_uri` | `GMAIL_API_BASE_URI` | `https://gmail.googleapis.com/gmail/v1/` | The Gmail API base URL. Override this only for testing with mocks or stubs. |
| `drivers.gmail.scopes` | `GMAIL_SCOPES` | `https://www.googleapis.com/auth/gmail.modify` | Comma-separated list of OAuth scopes requested when acquiring a token. The default `gmail.modify` scope covers reading, writing, and organizing messages. |
| `drivers.gmail.timeout` | -- | `30` | HTTP connection timeout in seconds for Gmail API requests. |

> **Tip** If you only need read access, narrow the scope to `https://www.googleapis.com/auth/gmail.readonly`. Least-privilege scopes are always preferred.

### Google Workspace Alias

```php
'drivers' => [
    'google-workspace' => [
        'driver' => 'gmail',
    ],
],
```

| Key | Default | Description |
|---|---|---|
| `drivers.google-workspace.driver` | `gmail` | A convenience alias. When you call `Mailbox::driver('google-workspace')`, Mailbox merges this block on top of the `gmail` config, with `driver` forced to `gmail`. You can add any Gmail config keys here to override them for this alias. |

This is useful when you want a second named configuration that shares the Gmail driver but uses different credentials or scopes. Any keys you add to the `google-workspace` block override the corresponding `gmail` defaults.

## Attachment Storage

```php
'attachment_disk' => env('MAILBOX_ATTACHMENT_DISK', 'local'),
'attachment_path' => env('MAILBOX_ATTACHMENT_PATH', 'mailbox-attachments'),
```

| Key | Env | Default | Description |
|---|---|---|---|
| `attachment_disk` | `MAILBOX_ATTACHMENT_DISK` | `local` | The Laravel filesystem disk where downloaded attachments are stored. You can use any disk defined in `config/filesystems.php` -- `local`, `s3`, `gcs`, etc. |
| `attachment_path` | `MAILBOX_ATTACHMENT_PATH` | `mailbox-attachments` | The base directory path within the disk. Attachments are stored in content-addressable subdirectories beneath this path for deduplication. |

Mailbox uses a content-addressable storage strategy: each attachment is hashed and stored with a collision-safe hash suffix, so identical files are never duplicated on disk.

```php
// After downloading
$file->disk; // "local"
$file->path; // "mailbox-attachments/a1b2c3/invoice-2024-003.pdf"
```

> **Tip** For production, consider using an `s3` disk so attachments survive container restarts and can be accessed across multiple servers.

## Performance & Resilience

These settings control how Mailbox handles transient failures, rate limits, and concurrent access to provider APIs.

### Retry Behavior

```php
'max_retries'        => 3,
'retry_backoff_base' => 2,
```

| Key | Env | Default | Description |
|---|---|---|---|
| `max_retries` | -- | `3` | Maximum number of retry attempts for transient API failures (429 rate limits, 5xx server errors). After exhausting retries, Mailbox throws a `RateLimitException` or `ProviderServerException`. |
| `retry_backoff_base` | -- | `2` | Base for exponential backoff between retries, in seconds. With the default of `2`, retries wait approximately 2s, 4s, 8s before giving up. |

### Concurrency Control

```php
'max_concurrent_per_mailbox' => 4,
'concurrency_lock_timeout'   => 30,
```

| Key | Env | Default | Description |
|---|---|---|---|
| `max_concurrent_per_mailbox` | -- | `4` | Maximum number of simultaneous API requests allowed per mailbox. Mailbox uses cache-based locks to enforce this across all workers and processes. Prevents a single mailbox from exhausting your provider's rate limit. |
| `concurrency_lock_timeout` | -- | `30` | Maximum time in seconds to wait for a concurrency slot before throwing a `RateLimitException`. If all slots are busy for longer than this, the request fails. |

### Queue Retry Strategy

```php
'queue_retry_strategy' => env('MAILBOX_QUEUE_RETRY_STRATEGY', 'release'),
```

| Key | Env | Default | Description |
|---|---|---|---|
| `queue_retry_strategy` | `MAILBOX_QUEUE_RETRY_STRATEGY` | `release` | Controls how Mailbox handles retryable responses inside queue jobs. `release` releases the job back to the queue so the worker is free to process other jobs while waiting. `sleep` performs inline sleep-based retries, blocking the worker. Use `release` in production. |

> **Note** The `release` strategy is strongly recommended for production. It avoids blocking queue workers during rate-limit backoffs, which can cause cascading delays across your entire queue.

### Page Size & Field Selection

```php
'default_page_size' => 50,
'default_select'    => [
    'id', 'subject', 'from', 'sender', 'toRecipients', 'ccRecipients',
    'receivedDateTime', 'sentDateTime', 'isRead', 'isDraft',
    'hasAttachments', 'importance', 'bodyPreview',
    'conversationId', 'internetMessageId', 'parentFolderId',
],
```

| Key | Env | Default | Description |
|---|---|---|---|
| `default_page_size` | -- | `50` | Number of items returned per page when listing messages or folders. Lower values reduce memory usage per request; higher values reduce the number of round-trips. |
| `default_select` | -- | See above | The default set of fields requested from the Microsoft Graph API when listing messages. Requesting only the fields you need reduces response size and improves performance. This setting applies to the MS Graph driver; Gmail handles field selection differently. |

### Immutable IDs

```php
'prefer_immutable_ids' => true,
```

| Key | Env | Default | Description |
|---|---|---|---|
| `prefer_immutable_ids` | -- | `true` | When enabled, Mailbox requests immutable message IDs from Microsoft Graph. Immutable IDs remain stable even when a message is moved between folders. Leave this enabled unless you have a specific reason to use mutable IDs. |

> **Warning** Disabling immutable IDs means message IDs can change when messages are moved between folders. This can break sync logic that relies on stable identifiers.

### Secret Expiry Warning

```php
'secret_expiry_warning_days' => 30,
```

| Key | Env | Default | Description |
|---|---|---|---|
| `secret_expiry_warning_days` | -- | `30` | Number of days before a client secret expires to trigger a `SecretExpirationWarning` [event](events.md). Mailbox checks expiry during health checks. Set this high enough to give your team time to rotate credentials. |

## Caching

Mailbox caches OAuth tokens to avoid requesting a new token on every API call. These settings control where and how tokens are cached.

```php
'cache_store'          => env('MAILBOX_CACHE_STORE'),
'cache_prefix'         => 'mailbox_token',
'token_refresh_buffer' => 300,
```

| Key | Env | Default | Description |
|---|---|---|---|
| `cache_store` | `MAILBOX_CACHE_STORE` | `null` | The Laravel cache store used for token caching. When `null`, Mailbox uses your application's default cache store. Set this to a specific store (e.g., `redis`, `memcached`) if you want token caching isolated from your application cache. |
| `cache_prefix` | -- | `mailbox_token` | Prefix applied to all token cache keys. You should not need to change this unless you have key collisions with another package. |
| `token_refresh_buffer` | -- | `300` | Number of seconds before a token's actual expiry to proactively refresh it. With the default of `300` (5 minutes), Mailbox fetches a new token when the current one has less than 5 minutes of life remaining. This prevents requests from failing with an expired token mid-flight. |

> **Tip** If you use Redis for your application cache and want to avoid token data being evicted by cache pressure, point `MAILBOX_CACHE_STORE` to a dedicated Redis connection with a `noeviction` policy.

## Logging

```php
'log_channel' => 'mailbox',
'log_level'   => env('MAILBOX_LOG_LEVEL', env('APP_DEBUG', false) ? 'debug' : 'info'),
```

| Key | Env | Default | Description |
|---|---|---|---|
| `log_channel` | -- | `mailbox` | The Laravel log channel Mailbox writes to. The package automatically registers a `mailbox` channel if one does not already exist in your `config/logging.php`. See the [logging](logging.md) page for details on customizing the channel. |
| `log_level` | `MAILBOX_LOG_LEVEL` | `debug` when `APP_DEBUG=true`, `info` otherwise | The minimum log level for the Mailbox channel. In debug mode, Mailbox logs detailed request internals, token cache behavior, and concurrency slot activity. In production, it logs only operational events like token refreshes, sync completions, and failures. |

## OAuth

Mailbox includes optional OAuth routes for user-delegated authentication flows. These are disabled by default. When enabled, Mailbox registers redirect and callback routes for both Microsoft Graph and Gmail.

### Enabling OAuth

```php
'oauth' => [
    'enabled'          => env('MAILBOX_OAUTH_ENABLED', false),
    'route_prefix'     => env('MAILBOX_OAUTH_ROUTE_PREFIX', 'mailbox/oauth'),
    'route_middleware'  => ['web'],
    'state_ttl_seconds' => 600,
    'default_return_url' => '/',
    'allowed_return_hosts' => [parse_url(env('APP_URL'), PHP_URL_HOST)],
],
```

| Key | Env | Default | Description |
|---|---|---|---|
| `oauth.enabled` | `MAILBOX_OAUTH_ENABLED` | `false` | Set to `true` to register the OAuth redirect and callback routes. When disabled, no routes are loaded. |
| `oauth.route_prefix` | `MAILBOX_OAUTH_ROUTE_PREFIX` | `mailbox/oauth` | URL prefix for the OAuth routes. With the default, routes are registered at `/mailbox/oauth/ms-graph/redirect`, `/mailbox/oauth/ms-graph/callback`, etc. |
| `oauth.route_middleware` | `MAILBOX_OAUTH_ROUTE_MIDDLEWARE` | `web` | Comma-separated list of middleware applied to the OAuth routes. Parsed from the env var as a comma-delimited string. |
| `oauth.state_ttl_seconds` | -- | `600` | How long (in seconds) the OAuth state parameter remains valid in cache. The default of 10 minutes gives users plenty of time to complete the consent flow. |
| `oauth.default_return_url` | -- | `/` | Where to redirect the user after a successful OAuth callback when no `return_to` parameter was provided on the initial redirect. |
| `oauth.allowed_return_hosts` | `MAILBOX_OAUTH_ALLOWED_RETURN_HOSTS` | Derived from `APP_URL` | Comma-separated list of hostnames allowed as `return_to` redirect targets. Prevents open-redirect attacks by restricting post-OAuth redirects to trusted hosts. Defaults to the host parsed from your `APP_URL`. |

### Microsoft Graph OAuth

```php
'oauth' => [
    'ms_graph' => [
        'redirect_uri' => env('MAILBOX_OAUTH_MS_GRAPH_REDIRECT_URI'),
        'scopes'       => [
            'openid', 'profile', 'email', 'offline_access',
            'Mail.ReadWrite',
        ],
    ],
],
```

| Key | Env | Default | Description |
|---|---|---|---|
| `oauth.ms_graph.redirect_uri` | `MAILBOX_OAUTH_MS_GRAPH_REDIRECT_URI` | `null` | An explicit override for the OAuth callback URL. When `null`, Mailbox generates the URL from the named route `mailbox.oauth.ms-graph.callback`. Set this when your app is behind a reverse proxy or load balancer that changes the public URL. |
| `oauth.ms_graph.scopes` | -- | `openid`, `profile`, `email`, `offline_access`, `Mail.ReadWrite` | The delegated permission scopes requested during the OAuth consent flow. The defaults cover user identification and full mailbox read/write access. |

### Gmail OAuth

```php
'oauth' => [
    'gmail' => [
        'client_id'     => env('MAILBOX_OAUTH_GMAIL_CLIENT_ID'),
        'client_secret' => env('MAILBOX_OAUTH_GMAIL_CLIENT_SECRET'),
        'redirect_uri'  => env('MAILBOX_OAUTH_GMAIL_REDIRECT_URI'),
        'scopes'        => [
            'openid', 'profile', 'email',
            'https://www.googleapis.com/auth/gmail.modify',
        ],
    ],
],
```

| Key | Env | Default | Description |
|---|---|---|---|
| `oauth.gmail.client_id` | `MAILBOX_OAUTH_GMAIL_CLIENT_ID` | `null` | The OAuth 2.0 client ID from the Google Cloud Console. Required when using Gmail OAuth (as opposed to service account authentication). |
| `oauth.gmail.client_secret` | `MAILBOX_OAUTH_GMAIL_CLIENT_SECRET` | `null` | The OAuth 2.0 client secret from the Google Cloud Console. Required when using Gmail OAuth. |
| `oauth.gmail.redirect_uri` | `MAILBOX_OAUTH_GMAIL_REDIRECT_URI` | `null` | An explicit override for the OAuth callback URL. When `null`, Mailbox generates the URL from the named route `mailbox.oauth.gmail.callback`. |
| `oauth.gmail.scopes` | -- | `openid`, `profile`, `email`, `gmail.modify` | The OAuth scopes requested during the Gmail consent flow. |

> **Warning** The Gmail OAuth `client_id` and `client_secret` are different from the service account credentials in `drivers.gmail`. Service account auth is for server-to-server access. OAuth is for user-delegated flows where individual users grant consent.

## Full `.env` Reference

Here is every environment variable Mailbox reads, collected in one place:

```env
# Driver
MAILBOX_DRIVER=ms-graph

# Microsoft Graph credentials
MS365_TENANT_ID=
MS365_CLIENT_ID=
MS365_CLIENT_SECRET=

# Gmail credentials
GMAIL_SERVICE_ACCOUNT_JSON=
GMAIL_SERVICE_ACCOUNT_JSON_PATH=
GMAIL_SUBJECT_EMAIL=
GMAIL_TOKEN_URI=https://oauth2.googleapis.com/token
GMAIL_API_BASE_URI=https://gmail.googleapis.com/gmail/v1/
GMAIL_SCOPES=https://www.googleapis.com/auth/gmail.modify

# Attachment storage
MAILBOX_ATTACHMENT_DISK=local
MAILBOX_ATTACHMENT_PATH=mailbox-attachments

# Queue behavior
MAILBOX_QUEUE_RETRY_STRATEGY=release

# Caching
MAILBOX_CACHE_STORE=

# Logging
MAILBOX_LOG_LEVEL=info

# OAuth (disabled by default)
MAILBOX_OAUTH_ENABLED=false
MAILBOX_OAUTH_ROUTE_PREFIX=mailbox/oauth
MAILBOX_OAUTH_ROUTE_MIDDLEWARE=web
MAILBOX_OAUTH_ALLOWED_RETURN_HOSTS=
MAILBOX_OAUTH_MS_GRAPH_REDIRECT_URI=
MAILBOX_OAUTH_GMAIL_CLIENT_ID=
MAILBOX_OAUTH_GMAIL_CLIENT_SECRET=
MAILBOX_OAUTH_GMAIL_REDIRECT_URI=
```

## What's Next

- [Logging](logging.md) -- customize the dedicated log channel and understand what Mailbox logs
- [Error Handling](error-handling.md) -- learn how retry budgets and concurrency limits translate into exceptions
- [Architecture](architecture.md) -- understand how drivers, managers, and resources fit together

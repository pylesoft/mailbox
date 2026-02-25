# Logging

Mailbox writes all of its log output to a dedicated channel so that API request traces, token lifecycle events, and sync operations stay cleanly separated from your application logs. This makes it easy to tail mailbox activity in isolation, set different retention policies, and route mailbox incidents to a dedicated alerting pipeline.

Out of the box, Mailbox registers a `mailbox` log channel automatically. You do not need to configure anything to start seeing logs.

## The Default Channel

When your application boots, Mailbox checks whether a `mailbox` channel exists in `config/logging.php`. If it does not, the package registers one with the following defaults:

```php
// Registered automatically by MailboxServiceProvider
'mailbox' => [
    'driver' => 'daily',
    'path'   => storage_path('logs/mailbox.log'),
    'level'  => config('mailbox.log_level'), // 'debug' or 'info'
    'days'   => 14,
],
```

This gives you a rotating daily log file at `storage/logs/mailbox.log` with 14 days of retention. The log level is determined by two config values:

| Condition | Effective Level |
|---|---|
| `MAILBOX_LOG_LEVEL` is set in `.env` | Uses that value exactly |
| `APP_DEBUG=true` and no `MAILBOX_LOG_LEVEL` | `debug` |
| `APP_DEBUG=false` and no `MAILBOX_LOG_LEVEL` | `info` |

## Customizing the Channel

If you want to change the driver, path, retention period, or any other aspect of the log channel, define a `mailbox` channel in your `config/logging.php` before the package boots. When Mailbox sees an existing channel, it respects your definition and does not overwrite it.

```php
// config/logging.php
'channels' => [

    'mailbox' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/mailbox.log'),
        'level'  => 'info',
        'days'   => 30,
    ],

],
```

You can use any Laravel log driver. For example, to send mailbox logs to a dedicated Papertrail endpoint:

```php
'mailbox' => [
    'driver'       => 'monolog',
    'handler'      => \Monolog\Handler\SyslogUdpHandler::class,
    'handler_with' => [
        'host' => env('PAPERTRAIL_HOST'),
        'port' => env('PAPERTRAIL_PORT'),
    ],
    'level' => 'info',
],
```

Or to stack the daily file with a Slack notification channel for errors:

```php
'mailbox' => [
    'driver'   => 'stack',
    'channels' => ['mailbox-daily', 'mailbox-slack'],
],

'mailbox-daily' => [
    'driver' => 'daily',
    'path'   => storage_path('logs/mailbox.log'),
    'level'  => 'info',
    'days'   => 30,
],

'mailbox-slack' => [
    'driver'   => 'slack',
    'url'      => env('MAILBOX_SLACK_WEBHOOK_URL'),
    'username' => 'Mailbox',
    'emoji'    => ':mailbox:',
    'level'    => 'error',
],
```

> **Tip** You can also point the `log_channel` config key to any existing channel in your application. If your team already aggregates everything into a `stack` channel, set `'log_channel' => 'stack'` in `config/mailbox.php` and Mailbox will write there instead.

## Log Levels and What Gets Logged

Mailbox follows PSR-3 log levels. The level you configure acts as a minimum threshold -- everything at that level and above is written. Here is what Mailbox logs at each level.

### `debug`

Detailed internal telemetry, useful during development and troubleshooting. This is verbose and should not be enabled in production.

- HTTP request and response details (method, endpoint, status code, duration)
- Token cache hits and misses with cache keys
- Forced token refreshes and cache invalidations
- Concurrency slot acquisition and release (slot number, wait time)
- Delta sync item-level change processing (created, updated, deleted counts per page)
- Rate limiter slot polling intervals

### `info`

Operational lifecycle events that confirm the system is working correctly. This is the recommended level for production.

- Token acquired or refreshed successfully (without exposing the token itself)
- Sync runs completed with summary counts (created, updated, deleted)
- Connection test results (success/failure, latency)
- Health check results
- Attachment downloads completed

### `warning`

Situations that are not failures but deserve attention.

- Rate limit responses received (429 status) -- logged on every occurrence, including those retried successfully
- Queue jobs released for retry after a retryable API response
- Concurrency slot wait times exceeding expected thresholds
- Secret expiration warnings approaching the configured threshold

### `error`

Failures that prevented an operation from completing.

- Token refresh failures (expired secret, revoked consent)
- API errors after retry exhaustion (4xx and 5xx)
- Access denied responses for specific mailboxes
- Attachment download failures

### `critical`

Reserved for situations that require immediate operator intervention.

- Authentication failures that disable sync for a mailbox
- Driver misconfiguration detected at runtime

## Production Recommendations

For production deployments, consider the following setup:

**Set the log level to `info`.** The default behavior already does this when `APP_DEBUG=false`, but you can be explicit:

```env
MAILBOX_LOG_LEVEL=info
```

**Increase retention for audit trails.** If your compliance requirements demand longer log retention, bump the `days` value:

```php
'mailbox' => [
    'driver' => 'daily',
    'path'   => storage_path('logs/mailbox.log'),
    'level'  => 'info',
    'days'   => 90,
],
```

**Route errors to your alerting stack.** Use a `stack` channel to write everything to a file and send errors to Slack, PagerDuty, or your preferred notification service. See the stacked channel example above.

**Use a separate log file.** The default `daily` driver already isolates mailbox logs from your application's `laravel.log`. This makes it easy to `tail -f storage/logs/mailbox.log` during incident triage without wading through unrelated application output.

> **Note** If you run multiple queue workers or servers, centralize your logs with a service like Papertrail, Datadog, or your ELK stack. File-based logs on individual servers become difficult to correlate at scale.

## Sensitive Data

Mailbox is designed to keep sensitive data out of logs. Here is what you can expect:

- **Access tokens** are never logged. Cache key names are logged, but not the token values themselves.
- **Email addresses** appear in log context as mailbox identifiers (e.g., `invoices@acme.com`). This is necessary for correlating logs to specific mailboxes.
- **Message bodies and subjects** are not logged by the package. Only message IDs and metadata counters appear in sync logs.
- **API request payloads** are not logged at any level. Only the HTTP method, endpoint path, status code, and duration are recorded.

If your organization has strict data handling requirements, audit the `debug` level output in a staging environment before enabling it in any environment that processes real user data.

> **Warning** Third-party HTTP logging middleware (like Guzzle's `MessageFormatter`) can inadvertently log full request and response bodies, including token values and email content. If you add your own Guzzle middleware, review what it captures.

## What's Next

- [Events](events.md) -- listen for the same lifecycle moments that generate log entries
- [Error Handling](error-handling.md) -- understand the exceptions behind the error-level log entries
- [Configuration](configuration.md) -- adjust `log_channel` and `log_level` settings

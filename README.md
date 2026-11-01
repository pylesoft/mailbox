# Pyle Mailbox

[![CI workflow](https://img.shields.io/badge/ci-workflow-grey)](.github/workflows/ci.yml)
[![PHPStan](https://img.shields.io/badge/phpstan-level%208-success)](phpstan.neon.dist)
[![PHP](https://img.shields.io/badge/php-8.2%2B-blue)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/laravel-12.x%20%7C%2013.x-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

**A unified, driver-based mailbox SDK for Laravel.**

## What Makes It Great

- **Shared read interfaces** -- access Microsoft Graph and Gmail through the same message, folder and attachment contracts, with optional provider capabilities for submission and notifications.
- **Fluent query builder** -- filter messages by folder, read status, date range, and free-text search with an Eloquent-like syntax.
- **Delta sync built in** -- track changes with provider-native delta tokens so you only process what is new.
- **Typed DTOs everywhere** -- every message, folder, and attachment comes back as a strict, readonly data-transfer object.
- **Retry, rate-limit, and batch** -- resilient HTTP handling with configurable backoff, concurrency locks, and queue-aware retry strategies.
- **Rule matching engine** -- evaluate inbound messages against user-defined filter rules with `MessageMatcher` and expose filterable fields to your UI.

## Quick Look

```php
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Enums\WellKnownFolder;

$invoices = Mailbox::mailbox('invoices@acme.com')
    ->messages()
    ->inFolder(WellKnownFolder::INBOX)
    ->where('isRead', false)
    ->take(25)
    ->get(); // Collection<int, MessageDto>
```

Configured application credentials or Workspace delegation power this example. For delegated user access, explicitly select your host-owned grant with `Mailbox::forGrant()`. Use bounded synchronization when you need resumable progress rather than a selected collection.

## Supported Drivers

| Driver                       | Key        | Auth Model                                       |
| ---------------------------- | ---------- | ------------------------------------------------ |
| Microsoft Graph | `ms-graph` | Configured application credentials or explicit delegated grants |
| Gmail | `gmail` | Workspace service-account delegation or explicit delegated grants |

Personal, organizational and shared-mailbox behavior depends on the selected authorization and provider permissions. The [provider SDK guide](docs/provider-sdk.md) describes implemented capabilities and host responsibilities. Account-specific live acceptance remains [blocked](docs/plans/mailbox-provider-sdk/live-acceptance.md); local fixtures do not establish provider parity or release readiness.

> **Tip** The alias key `google-workspace` resolves to the `gmail` driver, so you can use whichever name feels more natural in your configuration.

## Documentation

Full documentation lives in the [`docs/`](docs/introduction.md) directory. Here are the pages you will reach for most often:

- [Installation](docs/installation.md) -- requirements, Composer setup, publishing config and migrations.
- [Configuration](docs/configuration.md) -- every option in `config/mailbox.php`, explained.
- [Quickstart](docs/quickstart.md) -- a working example in under two minutes.
- [Messages](docs/messages.md) -- querying, reading, moving, and deleting messages.
- [Authentication](docs/authentication/ms-graph.md) -- provider-specific credential setup for MS Graph and Gmail.
- [Provider SDK](docs/provider-sdk.md) -- explicit grants, capability differences, durable host boundaries and acceptance limits.

## Contributing

1. Create a feature branch from the latest default branch.
2. Implement changes with tests and documentation updates where applicable.
3. Run the full quality suite:

```bash
vendor/bin/pest --parallel --processes=2
vendor/bin/phpstan analyse --debug --no-progress
vendor/bin/pint --dirty --format agent
composer validate --strict
composer check-platform-reqs
composer audit --locked
```

4. Open a pull request with a clear summary, migration impact, and validation notes.

The [testing guide](docs/testing.md) separates supported PHP/Laravel graphs, SDK-only and SQLite tests, serial opt-in wire checks, and actual consumer validation.

## License

Mailbox is open-source software licensed under the [MIT license](LICENSE).

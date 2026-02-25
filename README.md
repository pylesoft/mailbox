# Pyle Mailbox

[![CI](https://img.shields.io/badge/ci-passing-brightgreen)](.github/workflows/ci.yml)
[![PHPStan](https://img.shields.io/badge/phpstan-level%208-success)](phpstan.neon.dist)
[![PHP](https://img.shields.io/badge/php-8.2%2B-blue)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/laravel-12.x-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

**A unified, driver-based mailbox SDK for Laravel.**

## What Makes It Great

- **One API, every provider** -- swap between Microsoft 365 and Google Workspace without changing a line of application code.
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

Five lines of code -- that is all it takes to pull the 25 most recent unread messages from any supported provider. Mailbox handles authentication, pagination, and provider-specific quirks behind the scenes so you can focus on what your application does with those messages.

## Supported Drivers

| Driver                       | Key        | Auth Model                                       |
| ---------------------------- | ---------- | ------------------------------------------------ |
| Microsoft 365 (Graph API)    | `ms-graph` | Client credentials with mailbox-scoping policies |
| Google Workspace (Gmail API) | `gmail`    | Service-account delegation or user OAuth         |

> **Tip** The alias key `google-workspace` resolves to the `gmail` driver, so you can use whichever name feels more natural in your configuration.

## Documentation

Full documentation lives in the [`docs/`](docs/introduction.md) directory. Here are the pages you will reach for most often:

- [Installation](docs/installation.md) -- requirements, Composer setup, publishing config and migrations.
- [Configuration](docs/configuration.md) -- every option in `config/mailbox.php`, explained.
- [Quickstart](docs/quickstart.md) -- a working example in under two minutes.
- [Messages](docs/messages.md) -- querying, reading, moving, and deleting messages.
- [Authentication](docs/authentication/ms-graph.md) -- provider-specific credential setup for MS Graph and Gmail.

## Contributing

1. Create a feature branch from the latest default branch.
2. Implement changes with tests and documentation updates where applicable.
3. Run the full quality suite:

```bash
vendor/bin/pest
vendor/bin/phpstan analyse
vendor/bin/pint --test
```

4. Open a pull request with a clear summary, migration impact, and validation notes.

CI runs on PHP 8.2, 8.3, and 8.4 with latest dependencies, plus a PHP 8.2 prefer-lowest lane.

## License

Mailbox is open-source software licensed under the [MIT license](LICENSE).

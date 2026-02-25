# pylesoft/mailbox

[![CI](https://img.shields.io/badge/ci-passing-brightgreen)](./.github/workflows/ci.yml)
[![Coverage](https://img.shields.io/badge/coverage-tracked-blue)](./tests)
[![PHPStan](https://img.shields.io/badge/phpstan-level%208-success)](./phpstan.neon.dist)
[![PHP](https://img.shields.io/badge/php-8.2%2B-blue)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/laravel-12.x-red)](https://laravel.com)

Driver-based mailbox abstraction for Laravel applications.

## What This Package Provides

- Unified mailbox API across providers via contracts.
- Microsoft Graph driver for mailbox operations, sync, and attachment downloads.
- Shared DTOs, enums, models, migrations, and traits.
- Retry/rate-limiting + batching + delta sync primitives.
- Rule-matching support (`MessageMatcher`) and filter metadata for custom UIs.

## Installation

```bash
composer require pylesoft/mailbox
php artisan vendor:publish --tag=mailbox-config
php artisan vendor:publish --tag=mailbox-migrations
php artisan migrate
```

## Quickstart

```php
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Facades\Mailbox;

$messages = Mailbox::mailbox('invoices@example.com')
    ->messages()
    ->inFolder(WellKnownFolder::INBOX)
    ->where('isRead', false)
    ->take(25)
    ->get();

foreach ($messages as $message) {
    // Your application processing logic
}
```

## Commands

- `php artisan mailbox:test-access`
- `php artisan mailbox:health`
- `php artisan mailbox:folders {email} --tree`
- `php artisan mailbox:find-folder {email} {name}`
- `php artisan mailbox:sync`
- `php artisan mailbox:status`

## Supported Drivers

| Driver | Status | Notes |
| --- | --- | --- |
| Microsoft 365 (Graph API) | ✅ Supported | Client credentials flow with mailbox scoping policies |
| Google Workspace (Gmail API) | 🔜 Planned | Contracts are ready; driver implementation is planned for a future release |

## Documentation

- [Docs Index](docs/index.md)
- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Quickstart](docs/quickstart.md)
- [Usage](docs/usage/index.md)
- [Authentication](docs/authentication/index.md)
- [MS Graph Setup Guide](docs/authentication/ms-graph.md)
- [MS Graph User OAuth Guide](docs/authentication/user-oauth.md)
- [Events](docs/events.md)
- [Migration Guide](docs/migration-guide.md)
- [Extending](docs/extending/index.md)

## Development

```bash
php82 /usr/local/bin/composer install
php82 vendor/bin/pint --test
php82 vendor/bin/pest
php82 vendor/bin/phpstan analyse --no-progress
php82 vendor/bin/testbench package:test --parallel --recreate-databases
```

CI runs on PHP `8.2`, `8.3`, `8.4` (latest deps) plus a PHP `8.2` prefer-lowest lane.

## Contributing

1. Create a feature branch from the latest default branch.
2. Implement changes with tests and docs updates where applicable.
3. Run `php82 vendor/bin/pest`, `php82 vendor/bin/phpstan analyse`, and `php82 vendor/bin/pint --test`.
4. Open a PR with a clear summary, migration impact, and validation notes.

## License

MIT

# pylesoft/mailbox

Driver-based mailbox abstraction for Laravel applications.

## Features

- Unified mailbox API across providers.
- Microsoft Graph driver with token caching, retries, batching, and delta sync.
- Shared typed DTOs, enums, and Eloquent models.
- Attachment download to Laravel disks with dedup support.
- Rule matching support for message routing workflows.

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
```

## Commands

- `php artisan mailbox:test-access`
- `php artisan mailbox:health`
- `php artisan mailbox:folders {email} --tree`
- `php artisan mailbox:find-folder {email} {name}`
- `php artisan mailbox:sync`
- `php artisan mailbox:status`

## Documentation

See [`docs/`](docs) for installation, configuration, usage, extending, and troubleshooting guides.

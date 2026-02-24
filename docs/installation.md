# Installation

## Requirements

- PHP 8.2+
- Laravel 12+
- Microsoft 365 app registration with Graph API application permissions

## Install Package

```bash
composer require pylesoft/mailbox
```

## Publish Assets

```bash
php artisan vendor:publish --tag=mailbox-config
php artisan vendor:publish --tag=mailbox-migrations
php artisan vendor:publish --tag=mailbox-stubs
```

## Run Migrations

```bash
php artisan migrate
```

## Next Steps

1. Configure `config/mailbox.php` and environment variables.
2. Validate access with `php artisan mailbox:test-access`.
3. Run a health check with `php artisan mailbox:health`.

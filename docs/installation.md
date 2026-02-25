# Installation

## Prerequisites

- PHP 8.2+
- Laravel 12+
- A Microsoft 365 tenant if using `ms-graph`

## Dependency Model

This package intentionally depends on `illuminate/*` components rather than `laravel/framework` as a single dependency.
That keeps package-level dependency resolution predictable across Laravel app setups while still targeting Laravel 12 APIs.

## Install

```bash
composer require pylesoft/mailbox
```

## Publish Package Files

```bash
php artisan vendor:publish --tag=mailbox-config
php artisan vendor:publish --tag=mailbox-migrations
php artisan vendor:publish --tag=mailbox-stubs
```

## Run Database Migrations

```bash
php artisan migrate
```

## Smoke Test

```bash
php artisan mailbox:health --driver=ms-graph
```

If this fails, complete [Microsoft Graph setup](authentication/ms-graph.md) first.

## Next

- [Configuration](configuration.md)
- [Quickstart](quickstart.md)

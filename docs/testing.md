# Testing

This package uses Pest with unit, feature, and architecture suites.

## Run Tests

```bash
php82 vendor/bin/pest
```

## Static Analysis

```bash
php82 vendor/bin/phpstan analyse
```

## Coverage Focus

- Driver resolution and contract behavior
- Token caching and Graph retries
- Batching and rate-limiting
- Attachment dedup and delta sync
- Command execution and model scopes
- Architecture constraints and strict typing

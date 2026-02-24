# Testing

## Run Full Suite

```bash
php82 vendor/bin/pest
php82 vendor/bin/phpstan analyse
php82 vendor/bin/pint --test
```

## Test Categories

- Unit: DTO mapping, enums, matcher, filter compiler
- Feature: driver behavior, commands, sync, attachments, batching
- Architecture: contract and structural guardrails

## Recommended CI Gate

Fail PRs unless all three pass:

1. `pest`
2. `phpstan`
3. `pint --test`

## Next

- [Troubleshooting](troubleshooting.md)

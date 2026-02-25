# Testing

## Local Default (Memory DB)

```bash
php82 vendor/bin/pest
php82 vendor/bin/phpstan analyse
php82 vendor/bin/pint --test
```

Tests use SQLite `:memory:` by default for fast feedback.

## Parallel Runs (File DB Fallback)

When `TEST_TOKEN` is present (parallel mode), the harness switches from in-memory SQLite to per-worker files:

- `storage/framework/testing/test-{TEST_TOKEN}.sqlite`

Run parallel tests with:

```bash
php82 vendor/bin/testbench package:test --parallel --recreate-databases
```

## Lowest-Dependency Verification

CI runs a lowest-dependency lane on PHP 8.2:

```bash
composer update --prefer-lowest --prefer-stable --no-interaction --prefer-dist
php82 vendor/bin/pest
```

You can also run:

```bash
composer run test:lowest
```

## CI Matrix

- `tests-latest`: PHP `8.2`, `8.3`, `8.4` with latest locked dependencies
- `qa-static-style`: PHP `8.2` for `phpstan` + `pint --test`
- `tests-lowest`: PHP `8.2` with `--prefer-lowest --prefer-stable`

## Test Categories

- Unit: DTO mapping, enums, matcher, filter compiler
- Feature: driver behavior, commands, sync, attachments, batching
- Feature Infrastructure: test harness boot, publish tags, OAuth route gating, workbench smoke
- Architecture: contract and structural guardrails

## Recommended CI Gate

Fail PRs unless all jobs pass:

1. `tests-latest`
2. `qa-static-style`
3. `tests-lowest`

## Next

- [Troubleshooting](troubleshooting.md)

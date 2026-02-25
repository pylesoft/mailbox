# Upgrade Guide

Mailbox follows [semantic versioning](https://semver.org), so breaking changes only appear in major releases. This page will document every breaking change, deprecation, and required migration step as new versions are released. For now, the package has no breaking changes to document -- but the structure is here and ready for when that day comes.

## Checking Your Current Version

You can check which version of Mailbox you have installed at any time:

```bash
composer show pylesoft/mailbox
```

Or, if you only need the version number:

```bash
composer show pylesoft/mailbox --format=json | php -r "echo json_decode(file_get_contents('php://stdin'))->versions[0];"
```

## Watching for Updates

Stay informed about new releases so you can plan upgrades ahead of time.

### Composer Outdated

Run this periodically to check for available updates:

```bash
composer outdated pylesoft/mailbox
```

### GitHub Releases

Watch the repository on GitHub and subscribe to release notifications. Each release includes a changelog describing new features, bug fixes, and any deprecations.

### Changelog

The [CHANGELOG](https://github.com/pylesoft/mailbox/blob/master/CHANGELOG.md) in the repository root tracks every release with a summary of changes grouped by type: Added, Changed, Deprecated, Removed, Fixed, and Security.

## Upgrade Template

When a new major version is released, its upgrade notes will follow this format:

### Updating Dependencies

The required Composer dependency changes for the release.

### High-Impact Changes

Changes that affect most applications and require immediate action: renamed methods, removed classes, changed return types, or modified configuration keys.

### Medium-Impact Changes

Changes that affect applications using specific features: new required config values, changed default behaviors, or updated event signatures.

### Low-Impact Changes

Changes that affect edge cases or internal APIs: renamed internal classes, adjusted type hints, or modified protected methods in base classes.

## What's Next

- [Migration Guide](migration-guide.md) -- migrating from direct provider code to Mailbox
- [Configuration](configuration.md) -- full reference for all configuration options
- [Testing](testing.md) -- patterns for testing code that uses Mailbox

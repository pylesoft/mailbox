# Upgrade Guide

Mailbox follows [semantic versioning](https://semver.org). This page records upgrade requirements and security corrections.

## Unreleased: Authenticated Destinations and Graph Continuations

Graph continuation queries now reach the provider unchanged. Persist returned delta links as private state and resume them only for their original mailbox and folder. Placeholder tokens, foreign URLs and links targeting another resource are rejected. Malformed or cyclic pages and message-query page limits now fail explicitly instead of returning partial success.

Authenticated Graph and Gmail clients reject destinations outside their configured provider origin and base path, enforce a supplied mailbox context, and refuse redirects. A Gmail `users/me` request uses the selected mailbox's authorization. These are security exceptions to compatibility: raw clients can no longer forward credentials to arbitrary download hosts or follow redirects. Use the provider's explicit preauthenticated upload flow when supported.

Transport errors and logs no longer retain raw provider bodies, bearer headers or continuation queries. Native client and delta-result debug dumps redact secrets. The existing checkpoint property and array/JSON values remain unchanged for private persistence.

## Unreleased: Resumable Gmail Initial Synchronization

Explicit-grant Gmail resources now expose the optional `SupportsInitialSync` capability. It returns bounded message-ID evidence with separate continuations and completed checkpoints. The host retains evidence before advancing progress. See [bounded initial synchronization](delta-sync.md#bounded-gmail-initial-synchronization) for scope, baseline ordering, budgets and replay responsibilities.

Existing eager Gmail method signatures remain available. A query that reaches its page limit or repeats a page token now throws `ApiRequestException` instead of silently returning a partial collection. Eager initial delta captures its history baseline before listing and refuses completion without a baseline. Consumers that intentionally read a subset may keep using `take()`; consumers that need resumable imports should use the new capability.

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

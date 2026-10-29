# Upgrade Guide

Mailbox follows [semantic versioning](https://semver.org). Review the relevant upgrade notes before changing your installed version. The provider SDK notes below describe unreleased work and do not establish release or live-provider readiness.

## Unreleased Provider SDK

### Host-Owned Microsoft Authorization

Microsoft work/school and personal accounts may now use explicit host-owned enrollment and renewable grants. Existing configured application-permission access and legacy browser-service signatures remain available. New delegated reads use `Mailbox::forGrant()` with `RefreshingGrantTokenProvider` and `MsGraphGrantRefresher`; they do not discover or reassign legacy OAuth records or fall back to application authority. See [Microsoft enrollment and refresh](authentication/user-oauth.md#enrolling-and-refreshing-microsoft-with-host-owned-storage).

Configure the app registration and an explicit tenant GUID, `common`, `organizations` or `consumers`. The new flow requires `openid`, `profile`, `offline_access` and `User.Read` scopes plus the intended mail scopes; the default Microsoft OAuth scopes now include `User.Read`. Host state adapters must preserve the trailing optional `OAuthState::authority` field. Grant adapters must preserve the verified identity, `metadata['object_id']`, credential revision and private nonce fingerprint.

Delegated shared access requires the signed-in user's mailbox permissions and a successful read of that target. It supports delta-only discovery; required shared live read/send/watch has a separate application-grant setup contract. Enrollment, scopes and `/me` success do not establish mailbox permission or live readiness. Live-provider and shared-lock deployment acceptance remain separate gates.

### Received Addresses and Headers

Gmail message mapping now parses received addresses and decodes encoded names and subjects. For example, `"Doe, Jane" <jane@example.com>` produces one recipient named `Doe, Jane`, and `=?ISO-8859-1?Q?Andr=E9?=` becomes `André`. Quoted local parts, comments, groups, repeated recipient headers and Unicode addresses retain their mailbox values. Empty groups add no recipients. Graph's structured names and addresses remain separate, and its already decoded text is preserved.

The existing `MessageDto` and `EmailAddressDto` fields and resource interfaces remain unchanged. Recipient groups flatten into the existing recipient arrays; group names and comments remain available in the original `raw` payload. The singular `from` and `sender` fields cannot represent multiple mailboxes, so those values now fail explicitly instead of selecting or inventing an address.

Malformed address structure, truncated delimiters or encoded words, invalid encoded bytes, unsupported header charsets, and malformed provider address/header collections throw `Pyle\Mailbox\Exceptions\MailboxException`. Missing optional fields still use the existing null or empty values. Received header text must contain valid UTF-8 bytes, with other supported charsets declared in encoded words. Parsing does not perform DNS or mailbox-deliverability checks, and the parser's token limits remain explicit failures. Body hydration and outbound serialization have separate implementation and acceptance gates.

### Parser Dependency

Inbound parsing requires `zbateson/mail-mime-parser:^4.0.8` in production. Its types remain internal; applications continue using Mailbox DTOs. Run `composer update pylesoft/mailbox --with-all-dependencies` and review the resulting lock file and audit before upgrading.

Mailbox excludes `php-di/php-di` versions below `6.3.0`, a transitive parser dependency. PHP-DI 6.1 still requires the abandoned SuperClosure package; 6.2 removes that dependency but retains deprecated `ReflectionParameter::getClass()` calls. [PHP-DI 6.3](https://github.com/PHP-DI/PHP-DI/blob/6.3.0/src/Definition/Source/ReflectionBasedAutowiring.php) uses `getType()` instead. This narrow exclusion retains compatible 6.x and 7.x versions without adding another direct dependency.

### Authenticated Destinations and Graph Continuations

Graph continuation queries now reach the provider unchanged. Persist returned delta links as private state and resume them only for their original mailbox and folder. Placeholder tokens, foreign URLs and links targeting another resource are rejected. Malformed or cyclic pages and message-query page limits now fail explicitly instead of returning partial success.

Authenticated Graph and Gmail clients reject destinations outside their configured provider origin and base path, enforce a supplied mailbox context, and refuse redirects. A Gmail `users/me` request uses the selected mailbox's authorization. These are security exceptions to compatibility: raw clients can no longer forward credentials to arbitrary download hosts or follow redirects. Use the provider's explicit preauthenticated upload flow when supported.

Transport errors and logs no longer retain raw provider bodies, bearer headers or continuation queries. Native client and delta-result debug dumps redact secrets. The existing checkpoint property and array/JSON values remain unchanged for private persistence.

## Unreleased: Resumable Gmail Initial Synchronization

Explicit-grant Gmail resources now expose the optional `SupportsInitialSync` capability. It returns bounded message-ID evidence with separate continuations and completed checkpoints. The host retains evidence before advancing progress. See [bounded initial synchronization](delta-sync.md#bounded-gmail-initial-synchronization) for scope, baseline ordering, budgets and replay responsibilities.

Existing eager Gmail method signatures remain available. A query that reaches its page limit or repeats a page token now throws `ApiRequestException` instead of silently returning a partial collection. Eager initial delta captures its history baseline before listing and refuses completion without a baseline. Consumers that intentionally read a subset may keep using `take()`; consumers that need resumable imports should use the new capability.

## Gmail Body Completeness

Gmail single-message, query and legacy delta reads now hydrate externalized text and enforce response, decoded-content, MIME-part and hydration limits. Invalid base64url, inconsistent sizes, missing content and unsupported charsets throw instead of becoming empty text. `body()` also throws when no text body exists; valid empty text remains valid. See [Gmail body limits](messages.md#gmail-body-limits) for defaults and the optional concrete-resource limits.

Externalized unnamed text is no longer advertised as an attachment. Named text files and inline files retain their metadata, and inline file parts may use their Gmail part ID when no external attachment ID exists. The mandatory resource contracts and DTO constructor fields are unchanged.

## Unreleased: Bounded Gmail History

Explicit-grant Gmail resources also expose `SupportsIncrementalSync::incrementalSync(SyncScope, SyncBudget, SyncCheckpoint, ?SyncContinuation = null): ChangePage`. Reuse the initial-sync DTOs and retain evidence before advancing a continuation or completed checkpoint. The existing eager delta methods and custom-driver contracts remain available.

History returns reconciliation candidates without created-versus-updated guesses. Requests read unfiltered changes to preserve label-exit evidence; hosts must recheck the selected scope and import policy before updating projections. Label removal does not establish message deletion. A history 404 requests an initial reset with the committed checkpoint's original folder and cutoff, without erasing retained local history. See [bounded Gmail history](delta-sync.md#bounded-gmail-history) for replay, expanded-record budgets and recovery.

## Unreleased: Bounded Graph Folder Delta

Explicit-grant Graph resources now use the same optional `SupportsInitialSync` and `SupportsIncrementalSync` contracts and sync DTOs as Gmail. Graph results add trailing optional `ChangePage::coveredFolders` and `folderChanges` fields, with empty defaults for existing consumers. Mailbox-wide rounds enumerate the hierarchy and retain independent folder progress; a folder token alone never completes the mailbox.

Graph `removals` are folder-membership evidence, not verified mailbox-wide deletion. Recheck candidates against the original folder/import policy and preserve retained history during resets. Delta requests remain unfiltered to avoid Graph's documented 5,000-message filtered-delta ceiling; the cutoff stays bound to state. Existing configured/eager APIs remain available. See [bounded Graph delta](delta-sync.md#bounded-graph-folder-delta) for progress, budgets, recovery and coverage limits.

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

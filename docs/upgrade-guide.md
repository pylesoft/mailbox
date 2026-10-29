# Upgrade Guide

Mailbox follows [semantic versioning](https://semver.org). Review the relevant upgrade notes before changing your installed version. The provider SDK notes below describe unreleased work and do not establish release or live-provider readiness.

## Unreleased Provider SDK

### Consumer Compatibility and Acceptance

The [provider SDK guide](provider-sdk.md) distinguishes configured and explicit-grant paths, optional capabilities and host-owned durable state. Keep your current configured driver keys, `google-workspace` alias, constructors, raw clients, custom creators/resolvers and mandatory custom-driver interfaces. New capability checks do not require implementing unrelated methods on a legacy driver.

Review your resulting production lock and audit: inbound parsing, MIME serialization and validation are direct runtime requirements (`zbateson/mail-mime-parser`, `symfony/mime`, `egulias/email-validator`). Do not rely on development Laravel transitives. Symfony Mime requires the approved security minima `^7.4.22 || ^8.1.10`; the 8.1 branch needs PHP 8.4.1 or later, while 7.4 covers PHP 8.2/8.3.

The [local compatibility receipt](plans/mailbox-provider-sdk/compatibility.md) records isolated consumer and platform evidence. It does not publish a release, change an application's dependencies or prove provider acceptance. Required own/shared native reply/forward, 10 MiB and 20 MiB file, notification renewal and recovery cases remain in the [live acceptance ledger](plans/mailbox-provider-sdk/live-acceptance.md).

### Legacy OAuth Records and Reconnect

Keep existing connections, mailbox/message references, configuration and OAuth rows when upgrading. The additive selected-grant migration does not reconstruct ownership, bind old credentials, merge rows or delete history.

| Existing shape | Upgrade handling |
| --- | --- |
| Configured Gmail service account, including `google-workspace`, or Microsoft application credentials | Continue using the configured driver and existing model/resource APIs. These modes do not discover delegated OAuth rows. |
| `gmail-user` or `ms-graph-user` token linked to a connection | Preserve it as historical data. The old connection link is not verified ownership: earlier callbacks could overwrite it through a global subject/email lookup. |
| Unowned, duplicate-subject, email-only, expired or revoked legacy token | Preserve it and request reconnect. A matching email, subject, tenant or `user_reference` cannot recover the missing host authorization, OAuth client or verified identity evidence. |
| Explicitly selected `gmail` or `ms-graph` grant with verified identity, current generation and revision | Continue through `EloquentGrantStore`, the host's guards and the explicit runtime. Reconnect/disconnect invalidates the generation; ordinary refresh keeps it. |

Legacy `GmailUserOAuthService` and `MsGraphUserOAuthService` remain callable with their existing signatures, but enrollment now fails with `OAuthEnrollmentException` before creating state, exchanging a code or writing credentials. This is an intentional security exception to compatibility. Their browser redirect routes return a setup error; an old callback returns its safe error shape and a reconnect instruction. Safe historical `stateContext()` returns and callback-result classes remain available. The old cache `get`/later `forget` sequence is no longer an enrollment path, and raw provider error bodies are no longer exposed through it.

To reconnect:

1. Keep `MAILBOX_OAUTH_ENABLED=false` and provide host-owned initiation/callback endpoints. Use [Gmail enrollment](authentication/user-oauth.md#enrolling-gmail-with-host-owned-storage) or [Microsoft enrollment](authentication/user-oauth.md#enrolling-and-refreshing-microsoft-with-host-owned-storage).
2. Authorize the current actor, selected host connection and intended mailbox. Atomically select a new grant ID and a fresh lifecycle generation on that connection through your host's lifecycle handling. Retain earlier rows and generations; never transfer a row from another connection or recycle a generation.
3. Construct the exact `GrantContext` and use an `OAuthEnrollmentGuard`, an atomic `OAuthStateStore` and `EloquentGrantStore` (or your host's `GrantStore`). Recover the trusted context from host state in the callback, rather than query parameters or `user_reference`.
4. Complete a new provider exchange. The service verifies state, provider identity, OAuth client and, for Microsoft, tenant/issuer and Graph object identity before the conditional commit. An omitted refresh token cannot borrow from a legacy row or another connection. Failed checks leave credentials unavailable to the new runtime.
5. Read with `Mailbox::forGrant()` and a guarded token provider. A missing verified selection reports that reconnect is required; it never falls back to historical rows or configured broader authority.

There is no automatic credential-promotion API. If your application independently retained complete verified ownership and identity proof, it may provide a guarded `GrantStore` using that proof. Populating selected columns or relabeling a legacy provider does not establish it. Where the proof was never stored, reconnect is the recovery path. Package fixtures do not establish live consent, mailbox permissions or deployed shared-lock behavior.

### Host-Owned Microsoft Authorization

Microsoft work/school and personal accounts may now use explicit host-owned enrollment and renewable grants. Existing configured application-permission access remains available. Legacy browser-service signatures remain callable but require the reconnect upgrade above. New delegated reads use `Mailbox::forGrant()` with `RefreshingGrantTokenProvider` and `MsGraphGrantRefresher`; they do not discover or reassign legacy OAuth records or fall back to application authority. See [Microsoft enrollment and refresh](authentication/user-oauth.md#enrolling-and-refreshing-microsoft-with-host-owned-storage).

Configure the app registration and an explicit tenant GUID, `common`, `organizations` or `consumers`. The new flow requires `openid`, `profile`, `offline_access` and `User.Read` scopes plus the intended mail scopes; the default Microsoft OAuth scopes now include `User.Read`. Host state adapters must preserve the trailing optional `OAuthState::authority` field. Grant adapters must preserve the verified identity, `metadata['object_id']`, credential revision and private nonce fingerprint.

Delegated shared access requires the signed-in user's mailbox permissions and a successful read of that target. It supports delta-only discovery; required shared live read/send/watch has a separate application-grant setup contract. Enrollment, scopes and `/me` success do not establish mailbox permission or live readiness. Live-provider and shared-lock deployment acceptance remain separate gates.

For required shared-mailbox live setup, retain a current `GraphSubscriptionRegistration` and call the optional `SupportsGraphSharedMailboxLive::requireSharedMailboxLive()` on the same explicit resource. The captured host adapter must implement `VerifiesGraphSharedMailboxLiveAccess` to verify application authority, consent, effective scoped read/write/send/watch access and the exact subscription setup. Existing token adapters and delegated/application delta-only consumers need no new interface. The assertion performs no probe send or provider request, stores no live flag and does not replace catch-up reconciliation. See the [shared-mailbox contract and account matrix](authentication/ms-graph.md#require-shared-mailbox-live-setup); host-verified setup and executed live acceptance are separate evidence.

### Optional Selected-Grant Persistence

`EloquentGrantStore` may now back the selected-grant services using existing package connections and OAuth token models. Apply the additive migration, explicitly select a grant and generation, and retain your host guards, atomic OAuth state adapter and shared refresh lock. Existing OAuth rows are preserved and remain unselected; no subject/email lookup, ownership backfill or automatic binding is performed. Configured drivers and existing resource resolution remain available. Hosts using their own models may keep `mailbox.persistence.enabled=false` and provide their own `GrantStore`. See [optional Eloquent storage](configuration.md#storing-selected-grants-with-eloquent).

The adapter rejects stale revisions, reused revisions, revoked grants, changed ownership/generation and changed verified identity, including Microsoft's `object_id`. Ordinary refresh keeps the generation; reconnect/reactivation requires a fresh generation. Retain grant rows and their encrypted revision history to preserve reuse protection, and plan for its lifetime growth.

`MailboxOAuthToken` now hides access tokens, refresh tokens, nonce fingerprints and revision history from JSON and debug output, including legacy callback-result JSON. This is an intentional security change. Trusted code may still read model attributes directly; applications must not depend on credential values in serialized model or callback results.

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

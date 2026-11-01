# 03: Read through an explicit grant without package tables

**What to build:** Select an isolated grant and mailbox, read through the public SDK, and preserve existing configured drivers.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 3, 7, 9, 10, 11, 12, 13, 14, 15, 22, 24, 89, 90.

Introduce the smallest additive connection-bound entrypoint that makes a real mailbox read through an explicitly selected grant and target. Prove core usage without package persistence and preserve the existing configured integration.

## Acceptance criteria

- [x] A public SDK operation accepts an explicit connection, authorization grant, lifecycle generation and target mailbox, and makes an authenticated read using the selected credential provider.
- [x] Two host contexts can select independent grants for the same external account without driver-cache leakage, global configuration mutation or first-active-token lookup.
- [x] One grant can authorize multiple permitted mailbox targets; mailbox address alone never selects or overwrites credentials.
- [x] Host-owned lifecycle checks run before provider I/O. Superseded or disconnected generations fail explicitly; rejected delegated authority never falls back to a broader configured grant.
- [x] SDK-only boot and read can explicitly disable package persistence, migrations and model queries. Preserve the legacy persistence default where compatibility requires it.
- [x] Extend existing managers, drivers, clients and resolvers with optional capabilities; existing custom drivers, constructors, aliases and configured reads remain usable without new mandatory methods.
- [x] Document the host storage/lifecycle seam and unsupported-capability results without introducing application Team, Inbox, queue or browser models.

## Verification

- Read through the public SDK with real provider HTTP faked and host storage/lifecycle adapters substituted at the approved boundary.
- Boot an SDK-only environment that cannot access package tables, then test independent grants, shared-grant targets, stale generations, cache isolation and a legacy custom driver.

## Integration notes

This is the expand step. A currently valid supplied token is sufficient for this slice; enrollment and expired-token refresh are delivered separately. Keep one writer for shared contracts even when other tickets are available.

## Comments

- 2026-10-29: Claimed by the ticket 03 implementation agent on `feat/mailbox-sdk-03`, based on integration `6f09204`. Scope: additive explicit-grant reads, host lifecycle/token seam, optional persistence, and compatibility verification. Enrollment, refresh, provider setup and publication remain outside this slice.
- 2026-10-29: Implemented in `d35e283`, then merged integration through `1f059ab` (tickets 01 and 02). Final verified source: `ee1c91936cb18306774c49e4ae13ab7625250eca`. `Mailbox::forGrant(GrantContext, GrantTokenProvider, ?GuzzleHttp\Client)` returns existing provider resources through optional `SupportsGrantMailbox`; existing configured constructors, contracts, custom creators and resolver paths remain available. `mailbox.persistence.enabled=false` disables package migration registration; the default remains enabled.
- Red/green evidence: the first public read failed because the explicit context did not exist; the Gmail isolation slice failed with an unsupported capability before implementation. After ticket 01 integration, all six retained-resource/retry lifecycle cases reproduced loss of `GrantAccessDeniedException`; the narrow shared-transport exception pass-through restored all six without changing generic error redaction.
- SDK-only evidence: `tests/Sdk/ExplicitGrantReadTest.php` covers both providers, two grants for the same external account, shared-grant targets, retained-resource reconnect/disconnect, token-load and retry lifecycle changes, host ownership/target rejection, provider `401` without authority fallback, malformed tokens and optional client target restrictions. Its separate Testbench base has no migration hooks and uses the unusable `sdk-forbidden` database driver. Reads pass without package tables; registered package migration paths are absent. Cross-target client requests perform zero HTTP requests and zero token reads.
- Final verification on the verified source tree: focused SDK, transport, provider client/token, configured-driver/alias, custom-driver, resolver, harness, sync-compatibility and architecture coverage passed **127 tests / 311 assertions**, parallel with **4 workers**. The legacy tests used isolated Testbench worker SQLite files, never an application database. The Gmail RSA fixture used process-local `OPENSSL_CONF=C:/Program Files/Git/usr/ssl/openssl.cnf`; no machine setting changed. Changed-area PHPStan level 8 (including the consumed transport trait), `vendor/bin/pint --dirty --format agent`, and `git diff --check` passed. Composer installation used the existing lockfile; no dependency manifest changed.
- Host adapter usage, lifecycle duties, valid-token-only limitations and unsupported custom-driver behavior are documented in the existing [OAuth](../../../authentication/user-oauth.md#reading-with-an-explicit-grant) and [configuration](../../../configuration.md#using-your-own-persistence) pages. Enrollment, transparent refresh, actual Ngest execution, live providers, package-matrix/release gates, publication and consumer migration were not performed by this slice. The parent integration task owns broader package verification.

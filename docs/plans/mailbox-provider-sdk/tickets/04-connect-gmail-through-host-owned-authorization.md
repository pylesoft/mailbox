# 04: Connect Gmail through host-owned authorization

**What to build:** Complete delegated Gmail enrollment and read mail using the host's selected grant and lifecycle checks.

**Blocked by:** [02: Keep OAuth callback returns trusted](02-keep-oauth-callback-returns-trusted.md), [03: Read through an explicit grant without package tables](03-read-through-an-explicit-grant-without-package-tables.md)

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 1, 2, 16, 17, 18, 19.

Complete Gmail delegated enrollment through host-owned authorization and storage, ending with a real SDK mailbox read. Support personal Gmail and Google Workspace without introducing mandatory package models.

## Acceptance criteria

- [x] Host authorization guards approve initiation and callback binding for the explicitly selected connection; arbitrary user references or enabled package routes do not establish ownership.
- [x] OAuth state is expiring, single-use and bound to the initiating host context, provider, selected grant and lifecycle generation.
- [x] Exchange the code and verify provider subject, issuer and OAuth client identity before persisting selected-grant credentials. Different host contexts authorizing the same account remain independent.
- [x] Preserve an omitted refresh token only for the same selected grant and verified provider identity. A changed identity cannot inherit an earlier token; missing renewable authorization is explicit.
- [x] Persist returned rotations through guarded writes and reject callback completion after reconnect, disconnect or state replay.
- [x] Use the enrolled grant for an actual Gmail read, while preserving configured Workspace service-account access and safe callback/result fields.
- [x] Document required consent, callback binding, host storage and the actual implemented runtime capability without claiming refresh until ticket 05 is complete.

## Verification

- Exercise initiation, callback exchange, storage and a subsequent public SDK read with real OAuth/provider HTTP faked.
- Cover personal/Workspace identities, two connections for one external subject, expired/replayed/wrong-context state, host denial, omission/rotation, identity change and lifecycle changes during exchange.

## Integration notes

Reuse the trusted return policy from ticket 02 and the explicit runtime from ticket 03. Provider setup and live consent are not performed by this ticket.

## Comments

- 2026-10-29: Claimed by `mailbox_ticket_04` in `feat/mailbox-sdk-04`, based on integrated tickets 01–03 at `d9e2b882929615f925a38427051485f9603a03e4`. Implementation uses the approved Q13 real SDK/OAuth HTTP and host storage/lifecycle seams. No provider setup, live calls, publication or consumer changes are authorized.
- 2026-10-29: Implemented in `26e16ce`: additive `GmailGrantOAuthService` uses `OAuthEnrollmentGuard`, atomic `OAuthStateStore` and conditional `GrantStore`. Reuses `GrantContext` and the existing `forGrant()` / `GrantTokenProvider` / `GrantAuthorization` runtime. State binds host, connection/grant/generation, provider, client, callback, nonce, S256 verifier and expected credential revision. Private credentials and callback results have safe JSON/debug representations. `GrantContext::matches()` and `GrantIdentity::matches()` compare exact strings; reproduced numeric-looking `01` / `1` identities cannot inherit credentials or cross state bindings.
- 2026-10-29: The private identity decoder receives only the direct fixed Google token-endpoint response, with verified TLS, client authentication and redirects disabled. It checks the signed-token shape, issuer, subject, audience/authorized party, expiry, issue time and nonce; no caller JWT or profile identity fallback is trusted. Google personal/Workspace identities share this enrollment protocol. Current valid tokens power actual SDK Gmail message requests; refresh remains ticket 05. Legacy routes, result classes, service-account/application modes and configuration are unchanged.
- 2026-10-29: Verified red/green enrollment/read, invalid returned and previously stored refresh tokens, malformed unsigned ID-token shape and strict numeric-looking identities. Additional coverage verifies host denial, expiry/replay and every context binding, independent same-subject connections, exact-identity omission, rotation, subject/client/tenant changes, stale credential revisions, competing writes, disconnect/reconnect/ownership races, pinned TLS/redirect behavior, return safety and redacted failures/results.
- 2026-10-29: Required affected gate passed: `php vendor/bin/pest --parallel --processes=4 --compact --filter 'GmailGrantEnrollmentTest|ExplicitGrantReadTest|DriverResolutionTest|OAuth|GmailClientTest|GmailTokenManagerTest|GraphClientTest|TokenManagerTest|ArchitectureTest|StrictTypesAndPhpStanTest|TypeCoverageTest'` — 267 passed, 1,548 assertions. Executed with the explicit PHP 8.4.25 binary on Windows to preserve the filter; process-local `OPENSSL_CONF` points to Git's OpenSSL configuration for the existing RSA fixtures. SDK enrollment tests forbid all database access. Legacy tests use only inspected Testbench SQLite memory/owned worker files and `RefreshDatabase`.
- 2026-10-29: Changed-area PHPStan level 8, `pint --dirty --format agent`, staged/untracked-inclusive `git diff --cached --check`, strict Composer validation and installed-platform requirements passed. Locked install changed no dependency files. Integration tip `d9e2b88` merged before completion (already up to date). Documentation in `docs/authentication/user-oauth.md` records adapters, private storage, consent and callback requirements, precise identity trust and missing refresh capability. Live providers, deployed atomic host storage, supported-platform matrix and actual consumers remain their separate gates; this closure claims package fixture behavior only.
- 2026-10-29: Final post-merge SDK gate `php vendor/bin/pest tests/Sdk --parallel --processes=4 --compact` passed: 85 tests, 260 assertions, with package database access forbidden.

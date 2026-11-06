# 05: Refresh delegated grants safely across workers

**What to build:** Refresh before Gmail access under shared per-grant coordination, preserving identity and rejecting stale writes.

**Blocked by:** [04: Connect Gmail through host-owned authorization](04-connect-gmail-through-host-owned-authorization.md)

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 18, 19, 20, 21, 22, 23, 24.

Make an expired Gmail delegated grant usable safely when several workers or mailbox targets request it simultaneously. Establish the shared refresh contract that Microsoft and optional persistence will reuse.

## Acceptance criteria

- [x] Coordinate delegated refresh using one shared atomic lock per grant across processes and mailbox targets, with the host's configured shared cache-lock store.
- [x] Failed, unavailable or contended coordination returns a retryable failure and issues no refresh exchange. A process-local array store or mailbox-only lock cannot satisfy deployment requirements.
- [x] Reload credentials under the lock and conditionally commit only when selected identity, expected refresh revision and reconnect/disconnect generation still match.
- [x] Preserve an omitted refresh token only for the same verified grant, subject, issuer/tenant and OAuth client; store rotations atomically and reject identity changes without renewable credentials.
- [x] Ordinary refresh preserves the connection generation. Reconnect/disconnect invalidates cached authority and rejects stale refresh commits and subsequent provider phases.
- [x] Distinguish transient refresh failure from revoked, invalid or insufficient delegated authorization, without implicit fallback to configured application authority.
- [x] Return control and retry information to the host without releasing its enclosing job, leaking secrets or introducing a package-owned refresh workflow.
- [x] Document shared-store prerequisites and retain existing configured authentication modes.

## Verification

- Use controlled real HTTP exchanges and state changes to prove lock/reload/refresh/read behavior, a single exchange for competing targets, rotation and stale-write rejection.
- Test unavailable/failed/contended locks with zero exchanges, same-identity omission and negative subject/tenant/client changes.
- Keep deterministic package adapter tests separate from verification that a deployed host actually provides a shared cross-process lock store.

## Comments

- 2026-10-29: Claimed by mailbox_ticket_05 on `feat/mailbox-sdk-05`, based on integrated tickets 01-04 at `d881c0a79a038ee38a7adc1ae4915188037b21ab`. Implement shared guarded refresh and Gmail at the approved Q13 HTTP/host-adapter seams; preserve existing configured modes and leave deployed shared-store proof to the live acceptance gate. No publication, provider operations, consumer changes or active database access.
- 2026-10-29: Implemented in `977bac6`; host wiring documented in `589b284`. Merged integration through ticket 12 (`9161f2ddc0fb1c4ff358265415394d6ef2c4467f`) as `af7c5474488c1324a2610fc4e3a284145bcba8d4`. `GrantAccessGuard::assertActive()` is inherited by the unchanged two-method `GrantTokenProvider` API. `RefreshingGrantTokenProvider(guard, grants, locks, refresher)` combines host lifecycle/storage ports with `GrantRefreshLock` and `GrantRefresher`. `CacheGrantRefreshLock` requires an explicit named host store, rejects array/file/null/failover/non-locking stores, and keys locks by the exact connection/grant tuple across targets, revisions and generations. Shared transport preserves only the new safe `GrantRefreshException` alongside its existing access-denied exception; unknown legacy errors remain redacted.
- 2026-10-29: Real SDK/HTTP scenarios prove refresh then Gmail read without package tables, lock contention with one winning exchange, reloading current or newly rotated credentials under the lock, independent grants for the same external identity, rotation/omission, strict identity rejection, conditional-write and revision races, reconnect/disconnect, zero provider reads after denial, safe retry information and no enclosing-job release. Coordination/transport/429/5xx/temporary failures are retryable; delegated invalidity is access denied, while client setup and malformed credentials are non-retryable refresh errors. Existing configured service-account/application and custom-driver paths remain available.
- 2026-10-29: Final source correction `6637f91e3db7bc447e8f7b5dcbe7ba6d23331e7d` preserves the original enrollment nonce as an optional private trailing `StoredGrant` constructor field `nonceHash`, exposed only by `nonceHash()` and omitted from JSON/debug output. Same-secret enrollment omission keeps its prior fingerprint; a new enrollment secret records the current nonce fingerprint. Ordinary refresh and rotation carry that original evidence. Matching or omitted refresh nonce claims are accepted; mismatch or a missing verification baseline is rejected. End-to-end enrollment/refresh regressions and existing enrollment omission/rotation cases prove these rules.
- 2026-10-29: Verification: the full package suite at `af7c547` passed **572 tests / 2549 assertions** with four workers in 202.21 seconds before the later resource constraint. After the nonce correction, the affected enrollment/refresh suites passed **98 tests / 357 assertions** with two workers. Full PHPStan passed after the correction using `--debug` to keep one process; Pint, strict Composer validation, installed platform requirements and `git diff --check` passed. Tests used the SDK-forbidden database harness or the previously inspected isolated Testbench SQLite targets; no active application database or Workbench build was used. No extra full-suite rerun was needed for the focused nonce delta.
- 2026-10-29: Package acceptance is complete. Deterministic cache/host doubles establish adapter behavior, not a deployed central-store topology, cross-server lock proof or provider acceptance. Actual host shared-store deployment evidence, live Google consent/refresh and the declared account/provider matrix remain separate acceptance gates. No live provider operation, consumer change, publication, push or deployment was performed.

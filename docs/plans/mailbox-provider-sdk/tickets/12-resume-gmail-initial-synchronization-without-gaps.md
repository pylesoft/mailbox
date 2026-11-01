# 12: Resume Gmail initial synchronization without gaps

**What to build:** Establish the shared paged-change contract through a resumable Gmail initial scan with a safe baseline.

**Blocked by:** [03: Read through an explicit grant without package tables](03-read-through-an-explicit-grant-without-package-tables.md)

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 26, 34, 37, 38, 39, 40, 41, 42, 46, 47.

Introduce bounded change pages through a complete Gmail initial-import path. A host can retain each page and resume without mistaking a capped or interrupted scan for completion.

## Acceptance criteria

- [x] Capture a safe Gmail history baseline before initial listing so arrivals during the scan remain available for reconciliation.
- [x] Honor the host's explicit mailbox/scope, import cutoff and work budgets without imposing retention policy.
- [x] Return change work, unfinished continuation and completed synchronization checkpoint as distinct outcomes; only a finished round can produce completion.
- [x] Bind continuation/checkpoint state to provider, selected grant identity/generation, target mailbox, scope/import policy and format version; reject cross-target or unsupported reuse.
- [x] Report page ceilings, repeated/missing tokens, response limits and interrupted work honestly. Empty pages and duplicates cannot manufacture completion.
- [x] Allow the host to retain page work durably before promoting its continuation. Replay after interruption must not skip uncommitted work.
- [x] Expose upserts and removal/reset semantics through optional capabilities without breaking legacy eager collection methods.
- [x] Document the first shared bounded-progress contract, baseline ordering and host checkpoint responsibilities.

## Verification

- Reproduce the current page-ceiling false completion and concurrent-arrival/baseline-after-scan risks using real Gmail query and transport paths.
- Exercise multi-page imports, empty/duplicate pages, interrupted retention/resume, cyclic or missing tokens, budgets and cross-grant/mailbox/scope checkpoint rejection.

## Integration notes

This slice establishes the shared paging contract. Incremental history and provider-specific Graph delta behavior remain separate slices; unfinished content must remain explicit when encountered.

## Comments

- 2026-10-29: Claimed by `mailbox_ticket_12` on `feat/mailbox-sdk-12`, based on integration `d9e2b882929615f925a38427051485f9603a03e4`, with no upstream. Implementation is limited to the first shared bounded-progress contract and Gmail initial synchronization. Q13's approved SDK/real-Guzzle HTTP-fake seam applies; the SDK test base forbids database access. No provider, consumer, publication or production operations are authorized.
- 2026-10-29: Implemented in `f6deef1099c6fdd8d9d557a086f38a8392e04004`, reused ticket 04's strict identity comparison in `948d890f3e99689210d857ef0794fbc09e020632`, and completed response-boundary fixes in `6d9e5a5a8d12f479de71fde09f64e83bd5e5fd5d`. Integration `0e68d17dd6511f942891a22e0f541697bc6c4e31` is merged through `75f4b9c`; final merge check returned already up to date. The legacy eager initial path now captures its baseline first and throws on capped/cyclic or unusable-token listings; the additive bounded path retains resumable ID evidence and never promotes unfinished work.
- 2026-10-29: Public contract: optional `SupportsInitialSync::initialSync(SyncScope, SyncBudget, ?SyncContinuation = null): ChangePage` on explicit-grant Gmail resources. `SyncIdentity` binds provider, `GrantContext`, scope and format version; `SyncScope` selects optional folder and whole-second import cutoff; `SyncBudget` bounds pages, IDs, requests and aggregate response bytes. `ChangePage` exposes ID upserts/removals and exactly one continuation, completed `SyncCheckpoint` or reset requirement. Initial listing emits no inferred removals. Provider clients expose `getBounded(string $endpoint, ResponseBudget $budget, array $query = [], ?string $mailbox = null): array`; a shared `Support\ResponseBudget` counts actual bytes before decoding or error mapping and bounded reads make one HTTP attempt.
- 2026-10-29: Final affected gate on source `6d9e5a5`: `php vendor/bin/pest --parallel --processes=4 --compact --filter='GmailInitialSyncTest|GmailDeltaSyncTest|ExplicitGrantReadTest|ArchitectureTest'` passed 79 tests / 251 assertions. The transport/client/query gate passed 58 tests / 198 assertions before the final one-line null-list correction: `--filter='GmailInitialSyncTest|PerformsApiRequestsTest|GmailClientTest|GraphClientTest|GmailMessagePageCollectorTest|GmailMessageQueryTest'`. The new null-list SDK regression was independently observed red (no exception), then green after the correction. Oversized 200 and 503 streams prove aggregate actual-byte enforcement, stream closure and safe replay; small 503 errors retain typed failure without hidden retries. Tests use only SDK-forbidden database access or Testbench's isolated SQLite memory/worker files with four workers. Process-local `OPENSSL_CONF=C:/Program Files/Git/usr/ssl/openssl.cnf` supplies RSA fixtures; no Workbench build or destructive MySQL command ran.
- 2026-10-29: Final full PHPStan level 8, `php vendor/bin/pint --dirty --format agent`, `composer validate --strict`, `composer check-platform-reqs`, and `git diff --check` passed. The recovered earlier full-suite receipt was 499 tests / 2318 assertions before ticket 09's dependency integration; it is not a final post-09 full-suite claim. The parent owns the combined full integration gate after merging this branch. Incremental history, Graph delta, live accounts and actual consumer compatibility remain separate tickets; no remote, provider or consumer changes were made. `docs/delta-sync.md` and `docs/upgrade-guide.md` record host retention-before-progress responsibilities and the eager-list security/correctness change.

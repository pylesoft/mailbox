# 24: Enforce shared-mailbox live-mode capabilities

**What to build:** Use one explicit application grant for shared live read/send/watch and report missing setup without elevation.

**Blocked by:** [19: Prepare and submit a Graph draft safely](19-prepare-and-submit-a-graph-draft-safely.md), [23: Maintain and validate basic Graph subscriptions](23-maintain-and-validate-basic-graph-subscriptions.md)

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 6, 12, 24, 86, 87, 88.

Make required live Microsoft shared-mailbox operation an explicit capability contract. A valid setup uses one selected application-permission grant for read, send and watch.

## Acceptance criteria

- [x] Validate supported push setup, tenant consent, scoped mailbox access and required read/send/watch authority for the explicitly selected application grant and shared mailbox.
- [x] Demonstrate shared-mailbox reading, body-only draft submission and subscription registration through the same grant/target context.
- [x] Return a clear setup/capability error when requested live mode lacks a supported subscription or required permission; token acquisition alone is insufficient proof.
- [x] Never combine a user's delegated authority with a broader application grant or fall back after delegated rejection.
- [x] Keep authorized delegated/application delta-only shared-mailbox usage available when live mode is not requested, reporting its actual reduced discovery capability.
- [x] Retain catch-up reconciliation in live mode because push delivery can be missed; server discovery capability does not dictate host browser-update implementation.
- [x] Preserve lifecycle invalidation, secret handling and host ownership of durable orchestration, and document the complete account/authentication capability matrix.
- [x] Keep shared native reply/forward and large-file parity claims gated by combined and live validation rather than inferring support from basic text operations.

## Verification

- Use real SDK paths with controlled Graph HTTP to verify one selected application grant across shared read, preparation/send and subscription registration.
- Test missing consent, insufficient scope/mailbox access, unsupported push, delegated live requests and delta-only success; assert no implicit authority elevation.

## Integration notes

This slice enforces setup and provider behavior. It does not grant administrator consent, configure a tenant, provision callbacks or implement AI Clerk browser events.


## Comments

- 2026-10-29: Claimed by ticket 24 implementer on isolated branch feat/mailbox-sdk-24, based on integration 11c32b90dc4c060fc3c6ed5c5f2d46360d7b73ee. Approved seam: public SDK resource through actual Graph client/mapping with controlled HTTP and forbidden SDK database. No live provider operations or setup. Heavy checks wait for the root slot.
- 2026-10-29: The approved Q5 host boundary is an optional captured `VerifiesGraphSharedMailboxLiveAccess` adapter capability. `SupportsGraphSharedMailboxLive::requireSharedMailboxLive()` takes an active, unexpired, context-bound registration and asks that captured adapter to verify application authority, consent, scoped read/write/send/watch access and its exact retained configuration. No mandatory interface, opaque-token parsing, mixed grants, provider probes or durable live flag. Existing lifecycle guards still protect provider I/O; the host owns availability policy and setup rechecks.
- 2026-10-29: Initial TDD evidence: token-only public request RED (missing method) then GREEN; explicit subscription/read/body-only draft/send tracer RED (host verifier not invoked) then GREEN. Six foreign-context/inactive-registration cases reproduced missing rejection, then passed after the exact-context and active-state guard. Initial focused file: 9 tests, 50 assertions, exit 0; further prerequisite, configuration, lifecycle and catch-up regressions are prepared for the next shared check slot.
- 2026-10-29: Completed public protocol coverage: 21 tests, 123 assertions, Pest with two workers, exit 0. Missing application mode, consent, scoped read/write/send/watch authority or supported push returns the host's clear setup failure without token acquisition or HTTP; delta-only access remains available through that same authorized adapter. Retained tenant/canonical mailbox/folder/endpoints must match host-verified setup. Before/during-verification lifecycle invalidation fails explicitly. The positive path uses one selected grant for an explicit subscription, mapped read and body-only draft/send, then performs initial/incremental catch-up even after a removed subscription fails a fresh setup check. No setup check itself probes or sends mail.
- 2026-10-29: Final affected checks: explicit 10-file selection covering shared live setup, Graph subscriptions, draft/native submission, bounded sync, explicit-grant reads, driver/direct-constructor compatibility and architecture; 351 tests, 2,141 assertions, Pest with two workers, exit 0. SDK DB access is forbidden; legacy/architecture checks use only isolated Testbench SQLite. Full-source PHPStan `--debug --no-progress` passed in one process. Targeted PHP Pint and `--dirty --format agent` passed; formatting changed import order only. Composer strict validation, platform requirements and locked audit passed (zero advisories); Composer files unchanged. Current integration `11c32b90dc4c060fc3c6ed5c5f2d46360d7b73ee` was merged before closure (already up to date).
- 2026-10-29: Closed for the approved host-authorization and controlled-provider seams. The account/authentication matrix distinguishes delta-only discovery, configured/host-verified live setup and executed live acceptance. Official Microsoft subscription, staged draft/send and effective Application RBAC/Entra scope docs informed the contract. No tenant setup, provider live operation, push, PR, release, consumer migration, production write, SDK-owned durable receipt or scheduling was performed. Actual account permissions, callback/recipient delivery and shared native/file parity remain separate ticket 27 gates.

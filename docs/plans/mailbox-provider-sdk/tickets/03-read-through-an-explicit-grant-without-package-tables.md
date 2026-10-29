# 03: Read through an explicit grant without package tables

**What to build:** Select an isolated grant and mailbox, read through the public SDK, and preserve existing configured drivers.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 3, 7, 9, 10, 11, 12, 13, 14, 15, 22, 24, 89, 90.

Introduce the smallest additive connection-bound entrypoint that makes a real mailbox read through an explicitly selected grant and target. Prove core usage without package persistence and preserve the existing configured integration.

## Acceptance criteria

- [ ] A public SDK operation accepts an explicit connection, authorization grant, lifecycle generation and target mailbox, and makes an authenticated read using the selected credential provider.
- [ ] Two host contexts can select independent grants for the same external account without driver-cache leakage, global configuration mutation or first-active-token lookup.
- [ ] One grant can authorize multiple permitted mailbox targets; mailbox address alone never selects or overwrites credentials.
- [ ] Host-owned lifecycle checks run before provider I/O. Superseded or disconnected generations fail explicitly; rejected delegated authority never falls back to a broader configured grant.
- [ ] SDK-only boot and read can explicitly disable package persistence, migrations and model queries. Preserve the legacy persistence default where compatibility requires it.
- [ ] Extend existing managers, drivers, clients and resolvers with optional capabilities; existing custom drivers, constructors, aliases and configured reads remain usable without new mandatory methods.
- [ ] Document the host storage/lifecycle seam and unsupported-capability results without introducing application Team, Inbox, queue or browser models.

## Verification

- Read through the public SDK with real provider HTTP faked and host storage/lifecycle adapters substituted at the approved boundary.
- Boot an SDK-only environment that cannot access package tables, then test independent grants, shared-grant targets, stale generations, cache isolation and a legacy custom driver.

## Integration notes

This is the expand step. A currently valid supplied token is sufficient for this slice; enrollment and expired-token refresh are delivered separately. Keep one writer for shared contracts even when other tickets are available.


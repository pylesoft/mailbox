# 07: Persist selected grants with optional Eloquent storage

**What to build:** Store and reload explicit grants with guarded refresh while SDK-only usage remains database-free.

**Blocked by:** [05: Refresh delegated grants safely across workers](05-refresh-delegated-grants-safely-across-workers.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 8, 13, 14, 15, 19, 20, 22.

Make the selected-grant lifecycle work through optional Eloquent persistence, while applications with their own models retain database-free SDK usage.

## Acceptance criteria

- [ ] Persist and reload explicit grant ownership, verified provider identity, token expiry/rotation, refresh revision and lifecycle generation without conflating mailbox and authorization identity.
- [ ] Implement guarded credential replacement with actual atomic persistence semantics and cooperate with the host-provided per-grant refresh lock.
- [ ] Two independent host contexts authorizing the same external account retain separate records; one selected grant can serve multiple permitted mailbox targets.
- [ ] Use additive, data-preserving schema and configuration changes. Keep existing model/resource resolution and configured consumer behavior compatible.
- [ ] Allow explicit SDK-only disablement without package model queries or migration loading; preserve the existing Eloquent integration default where required.
- [ ] Document optional installation, storage configuration and the separation between database concurrency control and the required shared refresh lock.

## Verification

- Identify Testbench's isolated SQLite memory or worker-file targets and destructive test setup before database-backed tests.
- Exercise enrollment-shaped persistence, reload, rotation and competing stale revision/generation writes through real Eloquent storage.
- Run a separate SDK-only boot/read case without automatically migrating package tables. Use parallel execution with at most eight workers when running more than ten tests.

## Integration notes

No application database, production credential store or active consumer checkout may be migrated or modified by these checks.


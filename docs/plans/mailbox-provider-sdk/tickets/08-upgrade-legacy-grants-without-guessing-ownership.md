# 08: Upgrade legacy grants without guessing ownership

**What to build:** Retain legacy records and configured integrations; only explicitly bound or reconnected grants enable new delegated access.

**Blocked by:** [07: Persist selected grants with optional Eloquent storage](07-persist-selected-grants-with-optional-eloquent-storage.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 89, 90, 91.

Provide a safe upgrade path for existing OAuth records whose ownership cannot be reconstructed. Preserve data and require explicit binding or reconnection before new delegated runtime use.

## Acceptance criteria

- [ ] Inventory legacy identity/configuration shapes and distinguish verifiably bound records from ambiguous or unowned credentials.
- [ ] Preserve historical records and references. Do not guess ownership, merge grants, reassign by provider subject/email or delete credentials during an upgrade.
- [ ] Exclude ambiguous records from the new connection-bound delegated runtime with a clear remediation result.
- [ ] Allow explicit authorized binding or reconnection that verifies the selected host context and provider identity before enabling access.
- [ ] Prevent one connection's upgrade/reconnect from stealing another context's authorization; keep generation invalidation and guarded writes intact.
- [ ] Preserve existing configured service-account/application integrations and safe legacy consumer access where promised.
- [ ] Document the upgrade steps, nonrecoverable ownership ambiguity and intentional security exceptions without claiming lost ownership can be inferred.

## Verification

- Upgrade representative isolated SQLite legacy fixtures and assert record/reference preservation before and after explicit binding or reconnect.
- Test duplicate provider subjects across contexts, unowned records, unauthorized binding, identity mismatch and configured-mode compatibility through the public runtime.


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

## Comments

- 2026-10-29: Claimed by `mailbox_ticket_08` on `feat/mailbox-sdk-08`, based on integration `9fc2b5f0a78b0a53c279701146318e41c6d605ee`; ancestry verified, clean starting tree and no upstream. Read the tracker, glossary, ADRs, plan/spec and actual selected storage/enrollment contracts. `.ai/rules` is absent. Applying `matt:tdd` at the preapproved public SDK/provider HTTP and real isolated SQLite seams. No application database, provider, dependency setup or check started; shared heavy-check slot is queued.
- 2026-10-29: Source-fit security decision approved by the parent implementation task: the legacy service signatures cannot prove trusted host ownership or a selected generation. Retain callable shims, safe historical return-context reads and callback/result extensions, but stop legacy enrollment before state creation, exchange or credential writes. Remove the unsafe global subject/email upsert and unverified-claim fallback rather than retaining dead paths. Reconnect uses the existing guarded Gmail/Microsoft enrollment services and selected `GrantStore`; no credential-promotion or migration framework is added.
- 2026-10-29: Inspected `phpunit.xml`, `TestCase` and `ParallelTestingDatabase` before database checks: serial tests target only SQLite `:memory:`; parallel checks use owned Testbench worker SQLite files. `RefreshDatabase` may rebuild only that isolated schema. Serialized unchanged-lock setup installed 145 packages. The first callback regression was RED for both providers (expected setup exception absent), then GREEN: 2 tests / 10 assertions, 0.66s. It proves no provider HTTP, byte-for-byte raw historical owned/unowned token preservation and safe historical `stateContext()` access. An intermediate mechanical-edit parse error was fixed before GREEN. The slot was released after this bounded bootstrap; initiation and reconnect coverage remain pending.
- 2026-10-29: Initiation/controller RED reproduced both legacy routes returning HTTP 302; GREEN now returns HTTP 400 `oauth_setup_required` JSON with reconnect guidance, no state creation, provider HTTP or credential/connection mutation (2 tests / 16 assertions, 0.70s). The services retain their signatures and return-context helpers while removing the unreachable exchange, global upsert, unverified-claim, state-creation and unused setup helpers. Historical callback errors preserve safe return behavior; successful custom service/result/controller extensions remain available.
- 2026-10-29: The public Eloquent upgrade/enrollment/runtime/reconnect scenario passed for both providers (2 tests / 88 assertions, 0.84s). In isolated SQLite it reverses only the additive selected-grant migration, creates representative owned, duplicate-subject, unowned, email-only and revoked old rows plus message/mailbox references and encrypted configuration, then reapplies the migration. Existing guarded enrollment creates two independent same-account grants; reconnect selects a fresh grant/generation, rejects the old resource and leaves the other context usable. Every original credential row remains byte-for-byte unchanged, including foreign keys and metadata, and the superseded selected row/revision evidence is retained. Hydrated database snapshots include original defaults and column order. These fixtures do not prove missing ownership can be inferred.


# 07: Persist selected grants with optional Eloquent storage

**What to build:** Store and reload explicit grants with guarded refresh while SDK-only usage remains database-free.

**Blocked by:** [05: Refresh delegated grants safely across workers](05-refresh-delegated-grants-safely-across-workers.md)

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 8, 13, 14, 15, 19, 20, 22.

Make the selected-grant lifecycle work through optional Eloquent persistence, while applications with their own models retain database-free SDK usage.

## Acceptance criteria

- [x] Persist and reload explicit grant ownership, verified provider identity, token expiry/rotation, refresh revision and lifecycle generation without conflating mailbox and authorization identity.
- [x] Implement guarded credential replacement with actual atomic persistence semantics and cooperate with the host-provided per-grant refresh lock.
- [x] Two independent host contexts authorizing the same external account retain separate records; one selected grant can serve multiple permitted mailbox targets.
- [x] Use additive, data-preserving schema and configuration changes. Keep existing model/resource resolution and configured consumer behavior compatible.
- [x] Allow explicit SDK-only disablement without package model queries or migration loading; preserve the existing Eloquent integration default where required.
- [x] Document optional installation, storage configuration and the separation between database concurrency control and the required shared refresh lock.

## Verification

- Identify Testbench's isolated SQLite memory or worker-file targets and destructive test setup before database-backed tests.
- Exercise enrollment-shaped persistence, reload, rotation and competing stale revision/generation writes through real Eloquent storage.
- Run a separate SDK-only boot/read case without automatically migrating package tables. Use parallel execution with at most eight workers when running more than ten tests.

## Integration notes

No application database, production credential store or active consumer checkout may be migrated or modified by these checks.

## Comments

- 2026-10-29: Claimed by the ticket 07 implementer on `feat/mailbox-sdk-07`, based on integration `d6c529fc64413d38292fc5d7e1ce06aa0c0feb23`, with no upstream. Applying `matt:tdd` at the approved Q13 public grant-store/isolated-SQLite seam and preserving the SDK-only forbidden-database seam. Inspected `TestCase`, `ParallelTestingDatabase`, `phpunit.xml` and `testbench.yaml`: serial tests target SQLite `:memory:`; parallel tests target only Testbench-owned `storage/framework/testing/test-<TEST_TOKEN>.sqlite` files. `RefreshDatabase` may rebuild that isolated schema. No Workbench build, application database, live provider or consumer execution is included. Dependency setup and checks wait for the root's sole heavy-check slot.
- 2026-10-29: Implemented in `b7b78edd1be91875f6a733dab1974e80dada1038`, after merging integration `280fcf65c3557cd0c88e28af74f8e264123096ac` (tickets 14 and 16). `Support\EloquentGrantStore` explicitly implements the existing `GrantStore` port with the existing connection/token models and a nullable additive migration. Connections select `selected_grant_id` and `lifecycle_generation`; selected token rows retain exact verified identity, revision, expiry/scopes, metadata including Microsoft's `object_id`, and encrypted tokens/private nonce fingerprint. There is no default store binding, boot-time model query, subject/email selection, legacy backfill, OAuth-state adapter or host-model framework. Existing configured drivers, aliases and model/resource resolvers remain unchanged.
- 2026-10-29: Replacement holds connection/token locks inside a transaction, checks exact opaque identities in PHP despite database collations, and uses an actual conditional insert-select/update for connection ownership/selection/generation and expected token identity/revision/revocation. The initial no-op connection update acquires SQLite write ownership; its affected-row count is deliberately ignored because MySQL may report zero. Ordinary rotation keeps the generation. Revocation or supersession cannot be restored with a stale or null expectation; reactivation needs a fresh host generation. A hidden encrypted `LONGTEXT` list retains accepted revision fingerprints to reject ABA/reuse, including across reactivation. Keep those rows/history and plan for lifetime growth; this is not a replacement for the host's shared per-grant refresh lock.
- 2026-10-29: TDD evidence: first real persistence tracer failed for the missing adapter, then passed. Further RED cases reproduced a changed verified Microsoft `object_id` being accepted and an earlier provider's credentials being returned after a driver change; both now fail closed. Final `php vendor/bin/pest tests/Feature/Services/EloquentGrantStoreTest.php --parallel --processes=2 --compact` passed **17 tests, 128 assertions**, 7.84s. Cases cover private round-trip/nonce/object metadata, refresh rotation, stale/null/ABA conflicts, identity checks, revoked/disabled/deleted/deselected/changed-generation contexts, integer `01` aliases, same-account independent contexts with the same opaque grant ID, one grant across targets, real HTTP-fake refresh and a persistent lifecycle change during provider I/O with no mailbox request. The isolated additive-upgrade fixture reverses only this new migration, creates old-shape rows, reapplies it and proves credentials, foreign key and configured values are preserved without inferred binding.
- 2026-10-29: Final compatibility gate on the same merged source passed **298 tests, 1,795 assertions**, 64.58s, with two workers: `php vendor/bin/pest --filter='(ModelScopesTest|OAuthFlowTest|TrustedOAuthReturnsTest|DriverResolutionTest|HasMailboxTest|TestHarnessTest|ExplicitGrantReadTest|GrantRefreshTest|Tests\\Arch\\)' --parallel --processes=2 --compact`. This covers existing OAuth/model/resolver behavior, both delegated refresh paths, SDK-only forbidden-database reads and architecture checks. ParaTest rejects multiple positional paths, so the bounded class filter was used. Tests used only the inspected SQLite targets and process-local `OPENSSL_CONF`; no active application database was used.
- 2026-10-29: Final full `php vendor/bin/phpstan analyse --no-progress --debug` passed in one process after removing one redundant nullsafe operator inside `??` (equivalent behavior). `php vendor/bin/pint --dirty --format agent`, `composer validate --strict`, `composer check-platform-reqs` and `git diff --check` passed. The latest lock changed requirement metadata only; Composer confirmed zero install/update/remove operations. Pint changed only two model line endings. No full Pest suite was run; the root owns the combined gate.
- 2026-10-29: Updated configuration and upgrade documentation covers explicit installation/selection, canonical integer connection IDs, storage/authorization/state/lock responsibilities, lifecycle versus refresh, revision-history retention/growth and an intentional serialization security change. Token values are now excluded from model JSON/debug output, including legacy callback-result JSON; trusted direct attribute access remains supported. Each credential/nonce/history privacy assertion checks one value independently. SQLite fixtures do not establish deployed MySQL or cross-process lock behavior. Live providers, active consumers, production data, publication and deployment remain outside this ticket; legacy binding/upgrade policy belongs to ticket 08.

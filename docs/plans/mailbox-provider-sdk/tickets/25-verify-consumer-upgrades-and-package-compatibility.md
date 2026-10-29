# 25: Verify consumer upgrades and package compatibility

**What to build:** Pass production/lowest-version gates, isolated Ngest compatibility and SDK-only/host-adapter contracts with accurate docs.

**Blocked by:** [08: Upgrade legacy grants without guessing ownership](08-upgrade-legacy-grants-without-guessing-ownership.md), [11: Download files with enforced integrity and resource limits](11-download-files-with-enforced-integrity-and-resource-limits.md), [17: Reply, forward and inspect Gmail submission evidence](17-reply-forward-and-inspect-gmail-submission-evidence.md), [20: Reply, forward and inspect Graph submission evidence](20-reply-forward-and-inspect-graph-submission-evidence.md), [21: Resume Graph attachment preparation safely](21-resume-graph-attachment-preparation-safely.md), [22: Register Gmail watches and validate change notifications](22-register-gmail-watches-and-validate-change-notifications.md), [24: Enforce shared-mailbox live-mode capabilities](24-enforce-shared-mailbox-live-mode-capabilities.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 3, 8, 10, 11, 12, 57, 89, 90, 91, 92, 93, 94, 95.

Verify that all implemented capabilities form a compatible package upgrade, with honest documentation and release evidence. This gate integrates earlier work rather than deferring each slice's tests.

## Acceptance criteria

- [ ] Run repository-required Pest, PHPStan, Pint and strict Composer checks against an immutable candidate source identity after focused behavior checks pass.
- [ ] Identify isolated SQLite targets and destructive test setup before database-backed runs; execute more than ten Pest tests in parallel with at most eight workers. Do not touch application databases.
- [ ] Demonstrate PHP 8.2/8.3/8.4, lowest and supported dependency resolution, and explicit valid Laravel 12/13 combinations without assuming every PHP/Laravel pair is supported.
- [ ] Verify production-only dependency availability and current advisory results for all approved parser/encoder/validator requirements, with no reliance on development-only transitives.
- [ ] Check SDK-only no-table usage, optional Eloquent, configured driver calls, the Workspace alias, constructors, raw clients/query contracts, custom creators/resolvers/stubs and safe callback/result fields.
- [ ] Run actual Ngest compatibility in an isolated consumer environment without modifying its active checkout or credentials. Package fixtures do not replace this consumer check.
- [ ] Exercise an AI Clerk-shaped host adapter for grant lifecycle/storage, durable submission/receipt boundaries and local editor drafts, without adding AI Clerk/Ngest as production package dependencies or migrating either application.
- [ ] Combine native reply/forward, chosen files, resumed upload, uncertainty/evidence and notification recovery cases; preserve all earlier forbidden-effect regressions.
- [ ] Update public capability/configuration/authentication/content/file/custom-driver/testing guidance and upgrade notes only to match implemented verified behavior. Describe legacy binding and security exceptions explicitly.
- [ ] Create a live acceptance ledger with each required account/authentication/file/notification case marked pending, passed or concretely blocked. Record immutable source and terminal check evidence without claiming fixture success proves live parity.

## Verification

- Use real public SDK/provider mapping and HTTP fakes for package contracts, real isolated SQLite for persistence and the isolated actual consumer for Ngest compatibility.
- Collect command results and resolved version/platform matrix for the exact candidate head, including production-only/lowest installs and advisory checks.
- Review the live ledger for explicit own/shared native reply/forward, 10 MiB file, renewal and recovery rows; tickets 26 and 27 own live execution.

## Integration notes

Preserve existing runner/platform coverage; no Blacksmith migration, package publication, production setup change or consumer migration is included. Missing isolated-consumer access is a concrete blocker, not evidence of compatibility. Keep one writer for shared contracts and dependency files; independent graph nodes do not authorize conflicting simultaneous edits.


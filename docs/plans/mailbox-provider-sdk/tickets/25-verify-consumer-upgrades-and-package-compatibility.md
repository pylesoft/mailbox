# 25: Verify consumer upgrades and package compatibility

**What to build:** Pass production/lowest-version gates, isolated Ngest compatibility and SDK-only/host-adapter contracts with accurate docs.

**Blocked by:** [08: Upgrade legacy grants without guessing ownership](08-upgrade-legacy-grants-without-guessing-ownership.md), [11: Download files with enforced integrity and resource limits](11-download-files-with-enforced-integrity-and-resource-limits.md), [17: Reply, forward and inspect Gmail submission evidence](17-reply-forward-and-inspect-gmail-submission-evidence.md), [20: Reply, forward and inspect Graph submission evidence](20-reply-forward-and-inspect-graph-submission-evidence.md), [21: Resume Graph attachment preparation safely](21-resume-graph-attachment-preparation-safely.md), [22: Register Gmail watches and validate change notifications](22-register-gmail-watches-and-validate-change-notifications.md), [24: Enforce shared-mailbox live-mode capabilities](24-enforce-shared-mailbox-live-mode-capabilities.md)

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 3, 8, 10, 11, 12, 57, 89, 90, 91, 92, 93, 94, 95.

Verify that all implemented capabilities form a compatible package upgrade, with honest documentation and release evidence. This gate integrates earlier work rather than deferring each slice's tests.

## Acceptance criteria

- [x] Run repository-required Pest, PHPStan, Pint and strict Composer checks against an immutable candidate source identity after focused behavior checks pass.
- [x] Identify isolated SQLite targets and destructive test setup before database-backed runs; execute more than ten Pest tests in parallel with at most eight workers. Do not touch application databases.
- [x] Demonstrate PHP 8.2/8.3/8.4, lowest and supported dependency resolution, and explicit valid Laravel 12/13 combinations without assuming every PHP/Laravel pair is supported.
- [x] Verify production-only dependency availability and current advisory results for all approved parser/encoder/validator requirements, with no reliance on development-only transitives.
- [x] Check SDK-only no-table usage, optional Eloquent, configured driver calls, the Workspace alias, constructors, raw clients/query contracts, custom creators/resolvers/stubs and safe callback/result fields.
- [x] Run actual Ngest compatibility in an isolated consumer environment without modifying its active checkout or credentials. Package fixtures do not replace this consumer check.
- [x] Exercise an AI Clerk-shaped host adapter for grant lifecycle/storage, durable submission/receipt boundaries and local editor drafts, without adding AI Clerk/Ngest as production package dependencies or migrating either application.
- [x] Combine native reply/forward, chosen files, resumed upload, uncertainty/evidence and notification recovery cases; preserve all earlier forbidden-effect regressions.
- [x] Update public capability/configuration/authentication/content/file/custom-driver/testing guidance and upgrade notes only to match implemented verified behavior. Describe legacy binding and security exceptions explicitly.
- [x] Create a live acceptance ledger with each required account/authentication/file/notification case marked pending, passed or concretely blocked. Record immutable source and terminal check evidence without claiming fixture success proves live parity.

## Verification

- Use real public SDK/provider mapping and HTTP fakes for package contracts, real isolated SQLite for persistence and the isolated actual consumer for Ngest compatibility.
- Collect command results and resolved version/platform matrix for the exact candidate head, including production-only/lowest installs and advisory checks.
- Review the live ledger for explicit own/shared native reply/forward, 10 MiB file, renewal and recovery rows; tickets 26 and 27 own live execution.

## Integration notes

Preserve existing runner/platform coverage; no Blacksmith migration, package publication, production setup change or consumer migration is included. Missing isolated-consumer access is a concrete blocker, not evidence of compatibility. Keep one writer for shared contracts and dependency files; independent graph nodes do not authorize conflicting simultaneous edits.


## Comments

- 2026-10-29: Claimed by the fresh ticket-25 implementer on local branch feat/mailbox-sdk-25, based on integration b539e19af93ee9b9147d7e8d19896a48ca2e399d (tree 35630f1f02124e2339995822968d8b9c7e2c2373). Dependencies 08/11/17/20/21/22/24 are closed and integrated. Root granted the sole heavy-check slot; all checks remain serial, Pest at most two workers. Scope is local package compatibility, isolated consumer/platform verification and accurate documentation; no publication, live provider operation or consumer migration.

- 2026-10-29: Host compatibility source `e574aa7` passes 3 cases / 58 assertions through real Graph behavior and a separate test-only durable SQLite host. The first actual PHP 8.2/Laravel 12 full run exposed concurrent Windows Testbench `services.php` replacement; `cf26088` isolates service/package manifests per worker before provider registration. Its focused two-worker setup passed 59 / 217, then all four actual Laravel 12 lanes (PHP 8.2 prefer-lowest/latest, 8.3 latest, 8.4 latest) passed 1,413 tests / 6,953 assertions each, with 8 explicitly skipped wire cases. Installed-platform and unfiltered audits pass in every graph. Lowest full-dev resolution is Laravel 12.69.0 under current constraints/security policy, not a Laravel 12.0 suite claim.

- Actual isolated Ngest source `b899e17579f47065185f2eab9c3c62d0a748fc4c` passes 5 / 37 on PHP 8.4.25 after a complete 225-file Mailbox source-copy/reinstall/hash check. Its active checkout/vendor/environment/data remain unchanged, with only its preexisting `solo.yml` edit. The real manager/custom creator, token exchange, raw service, empty-config Eloquent connections, policies and browser mapping are exercised with HTTP fakes and SQLite-only assertions before migrations. Its three residual advisories affect unchanged Livewire 4.3.0 and Symfony Yaml 8.0.12; these are documented separately, without adding ignores or remediating the host.

- Production-only actual PHP 8.2/Illuminate 12 lowest, PHP 8.3/Illuminate 13 and PHP 8.4/Illuminate 13 installs, platform checks, unfiltered audits and decoded MIME/public DTO self-checks pass without Testbench/PHPUnit. PHP 8.3/Laravel 13's full dev graph is blocked specifically by Pest plugin 3.x framework constraints versus 5.x's PHP 8.4 requirement; its runtime-only evidence remains valid and narrower. See the [compatibility receipt](../compatibility.md) for exact graphs and the [live ledger](../live-acceptance.md) for concrete blocked account/authentication/send/file/notification/recovery rows. Final current-lock PHP 8.4/Laravel 13 and serial wire gates are still pending; this ticket remains open.

- Final local closure at immutable candidate `6cc93ad2296fc4ecedb5a581df8ded1cbf15637d` (tree `a7d2a5d1eb1f396e96774948a9738ba08a4a4c1e`): current-lock PHP 8.4/Laravel 13 full suite **1,413 passed / 6,953 assertions / 8 explicit wire skips**, two workers, exit 0. Full-source PHPStan `--debug` reports no errors in one process; Pint `--dirty --format agent`, strict Composer validation, installed platform checks and unfiltered locked audit all exit 0. All four Laravel 12 full graphs and three production-only graphs retain their scoped terminal receipts above.

- The eight skipped wire cases were then executed individually in fresh serial PHP 8.4.25 processes: **8 passed / 144 assertions**, exit 0, source-and-lock fingerprint `ddd59000cbe0463dac93904b2f1320b2762618a0a2328661c0135980e5aca591`. Owned local TLS servers independently verify bytes/SHA, MIME/thread intent or Outlook ranges, trusted original targets, preauthenticated origin-auth suppression and cleanup. Cold 10/20 MiB payload growth is 0 for Gmail and 8 bytes for Graph above their respective controls; warm 20 MiB peaks are 162,512 bytes for Gmail reply and 263,800 for Graph. The unchanged 8 MiB payload budget is not a total cold heap/RSS claim.

- Public guidance, explicit CI matrix and live ledger now match the verified scope. Detailed command/version/hash receipts are retained in `C:/Users/hello/.codex/scratch/mailbox-sdk-implementation/ticket25/`, including `final-receipts-6cc93ad.json`, `wire-receipts-6cc93ad.json`, per-graph locks and the isolated consumer source receipt. This closure changes Markdown only. The parent specification and tickets 26/27 remain open; all live rows are concretely blocked, Ngest's unchanged advisory risk remains recorded, and no remote CI, publication, live setup/send or consumer migration is claimed. Mandatory independent integration review follows separately.

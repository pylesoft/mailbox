# Local Compatibility Receipt

This receipt records local compatibility checks for the unreleased provider SDK. It does not publish a version, migrate an application, establish live provider parity or close the [live acceptance ledger](live-acceptance.md).

## Source and Isolation

- Integration baseline: `b539e19af93ee9b9147d7e8d19896a48ca2e399d`, tree `35630f1f02124e2339995822968d8b9c7e2c2373`.
- Final combined candidate: `6cc93ad2296fc4ecedb5a581df8ded1cbf15637d`, tree `a7d2a5d1eb1f396e96774948a9738ba08a4a4c1e`. Host-contract test source is `e574aa7`; worker-manifest isolation is `cf26088`. Their runtime trees and Composer files match the integration baseline, and their final test tree matches this candidate. Later receipt/closure edits change Markdown only.
- Runtime constraints remain PHP `^8.2`, Illuminate `^12.0 || ^13.0`, parser `^4.0.8`, Mime `^7.4.22 || ^8.1.10`, Egulias `^4.0.4`, and PHP-DI exclusion `<6.3.0`. No supported constraint was weakened and no application dependency was migrated.
- SDK-only tests use a deliberately unavailable default connection with package persistence disabled. Persistence tests use real SQLite memory or worker-owned Testbench files with `RefreshDatabase`. Provider/package bootstrap manifests are also worker-specific before provider registration, preventing concurrent Windows manifest replacement.
- All checks run serially; Pest uses at most two workers, PHPStan `--debug` one process, and Composer at most two child/download operations. Opt-in TLS/memory groups run in separate serial invocations.

## Public Host Contract

`HostAdapterCompatibilityTest` passed three cases with 58 assertions on actual PHP 8.4.25. A separate test-only SQLite host retains grants, local editor content, preparation/upload progress, submission receipts and notification receipts across adapter instances. Real Graph resources and clients sit behind HTTP fakes.

The cases exercise native reply and forward with only chosen files, resumed large-file ranges without bearer authorization, original immutable identities, uncertain send/absent evidence without resend, lifecycle rejection, and missed-notification reconciliation from a retained checkpoint. Package tables remain unavailable. This is an adapter example and SDK contract proof; it does not establish a production host database, cross-process refresh lock, webhook endpoint or application migration.

## Actual Ngest Consumer

The consumer source is `b899e17579f47065185f2eab9c3c62d0a748fc4c`, branch `fix/acumatica-lock-retry-6413`. Its active checkout retains only the existing unrelated `solo.yml` edit. No active application boot, vendor/environment/credential/database change occurred.

The isolated export includes 1,744 reviewed tracked source files with normal file modes. It preserves `laravel/` and `packages/ingest/` layout and excludes `.env*`, the tracked encrypted environment, auth/vault files, vendors, storage, caches, logs and databases. The snapshot creates its own empty writable directories and a zero-byte synthetic `.env` sentinel; no active environment content is copied.

Only the isolated manifest replaces Mailbox's VCS entry with an immutable source path copy, `symlink=false`, and exact local label `0.1.4-dev`. This is an unreleased compatibility label, not a chosen release version. The complete installed Mailbox copy matches all 225 exported production-source files, including routes, with zero hash differences; neither installed local package is a symlink/reparse point. The local Ingest package is also a copied path dependency. Existing legitimate configured Composer authentication is used only by fetch commands without opening, printing, copying or modifying credential files. Installation disables scripts/plugins; the app test process uses a separate allowlisted synthetic environment.

Before boot, `APP_ENV=testing` activates the installed Dotlock guard before any vault load. Before `RefreshDatabase`, the test harness asserts SQLite `:memory:`, no database URL, SQLite activitylog and disabled Nightwatch, then removes non-SQLite connections and fakes jobs/Laravel HTTP. Disks, logs and temporary paths remain owned by the snapshot. The real Guzzle client has a separate HTTP fake; no provider credentials or network fallback enter it.

The application raw service resolves the real manager/custom creator, real configured token exchange, Graph client and resources. Token and Graph base URIs remain distinct. The Eloquent path creates real empty-config Mailbox connections, Mailboxes, FeedSources, Automations and users; the actual access policy and `ProviderMailboxBrowser` browse/preview map real SDK responses. Existing availability/denial tests supplement these paths. This extension-based injection does not by itself prove every unmodified built-in HTTP dependency assembly; package configured-driver/constructor tests cover that separate contract.

The combined consumer run passed **5 tests / 37 assertions**, exit 0. The original locked Laravel 13.24.0 resolves to 13.39.0 in the isolated candidate graph; Mime 8.1.2 becomes 8.1.10, parser 4.0.3 becomes 4.0.8, and Egulias remains 4.0.4. These changes are confined to the isolated compatibility lock. The app's existing Composer `ext-pcntl=8.4.20` platform override is retained; it is not evidence that this Windows runtime has pcntl or can run every application worker.

### Consumer Advisory Result

The isolated consumer audit exits 1 with three advisories affecting two **unchanged** consumer versions:

| Package/version before and after | Advisory | Severity |
| --- | --- | --- |
| Livewire 4.3.0 | `PKSA-xhv3-nr94-h8db` / `GHSA-mhf7-xp6r-6qrc` | Medium |
| Livewire 4.3.0 | `PKSA-bgw4-5zmg-2njg` / `CVE-2026-81887` | Medium |
| Symfony Yaml 8.0.12 | `PKSA-xz3r-9gp4-4m1z` / `CVE-2026-109413` | High |

No ignore or unrelated consumer upgrade was introduced. These are consumer risks, separate from the package's required unfiltered audit. The active application's existing advisory-ignore configuration is retained in the isolated manifest; the audit's reported `filter` is empty for these findings. No app-wide/evaluation suite or host remediation is included.

## Actual Platform and Dependency Graphs

Every lane has its own source snapshot, manifest, lock, vendor and SQLite targets. More than ten tests run with two workers. The current prefer-lowest result is the lowest graph permitted by Composer's current security policy, not evidence for Laravel 12.0.

| Actual runtime / graph | Resolution and verification |
| --- | --- |
| PHP 8.2.35 / Laravel 12, prefer-lowest | Laravel 12.69.0, Testbench 10.0.0/core 10.8.0, Pest 3.8.5, Mime 7.4.22, Egulias 4.0.4, parser 4.0.8. Full suite: 1,413 passed, 8 opt-in wire skips, 6,953 assertions, exit 0; installed platform and unfiltered audit clean. |
| PHP 8.2.35 / Laravel 12, latest compatible | Laravel 12.70.2, Testbench 10.12.0/core 10.16.0, Pest 3.8.7, Mime 7.4.22. 1,413 passed / 8 wire skips / 6,953 assertions, exit 0; installed platform and unfiltered audit clean. |
| PHP 8.3.15 / Laravel 12 | Same declared versions as the latest PHP 8.2 lane, separately solved/installed on actual PHP 8.3. 1,413 passed / 8 wire skips / 6,953 assertions, exit 0; platform/audit clean. |
| PHP 8.4.25 / Laravel 12 | Same declared versions as the latest PHP 8.2 lane, separately solved/installed on actual PHP 8.4. 1,413 passed / 8 wire skips / 6,953 assertions, exit 0; platform/audit clean. |
| PHP 8.4.25 / Laravel 13, current lock | Laravel 13.39.0, Testbench 11.1.0/core 11.3.1, Pest 5.x-dev `18bbca7`, Mime 8.1.10. 1,413 passed / 8 wire skips / 6,953 assertions, exit 0; full-source static/style/strict Composer/platform/audit gates pass. |
| Production-only PHP 8.2.35 / Illuminate 12, lowest | 72 runtime packages, Illuminate 12.0.0, Mime 7.4.22, Egulias 4.0.4, parser 4.0.8. Install/platform/unfiltered audit/content check all exit 0, without Testbench/PHPUnit. This is narrower than the full framework 12.69.0 lane. |
| Production-only PHP 8.3.15 / Illuminate 13 | 74 runtime packages, Illuminate 13.39.0, Mime 7.4.22, Egulias 4.0.4, parser 4.0.8. Install/platform/unfiltered audit/content check all exit 0, without Testbench/PHPUnit. |
| Production-only PHP 8.4.25 / Illuminate 13 | 73 runtime packages, Illuminate 13.39.0, Mime 8.1.10, Egulias 4.0.4, parser 4.0.8. Install/platform/unfiltered audit/content check all exit 0, without Testbench/PHPUnit. |

PHP 8.2 with Laravel 13 is invalid under Laravel's PHP requirement and is not a test lane. The actual PHP 8.3/Laravel 13 development solver exits 2: Pest Laravel plugin 3.x supports framework 11/12, while its 5.x branch requires PHP 8.4. No constraint is weakened. That development-tool conflict is not a claim that the declared Illuminate 13 runtime is unsupported on PHP 8.3; the separate production lane records its narrower evidence. Production checks exclude test frameworks and exercise public received-message mapping and prepared-message encoding with the real runtime parser/encoder/validator dependencies; they are not full Testbench suites.

## Final Candidate Gates

At `6cc93ad`, the current-lock PHP 8.4/Laravel 13 full suite passes **1,413 tests / 6,953 assertions**, exit 0, with two workers. Its eight ordinary-run skips are the separately executed TLS cases below. Full-source PHPStan `analyse --debug --no-progress` reports no errors in one process. Pint `--dirty --format agent`, Composer strict validation, actual installed platform requirements and the unfiltered locked audit all exit 0; audit reports no advisories, abandoned packages or filters. Direct dependency inspection confirms all three content libraries are runtime requirements.

All eight fresh TLS cases pass separately and serially on PHP 8.4.25, 64-bit: **8 tests / 144 assertions**, exit 0. The actual source-and-lock fingerprint is `ddd59000cbe0463dac93904b2f1320b2762618a0a2328661c0135980e5aca591` for every case.

| Local TLS case | Source bytes | PHP peak growth | Payload growth above its cold control |
| --- | ---: | ---: | ---: |
| Gmail cold control | 8,192 | 9,280,032 | Control |
| Gmail cold 10 MiB | 10,485,760 | 9,276,696 | 0 |
| Gmail cold 20 MiB aggregate | 20,971,520 | 9,276,376 | 0 |
| Gmail warm 20 MiB reply | 20,971,520 | 162,512 | Direct warm peak |
| Graph cold control | 3,000,001 | 5,244,096 | Control |
| Graph cold 10 MiB | 10,485,760 | 5,244,104 | 8 |
| Graph cold 20 MiB aggregate | 20,971,520 | 5,244,104 | 8 |
| Graph warm 20 MiB aggregate | 20,971,520 | 263,800 | Direct warm peak |

Independent owned local servers verify actual MIME/JSON or sequential Outlook ranges, exact file bytes/SHA-256, original trusted HTTPS targets, scoped local CA/cURL routing and origin-authorization suppression on preauthenticated uploads. Gmail's warm reply also verifies thread and reply headers. Each invocation owns and cleans its helper/process and temporary files. These results retain the unchanged 8 MiB payload allocation allowance; they do not promise an 8 MiB total cold heap, native/RSS limit or live provider acceptance.

The workflow's explicit PHP 8.2/8.3/8.4 with Laravel 12, PHP 8.4 with Laravel 13, and PHP 8.2 prefer-lowest lanes have been parsed and checked locally. Introduced relative Markdown links and diff whitespace resolve cleanly. No GitHub CI run, push, release, provider setup or application migration was performed. Independent integration review remains a separate subsequent step; the parent specification and live tickets 26/27 remain open.

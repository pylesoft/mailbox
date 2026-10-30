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

The workflow's explicit PHP 8.2/8.3/8.4 with Laravel 12, PHP 8.4 with Laravel 13, and PHP 8.2 prefer-lowest lanes have been parsed and checked locally. Introduced relative Markdown links and diff whitespace resolve cleanly. No GitHub CI run, push, release, provider setup or application migration was performed. Independent integration review followed this candidate, as recorded below; the parent specification and live tickets 26/27 remain open.

## Final Review Repair

Final integration review at `2e07289774e9315bbe22f167b425e7c3b1a9e839` identified five specification findings and two privacy-standard findings. Public regressions reproduced the configured Gmail probe credential leak, object collections accepted as empty lists, inherited Guzzle authentication and query overrides, and secret-bearing exception arguments. A single-leaked-sentinel control also confirmed that each privacy assertion must check one secret at a time.

The repair runtime is `bf48f73c903c0947465b880f5dc4cb9c7cdc00d6`, tree `835b8d07aa765dbdf4209c43fe1c24345641425f`. Successful Gmail probes now return the resolved mailbox identity, as described in the [security upgrade note](../../upgrade-guide.md#legacy-oauth-records-and-reconnect). Native collection validation preserves raw array APIs, omitted and empty-list behavior, response budgets and the existing conversation `content_incomplete` result. Captured grants suppress ordinary inherited authentication; opaque bounded URLs suppress inherited queries without parsing or rebuilding their bytes. Malformed destinations still pass through sanitized validation.

Google's common direct-token identity checks now share one provider-specific verifier. Enrollment still requires its original nonce; refresh retains its separate optional nonce and exact stored-subject checks. Sensitive parameters protect JWTs, nonces, saved sync positions and malformed response envelopes throughout the rejecting SDK frames. Explicit private persistence/export APIs remain available. The stale DTO and CI descriptions were corrected without widening serialization.

| Refreshed check | Terminal result |
| --- | --- |
| PHP 8.4.25 / Laravel 13 current lock, complete suite | 1,449 passed / 7,198 assertions, two workers, exit 0; eight opt-in wire skips executed separately below |
| Full-source PHPStan, Pint, strict Composer validation, installed platform and unfiltered locked audit | Exit 0; PHPStan uses one process; audit has no advisories, abandoned packages or filters |
| Actual PHP 8.2.35 / existing Laravel 12 prefer-lowest graph | 1,017 passed / 4,707 assertions across 26 affected public SDK/transport/legacy test files, two workers, exit 0; explicit-file XML preserves the test environment and existing lock |
| Actual isolated Ngest `b899e175` | Same five public raw/Eloquent/browser/access cases pass / 37 assertions, exit 0; 226 SDK files match the installed copy, with no symlink or consumer lock change |
| Eight serial local TLS cases | Eight passed / 144 assertions, exit 0; fingerprint `2b2139534d9d957245c795db71811c893082229da6361ee19d21259ced8a4d78` |

The refreshed wire cases verify the same bytes, MIME/thread intent, Outlook ranges and preauthenticated authorization boundary. Cold payload growth remains zero for Gmail and eight bytes for Graph above their controls; warm 20 MiB peaks are 162,144 bytes for the Gmail reply and 263,992 bytes for Graph. The unchanged 8 MiB payload allowance remains distinct from total cold heap or RSS.

Detailed source, command, copy/hash and terminal receipts are retained in `C:/Users/hello/.codex/scratch/mailbox-sdk-implementation/review-repair/`. The earlier full platform and production-only matrix remains historical evidence for `6cc93ad`; this repair refreshes the affected lowest graph and complete current graph, rather than claiming every earlier lane was rerun. No dependency constraint changed. The active Ngest checkout still has only its preexisting `solo.yml` edit; its three unchanged advisories remain documented above. Live tickets 26/27 and the specification remain open, with no publication, provider operation or active-consumer migration.

## Deep Local Self-Review

The review starts at `b259591e967adacb2a9ea775614b0beffcc2aa93`, against released base `13a95cc55cc25f2bfcf536104bfc5133865a08a5`. Sixteen independent, read-only packets covered correctness/security, maintainability, Ponytail and Laravel Simplifier across authorization, inbound reads, submission and notifications. The main review also checked compatibility, delivery gates and the linked AI Clerk plan. All 96 stories and 202 ticket criteria are accounted for; the 18 live-account criteria remain blocked, rather than counted as fixture success.

The repaired runtime is `43f97df1b6ae5f03ca5b0b0fb6b65c3641d2a2c7`, tree `5e1bce07beb1e1ff094d58fba5d82110fda0fd9f`. Public regressions reproduced eight defects before their fixes: selected-grant Graph bulk operations lost mailbox context; eager continuations inherited an unrelated default query; Graph streams skipped file-size validation; Graph preparation/inspection omitted caller memory guards; retained Gmail verifiers and Graph resources leaked credentials through diagnostics; and native Graph tombstone/recipient collection types lost their distinction during array conversion. Non-author reviewers verified each repair and its affected contracts. The two small Google/Gmail cleanups reuse existing code without changing behavior.

Graph typed acquisition now has an explicit [subclass interception note](../../upgrade-guide.md#received-addresses-and-headers). Configured batching, raw array APIs, mandatory custom-driver interfaces, stored identities, selected-grant authority and uncertain-send behavior remain covered. No dependency, schema, application migration or provider setup was added by this review.

| Refreshed gate on the repaired runtime | Terminal result |
| --- | --- |
| Complete PHP 8.4.25 / Laravel 13 suite | 1,479 passed / 7,306 assertions, two workers, exit 0; eight opt-in wire skips executed separately |
| Actual PHP 8.2.35 / preserved Laravel 12 lowest graph | 748 passed / 3,221 assertions across 23 explicit affected files, two workers, exit 0; lock unchanged, exported runtime matches Git blobs after Windows line-ending normalization |
| Actual isolated Ngest `b899e175` | Five passed / 37 assertions, exit 0; all 226 installed SDK files match the candidate copy, no symlink or consumer lock change |
| Eight separate serial local TLS cases | Eight passed / 144 assertions, exit 0; source-and-lock fingerprint `d6694bc7fda3ba04583bf3cece84c5b203d3a05404812317319d83d577bc8607` |
| Serial allocation checks | Attachment reads: seven passed / 24 assertions; Graph budget regressions: nine passed / 31 assertions; exit 0 |
| Full-source PHPStan, Pint, strict Composer, installed platform and locked audit | Exit 0; PHPStan ran in one process; audit has no advisories, abandoned packages or filters |

The final wire cases retain exact bytes, reply/thread intent, range progress and the preauthenticated authorization boundary. Payload growth above cold controls is zero for Gmail and eight bytes for Graph. Warm 20 MiB peaks are 162,976 bytes for Gmail reply and 263,976 bytes for Graph. Serial 10 MiB attachment reads measured 29,375,912 bytes for Gmail and 95,976 bytes for Graph after transport initialization. These are measured PHP heap allocations on this runtime, with the existing documented limits; they are not universal cold-process or RSS bounds.

One inherited limitation remains: concurrent downloads to the same storage destination can race between the existence check and write. A controlled public interleaving reproduces it, and both released provider implementations already contain that race. The [attachment guide](../../attachments.md#content-addressable-deduplication) now requires host coordination by the normalized destination directory. This review does not introduce a new storage backend, change existing paths/deduplication, or claim atomic publication. Revision-history growth likewise retains its documented never-reuse protection; no destructive history cap or speculative schema redesign was added.

Current source, review reports, coverage maps and terminal receipts are retained in `C:/Users/hello/.codex/scratch/mailbox-sdk-self-review-b259591/`. Earlier full platform lanes remain historical, rather than claimed as rerun. The active Ngest checkout retains only its preexisting `solo.yml` edit and its three unchanged advisory risks. The original planning checkout retains its 37 untracked planning files. The specification and live tickets 26/27 stay open: designated accounts, consent, scoped permissions, recipients, callbacks, deployed shared coordination and live-operation authority are still missing. No push, PR write, publication, deployment or active-consumer change occurred.

## PR Review Follow-Up

After the local review, [PR 15](https://github.com/pylesoft/mailbox/pull/15) was published under separate authorization. Its automatic review reproduced rejection of Microsoft's documented `v1.0` and `gv1.0` upload-session paths. The validator now accepts those message-session paths and their resource-name casing alongside `v2.0`, without rewriting the opaque URL or changing its trusted-host, credential, redirect or parent-message boundaries. Both documented forms failed before the repair and pass through resumed public upload preparation afterward.

The focused attachment suite passes **51 tests / 463 assertions**; host-adapter compatibility passes **3 / 58**. Four fresh serial Graph TLS cases pass **4 / 82**, using the documented `gv1.0` path for actual cURL uploads, fingerprint `8744ae6802e5fbc8b10f3ca76dd67b0235bf40f53f2d0a94070243ccaac9a069`. Scoped PHPStan, Pint and whitespace checks pass. Receipts are retained in `C:/Users/hello/.codex/scratch/mailbox-sdk-pr/`. Earlier local checks retain their recorded source identities; latest-head GitHub CI is recorded on the PR. The repeated storage-race finding retains the disclosed inherited-limitation disposition above; no atomic-publication repair or live-provider acceptance is claimed.

## Verified Graph Subscription Recovery

Independent review accepted repair runtime `51e44fc709287aa3d912610cdcdd9065d3907ef7` and final author head `7112f5574813a2e3463d60e5848dd80b6a2f98e3`, merged as `8f33914d82ead4e48f9d926493730476d6a1553b`, tree `25afd284bff7c4528d209e4b307e455497e0f68a`. Microsoft [withholds clientState from LIST](https://learn.microsoft.com/en-us/graph/api/subscription-list?view=graph-rest-1.0#response-1).

Earlier positive LIST fixtures echoed that secret and did not prove the documented response shape. LIST now discovers candidates only; each matching candidate requires an actual [known-ID GET](https://learn.microsoft.com/en-us/graph/api/subscription-get?view=graph-rest-1.0#response-1) through the same selected grant before the SDK retains its registration.

LIST and candidate GETs share request and actual response-byte budgets. Private pending IDs resume without re-listing and still require fresh proof. Failed candidate inspection stays unknown; the host aggregates slices and owns coordination and retry decisions.

The optional interface, mandatory custom-driver contracts and shared-live guards remain unchanged, and the exact opaque-mailbox comparison repair is retained.

| Fresh merged-candidate gate | Actual terminal result |
| --- | --- |
| PHP 8.4.25 / Laravel 13.39.0 current lock, all four ordinary suites | **1,632 passed / 8,137 assertions / eight opt-in wire skips**, two workers, exit 0; 91 files preserve the original suite policies and environment |
| Actual PHP 8.2.35 / Laravel 12.69.0 lowest graph | **259 passed / 966 assertions**, one combined nine-class affected selection, two workers, exit 0; Testbench 10.0.0, Pest 3.8.5, Mime 7.4.22 and 146 exact installed records |
| Actual isolated Ngest snapshot `b899e17579f47065185f2eab9c3c62d0a748fc4c` | **5 passed / 37 assertions**, serially, exit 0 without test warnings; PHP 8.4.25/Laravel 13.39.0, 231 installed SDK paths and real reflection hashes match the candidate |

The ordinary selection excludes the unchanged seven-case attachment-memory group and configured-Gmail privacy helper file. That helper's prior source-bound **1 / 21** loopback receipt remains valid; its test and Gmail/OAuth/support paths have no Git delta.

No helper, allocation benchmark or wire case was rerun for this repair. The eight wire skips above are explicit; earlier platform, production-only, advisory and wire measurements retain their recorded source and scope.

All 336 runtime/test/config Git blobs match the author: 305 raw files match and 31 differ only by CRLF/LF. The final ordinary run preserves all 428 tracked raw hashes.

The lowest overlay matches all 427 non-lock Git blobs exactly and preserves its own separate lock, while Ngest preserves all 517 recorded consumer-source hashes. Its offline, scripts/plugins-disabled package-only reinstall leaves the other 193 installed name/version/source/dist records and manifest/lock unchanged. The inherited local label `0.1.4-dev` and path reference `c1464ac` are fixture metadata; the 231 source and reflection checks establish the actual SDK identity.

The canonical SDK lock remains SHA256 `45D7E7D0B8C04191FBCDA4DD7D524F7A8A5D38B4631188BD9AC8485777A7AB64`, with 145 installed records. Only the exact source/dist GitHub owner alias for `vlucas/phpdotenv` v5.6.3 differs: both URLs resolve to repository 7769432 and the same reference/tree, and all 42 installed runtime blobs were checked.

No metadata, dependency constraint, solver result or advisory policy was rewritten. The author's combined **259 / 966**, connected six-path level-8 PHPStan, Pint with no PHP byte changes, strict Composer validation and installed-platform receipts apply to the identical runtime Git blobs; they are not additional merger runs.

Setup corrections remain separate evidence. The first ordinary child stopped before tests because empty disabled wire flags were absent in the Windows child environment. The first lowest archive failed its Git-byte check before PHP because it inherited line-ending conversion; a new archive with `core.autocrlf=false` repaired only the owned source export.

The first Ngest run completed five cases and 37 assertions with five absent synthetic-environment warnings; a new zero-byte owned sentinel and fresh cache paths produced the clean five-case result above. Repeated counts are not added. Ngest's existing 23 PSR-4 layout warnings and its three previously recorded unchanged consumer advisories remain disclosed.

Commands, raw source manifests, graph/reflection checks, preserved setup failures and stopped-process proofs are retained in `C:/Users/hello/.codex/scratch/ai-clerk-mailbox-implementation/merge-sdk-verified-lookup/`. Each PHP process uses a cleared synthetic testing environment, SQLite-only or SDK-forbidden database targets, disabled cURL initialization and URL fopen, and owned caches.

No active consumer environment, vendor or database was read or changed. No provider operation was performed.

Tickets 23/25 close local acceptance only; the specification and live tickets 26/27 remain open. Publication, exact-head remote CI and host adoption remain separate coordinator gates.

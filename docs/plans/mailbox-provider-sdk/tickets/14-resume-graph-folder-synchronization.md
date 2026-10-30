# 14: Resume Graph folder synchronization

**What to build:** Use the shared paged contract for Graph folder deltas, upserts/removals and checkpoint resets.

**Blocked by:** [01: Follow Graph continuations safely](01-follow-graph-continuations-safely.md), [12: Resume Gmail initial synchronization without gaps](12-resume-gmail-initial-synchronization-without-gaps.md)

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 26, 34, 37, 38, 39, 40, 43, 44, 45, 46, 47, 48, 88.

Use the shared bounded-progress contract for Microsoft Graph folder delta. Make each folder's progress and removal meaning explicit.

## Acceptance criteria

- [x] Read Graph next/delta links with their opaque query values intact and trusted destination checks applied.
- [x] Track delta progress per selected folder. An explicit mailbox-wide request enumerates its covered folders and retains separate progress; do not present one folder token as whole-mailbox completion.
- [x] Expose upserts and removal evidence without classifying creation versus update solely from etags.
- [x] Distinguish removal from a folder from verified message deletion; never instruct the host to delete retained history based only on folder removal.
- [x] Bind checkpoint/continuation state to provider, grant/generation, mailbox, folder scope, import policy and format; reject invalid reuse.
- [x] Handle empty/duplicate pages, interruption/replay, response caps, repeated/missing tokens and expired tokens with honest unfinished or scoped-reset outcomes.
- [x] Only expose completed folder/round checkpoints after all covered work finishes; the host persists work before promotion and retains scheduling/retention policy.
- [x] Preserve legacy configured/delta consumers and document actual coverage and reset semantics.

## Verification

- Exercise Graph delta through real HTTP history with exact opaque next/delta URLs, multiple folders and message moves/removals.
- Cover interrupted/replayed pages, expired checkpoints, cyclic progress and independently unfinished folders; assert no false mailbox-wide completion or destructive retention interpretation.

## Comments

- 2026-10-29: Claimed by the ticket 14 implementer on `feat/mailbox-sdk-14`, based on integration `72a728a1f2c143333c3b50d411ad783830352fec`. Reusing the approved Q13 public SDK, real Guzzle HTTP-history and host-port seams with `matt:tdd`. Scope is bounded Graph folder delta and explicit mailbox-wide covered-folder progress; no enrollment, live provider operations, application projections or publication. The branch has no upstream and started clean. Dependency setup and checks are queued behind the global single-check slot.
- First selected-folder tracer: observed RED because the Graph resource lacked `SupportsInitialSync`; GREEN after implementing initial/resumed/incremental folder delta and accepting Microsoft's documented `mailfolders('ID')` path form without altering opaque queries. `GraphSyncTest.php`: 1 test, 12 assertions, SDK-forbidden database. Locked dependency setup completed with scripts disabled. Mailbox-wide, state-validation and reset slices remain open.
- Mailbox-wide tracer: RED for unsupported mailbox scope, then GREEN for paginated root/hidden/child-folder enumeration, exact `01` folder identity, folder-specific move evidence, and separate next/delta links with no early mailbox checkpoint. Reset tracer: RED for documented HTTP 400 `syncStateNotFound` (including nested codes) and 410, then GREEN for original-scope reset without error-body retention. Unknown 400/401/403/404 errors remain explicit/redacted; actual error bytes are bounded before recognizing reset codes. Current focused result: 10 tests, 106 assertions; state/progress and final gates remain open.
- Completed 2026-10-29. Implementation `75894bd`, integration merge `8fa181d3b2681bf558ece3d49b4dcc2f6cdd647f` includes `d6c529fc64413d38292fc5d7e1ce06aa0c0feb23`; final code `9e2782b801b56bf38857dc9d53300c30fb1b45e6`. Explicit-grant Graph resources implement the existing `SupportsInitialSync` / `SupportsIncrementalSync` APIs. Existing configured/eager method and constructor signatures remain compatible. `ChangePage` adds trailing optional list-shaped `folderChanges` records and `coveredFolders`, both defaulting to empty arrays; no second paging contract or persistence layer was introduced.
- RED/green boundary cases reproduced malformed state reaching I/O or becoming a checkpoint, absent/wrong/empty progress-token kinds retaining uncommitted evidence, and malformed `@removed: null` inventing removal evidence. Final public SDK/Guzzle gate on the final code: **49 passed, 344 assertions**, PHP 8.4.25, two workers, SDK-forbidden database. Includes exact opaque URLs/queries, strict `01`/`1` and case-sensitive folder IDs, unknown mailbox aliases, policy/lifecycle/format binding before token access, hidden/child-folder enumeration, independent folder progress, moves, new-folder discovery, empty/duplicate pages, cycles, scoped expiry, all four host budgets and over-budget replay.
- Affected compatibility gate at `8fa181d`: **244 passed, 1,285 assertions**, exit 0, two workers, 30.17 seconds. Selection includes Graph SDK, Gmail initial/history, explicit-grant reads, all legacy Graph tests, shared transport, driver resolution, persistence sync compatibility and architecture. Feature tests use only Testbench-owned SQLite; SDK tests forbid database access. Final typed-record-only updates were followed by the 49-test Graph gate above. An earlier regex-selection invocation displayed the same 244 passing tests but exited 1; its cause was not established. The successful explicit temporary PHPUnit selection cloned the repository bootstrap, environment and default warning/failure policies, changing only selected files (bootstrap made absolute to the identical autoloader).
- Static/format evidence: full-source single-process `phpstan analyse --debug --no-progress` found errors confined to the new Graph class; explicit record updates corrected its array-shape inference. The final changed-file single-process PHPStan check passed with no errors, with unchanged source retaining its earlier clean analysis. Final `pint --dirty --format agent` passed and `git diff --check` was clean. No dependencies changed.
- Coverage policy: Microsoft documents a 5,000-message ceiling for `receivedDateTime`-filtered delta. Requests therefore remain unfiltered, with the original cutoff bound to state and host content/import-policy rechecks required. Mailbox-wide coverage is the hierarchy observed during that round, not an atomic hierarchy snapshot. Folder removal, an unobserved former folder or reset never directs deletion of retained history. See [delta documentation](../../../delta-sync.md#bounded-graph-folder-delta) and [upgrade guidance](../../../upgrade-guide.md#unreleased-bounded-graph-folder-delta). Official references checked: [message delta](https://learn.microsoft.com/en-us/graph/delta-query-messages), [folder hierarchy](https://learn.microsoft.com/en-us/graph/api/mailfolder-list-childfolders?view=graph-rest-1.0), [delta reset and token duration](https://learn.microsoft.com/en-us/graph/delta-query-overview), [error-code shape](https://learn.microsoft.com/en-us/graph/errors).
- Limits: no full-suite rerun, live Graph/provider setup, active consumer operation, MySQL operation, publication or deployment. The root owns the combined integration gate; ticket 27 retains live Microsoft acceptance. No remaining ticket-14 implementation/check blocker.

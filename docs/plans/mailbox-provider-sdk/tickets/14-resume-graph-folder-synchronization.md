# 14: Resume Graph folder synchronization

**What to build:** Use the shared paged contract for Graph folder deltas, upserts/removals and checkpoint resets.

**Blocked by:** [01: Follow Graph continuations safely](01-follow-graph-continuations-safely.md), [12: Resume Gmail initial synchronization without gaps](12-resume-gmail-initial-synchronization-without-gaps.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 26, 34, 37, 38, 39, 40, 43, 44, 45, 46, 47, 48, 88.

Use the shared bounded-progress contract for Microsoft Graph folder delta. Make each folder's progress and removal meaning explicit.

## Acceptance criteria

- [ ] Read Graph next/delta links with their opaque query values intact and trusted destination checks applied.
- [ ] Track delta progress per selected folder. An explicit mailbox-wide request enumerates its covered folders and retains separate progress; do not present one folder token as whole-mailbox completion.
- [ ] Expose upserts and removal evidence without classifying creation versus update solely from etags.
- [ ] Distinguish removal from a folder from verified message deletion; never instruct the host to delete retained history based only on folder removal.
- [ ] Bind checkpoint/continuation state to provider, grant/generation, mailbox, folder scope, import policy and format; reject invalid reuse.
- [ ] Handle empty/duplicate pages, interruption/replay, response caps, repeated/missing tokens and expired tokens with honest unfinished or scoped-reset outcomes.
- [ ] Only expose completed folder/round checkpoints after all covered work finishes; the host persists work before promotion and retains scheduling/retention policy.
- [ ] Preserve legacy configured/delta consumers and document actual coverage and reset semantics.

## Verification

- Exercise Graph delta through real HTTP history with exact opaque next/delta URLs, multiple folders and message moves/removals.
- Cover interrupted/replayed pages, expired checkpoints, cyclic progress and independently unfinished folders; assert no false mailbox-wide completion or destructive retention interpretation.

## Comments

- 2026-10-29: Claimed by the ticket 14 implementer on `feat/mailbox-sdk-14`, based on integration `72a728a1f2c143333c3b50d411ad783830352fec`. Reusing the approved Q13 public SDK, real Guzzle HTTP-history and host-port seams with `matt:tdd`. Scope is bounded Graph folder delta and explicit mailbox-wide covered-folder progress; no enrollment, live provider operations, application projections or publication. The branch has no upstream and started clean. Dependency setup and checks are queued behind the global single-check slot.
- First selected-folder tracer: observed RED because the Graph resource lacked `SupportsInitialSync`; GREEN after implementing initial/resumed/incremental folder delta and accepting Microsoft's documented `mailfolders('ID')` path form without altering opaque queries. `GraphSyncTest.php`: 1 test, 12 assertions, SDK-forbidden database. Locked dependency setup completed with scripts disabled. Mailbox-wide, state-validation and reset slices remain open.
- Mailbox-wide tracer: RED for unsupported mailbox scope, then GREEN for paginated root/hidden/child-folder enumeration, exact `01` folder identity, folder-specific move evidence, and separate next/delta links with no early mailbox checkpoint. Reset tracer: RED for documented HTTP 400 `syncStateNotFound` (including nested codes) and 410, then GREEN for original-scope reset without error-body retention. Unknown 400/401/403/404 errors remain explicit/redacted; actual error bytes are bounded before recognizing reset codes. Current focused result: 10 tests, 106 assertions; state/progress and final gates remain open.


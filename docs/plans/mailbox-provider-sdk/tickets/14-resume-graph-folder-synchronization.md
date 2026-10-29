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


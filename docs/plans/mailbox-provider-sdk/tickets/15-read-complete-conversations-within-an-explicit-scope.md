# 15: Read complete conversations within an explicit scope

**What to build:** Return older selected-thread context and bounded unfinished work honestly for Gmail and Graph.

**Blocked by:** [01: Follow Graph continuations safely](01-follow-graph-continuations-safely.md), [10: Read externalized Gmail bodies completely](10-read-externalized-gmail-bodies-completely.md), [12: Resume Gmail initial synchronization without gaps](12-resume-gmail-initial-synchronization-without-gaps.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 33, 34, 35, 38, 49, 50.

Provide an optional conversation read that returns complete useful context within an explicit mailbox/folder scope, or a clear unfinished/limited outcome.

## Acceptance criteria

- [ ] Select Gmail conversations by the host's activity/cutoff policy while retaining older messages belonging to the selected thread.
- [ ] Expose Graph conversation contents with the actual mailbox/folder scope covered; do not promise inaccessible cross-mailbox or cross-folder context.
- [ ] Hydrate required message bodies and file metadata through the complete-content path; missing parts cannot become empty complete messages.
- [ ] Treat Gmail whole-thread responses as unpaged provider operations: enforce real response/content limits rather than promising bounded payloads from a request-count limit.
- [ ] Return explicit unfinished/unsupported work when thread payload, paging or hydration cannot complete within bounds, preserving usable continuation where possible.
- [ ] Keep caller scope, history retention and presentation policy outside the package. Existing custom drivers can decline this optional capability without breaking basic reads.
- [ ] Document what complete means for each provider and the difference between finished conversation content and completed synchronization.

## Verification

- Read public Gmail and Graph conversations using real HTTP/mapping with older thread context, multi-page Graph results and externalized Gmail text.
- Test unpaged oversized Gmail threads, content-fetch interruption, inaccessible scope and optional-capability absence; assert no silently omitted context.


# 17: Reply, forward and inspect Gmail submission evidence

**What to build:** Preserve chosen reply/forward context and inspect original correlation evidence without resending.

**Blocked by:** [15: Read complete conversations within an explicit scope](15-read-complete-conversations-within-an-explicit-scope.md), [16: Submit a prepared Gmail message once](16-submit-a-prepared-gmail-message-once.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 67, 68, 74, 75.

Use explicit caller context to submit Gmail replies and forwards, then inspect submission evidence without ever using absent evidence as a resend instruction.

## Acceptance criteria

- [ ] Preserve the intended Gmail thread identity, matching subject and required reply identifiers/references when the caller explicitly requests a reply.
- [ ] Keep forwarding distinct from replying, retaining only caller-chosen content, recipients, headers and file intent; do not choose reply-all policy or inherit historical Bcc.
- [ ] Use complete scoped message/conversation content for context where requested, with explicit failure/incomplete results for unavailable or mismatched context.
- [ ] Retain original caller correlation and provider acknowledgement identity for subsequent evidence inspection.
- [ ] Inspect Sent/message identifiers through trusted scoped reads; delayed, absent, mismatched or incomplete evidence leaves uncertainty unresolved.
- [ ] Evidence inspection performs no send and never converts a 404, timeout or missing sent copy into permission to replay submission.
- [ ] Document supported threading/correlation behavior, forward semantics and the limits of acceptance evidence.

## Verification

- Exercise actual Gmail serialization, send and evidence-read paths for replies and forwards with international subjects, References/In-Reply-To and explicit recipient changes.
- Test wrong-thread context, delayed/missing/mismatched evidence and interrupted reads; assert original correlation survives and no second send occurs.

## Integration notes

Attachment-bearing forwards use the separate file capability when available. This slice is verifiable with body-only replies/forwards and must not silently select or copy files.


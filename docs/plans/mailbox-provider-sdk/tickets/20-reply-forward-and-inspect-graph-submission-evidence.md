# 20: Reply, forward and inspect Graph submission evidence

**What to build:** Replace inherited draft defaults with caller intent and inspect the same provider identity after submission.

**Blocked by:** [15: Read complete conversations within an explicit scope](15-read-complete-conversations-within-an-explicit-scope.md), [19: Prepare and submit a Graph draft safely](19-prepare-and-submit-a-graph-draft-safely.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 68, 69, 70, 71, 72, 74, 75.

Prepare native Graph reply and forward drafts from explicit context, normalize them to caller intent and inspect the same submission artifact without modifying the original message.

## Acceptance criteria

- [ ] Use the selected native reply/forward operation and immutable draft identity for the intended source context, with explicit unsupported/incomplete results when context is unavailable.
- [ ] Replace inherited recipients and unselected files on the new submission draft so the caller's explicit To/Cc/Bcc and file manifest remain authoritative.
- [ ] Preserve caller-chosen content and supported context while documenting harmless provider normalization; never select reply-all recipients or historical Bcc automatically.
- [ ] Limit all preparation changes to the new draft. Verify the original message and its attachments are untouched.
- [ ] Verify normalized intent before send, retaining original preparation identity and caller correlation through acceptance and later inspection.
- [ ] Inspect sent-copy evidence using the same immutable identity and trusted scope; delayed, absent, mismatched or 404 evidence remains inconclusive and never triggers another send.
- [ ] Keep draft-preparation uncertainty separate from send uncertainty, preserve no-replay behavior and document native reply/forward and evidence limitations.

## Verification

- Exercise native reply/forward creation, draft normalization, send and evidence inspection through real Graph HTTP/mapping paths.
- Assert exact caller recipients, removal of inherited unselected files, unchanged original resources and stable immutable identity.
- Cover provider-normalized content, stale/wrong context, missing draft acknowledgement and delayed/missing sent-copy evidence with no automatic send.

## Integration notes

The empty file-manifest case must be complete here. Uploading chosen small/large files is delivered by ticket 21 and combined native file cases are checked by the compatibility and live gates.


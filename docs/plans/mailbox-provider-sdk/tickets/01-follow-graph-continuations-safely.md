# 01: Follow Graph continuations safely

**What to build:** Follow all returned pages with intact opaque queries and trusted authenticated destinations.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 25, 26, 27, 30, 36.

Repair continuation requests through the existing public read path before expanding provider capabilities. Keep opaque provider tokens intact while restricting where mailbox credentials can be sent.

## Acceptance criteria

- [ ] Reproduce Graph continuation query loss through the real Guzzle request path, then follow next and delta links without replacing, decoding or rebuilding their opaque query values.
- [ ] Resolve only trusted provider origins and resources for the selected mailbox context. Reject foreign origins, schemes, resource targets and redirect destinations before forwarding authorization.
- [ ] Preserve useful retry behavior for replay-safe reads and existing configured-provider request behavior.
- [ ] Retain explicit malformed, missing or cyclic continuation failures rather than inventing a completed result.
- [ ] Redact bearer tokens and secret continuation/recovery values from public errors, debug representations and logs.
- [ ] Update continuation and authenticated-request documentation with the corrected behavior and trust boundary.

## Verification

- Use Guzzle MockHandler and request history behind public provider reads; assert the exact first and continued request URI and authorization destination.
- Cover encoded/repeated query values, absolute and relative provider links, foreign origins, redirects, cyclic links and retryable reads. Mocking a high-level client method is insufficient.

## Integration notes

Keep this repair in the shared transport seam. The bounded synchronization tickets will add the complete checkpoint format and scope contract.

## Comments

- 2026-10-29: Claimed locally by the ticket 01 implementation agent on `feat/mailbox-sdk-01`, starting from approved integration commit `b899dab`. Verification uses public provider reads with real Guzzle transport, MockHandler and request history. Resolution remains open until the acceptance criteria and affected checks pass.


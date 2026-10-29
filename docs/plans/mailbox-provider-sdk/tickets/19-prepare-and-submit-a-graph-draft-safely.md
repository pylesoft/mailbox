# 19: Prepare and submit a Graph draft safely

**What to build:** Expose immutable draft preparation, a separate one-time send and honest acceptance or uncertainty.

**Blocked by:** [06: Connect and refresh Microsoft mailboxes](06-connect-and-refresh-microsoft-mailboxes.md), [16: Submit a prepared Gmail message once](16-submit-a-prepared-gmail-message-once.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 29, 31, 32, 36, 61, 63, 64, 65, 66, 69, 72, 73, 76.

Prepare a Microsoft Graph submission draft and send it through separate public phases. The host can retain the immutable draft identity before requesting the irreversible send.

## Acceptance criteria

- [ ] Create provider drafts only after an explicit host submission request. Editing a private application draft triggers no Graph operation.
- [ ] Accept the shared structured prepared message with explicit sender, recipients, content and correlation; expose optional capability/setup failures instead of changing host composition policy.
- [ ] Use immutable provider message identity consistently during creation, later send and evidence references. Return preparation identity/progress before a separate send invocation so the host can persist it.
- [ ] Verify prepared content and explicit recipient intent before send, allowing only documented harmless protocol normalization.
- [ ] Represent lost draft-creation acknowledgement as preparation uncertainty without claiming a send occurred or automatically creating another draft.
- [ ] Send the selected draft once. Preserve status/headers from an empty 202 as acceptance evidence without inventing a message ID, fetched message or delivery result.
- [ ] Keep timeout/5xx/malformed send acknowledgement uncertain, with no replay, enclosing-job release or automatic cleanup that obscures possible acceptance.
- [ ] Check selected grant/generation before every phase, redact secrets, enforce Graph write limits and document scope requirements, possible leftover drafts and host durability responsibilities.

## Verification

- Exercise real Graph draft-create and separate send requests behind the public SDK using HTTP history and host-retained preparation handles.
- Cover explicit recipients/Bcc, immutable identity, empty 202, missing create response, ambiguous send, lifecycle changes between phases and no automatic resubmission.
- Keep this slice body-only and return explicit unsupported file/raw-MIME behavior until the relevant file capability is implemented.

## Integration notes

Reuse the prepared-message and operation-specific write contracts from ticket 16. This ticket does not introduce a durable package outbox or background editor-draft mirroring.


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

## Comments

- 2026-10-29: Claimed by `mailbox_ticket_19` for the authorized local SDK implementation on `feat/mailbox-sdk-19`, clean at integration `280fcf65c3557cd0c88e28af74f8e264123096ac`, with ancestry confirmed and no upstream. The approved Q13 public SDK, real Guzzle HTTP and host lifecycle/token seams apply under `matt:tdd`. Scope is body-only Graph draft preparation and a separately invoked one-attempt send; files/raw MIME, native reply/forward, evidence recovery, durable host state and live-provider operations remain outside this slice. Dependency setup, tests and static checks wait for the root's single shared resource slot.
- 2026-10-29: The first approved slot installed the unchanged 145-package lock serially with Composer scripts disabled. The public prepare/retain-handle/verify/send tracer first failed for the missing optional capability, then reproduced a shared one-attempt transport defect: a genuinely empty stream initially reports EOF only after the first read, so the empty 202 body was discarded as unreadable. Recognizing empty EOF fixed the tracer: 1 test / 26 assertions passed with the SDK-forbidden database harness. The new trailing `requestOnce(..., maxResponseBytes: ...)` retains the existing 65,536-byte default and status/safe-header overflow behavior. No broad/static gate ran; the shared check slot was released for ticket 11 before the next safety slice.
- 2026-10-29: The next RED/green slice reproduced overly strict JSON/address/body equality and a conflicting duplicate correlation header being accepted. Verification now permits only documented key/list order, domain/header/body-type casing and CRLF/LF normalization, rejects conflicting or duplicate caller headers, and checks the complete empty attachment collection because Graph's `hasAttachments` excludes inline files. The SDK-only Graph submission batch passed 51 tests / 247 assertions with two workers: content/recipient/file/identity mismatches stop before send; cross-context or changed-intent handles stop before further HTTP; grant checks run before create, verification and send; lost create acknowledgements cannot trigger recreation or send; ambiguous sends do not replay, release a host job or clean up. Public/debug evidence omits transport secrets, response bodies and the private persistable intent fingerprint. The ticket remains open for final bounds/configuration/shared regression and static gates.


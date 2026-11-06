# 19: Prepare and submit a Graph draft safely

**What to build:** Expose immutable draft preparation, a separate one-time send and honest acceptance or uncertainty.

**Blocked by:** [06: Connect and refresh Microsoft mailboxes](06-connect-and-refresh-microsoft-mailboxes.md), [16: Submit a prepared Gmail message once](16-submit-a-prepared-gmail-message-once.md)

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 29, 31, 32, 36, 61, 63, 64, 65, 66, 69, 72, 73, 76.

Prepare a Microsoft Graph submission draft and send it through separate public phases. The host can retain the immutable draft identity before requesting the irreversible send.

## Acceptance criteria

- [x] Create provider drafts only after an explicit host submission request. Editing a private application draft triggers no Graph operation.
- [x] Accept the shared structured prepared message with explicit sender, recipients, content and correlation; expose optional capability/setup failures instead of changing host composition policy.
- [x] Use immutable provider message identity consistently during creation, later send and evidence references. Return preparation identity/progress before a separate send invocation so the host can persist it.
- [x] Verify prepared content and explicit recipient intent before send, allowing only documented harmless protocol normalization.
- [x] Represent lost draft-creation acknowledgement as preparation uncertainty without claiming a send occurred or automatically creating another draft.
- [x] Send the selected draft once. Preserve status/headers from an empty 202 as acceptance evidence without inventing a message ID, fetched message or delivery result.
- [x] Keep timeout/5xx/malformed send acknowledgement uncertain, with no replay, enclosing-job release or automatic cleanup that obscures possible acceptance.
- [x] Check selected grant/generation before every phase, redact secrets, enforce Graph write limits and document scope requirements, possible leftover drafts and host durability responsibilities.

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
- 2026-10-29: Implemented in `40d7a4f84a186ae837799b6c7155aae8615af3f9` and final source `83e208caebc3eefb84777d4d3f59ba01b3b803d7`. Merged integration `9fc2b5f0a78b0a53c279701146318e41c6d605ee` as `ff4c8bd` before the final gates, without conflicts. `SupportsDraftSubmission::prepareSubmission(PreparedMessage $message, int $maxRequestBytes = 4_000_000): SubmissionPreparation` returns the immutable draft/context/correlation and preparation-only outcome/evidence before the separate `submitPrepared(SubmissionPreparation $preparation, PreparedMessage $message): SubmissionResult` call. The handle's original-intent fingerprint is private, host-persistable and excluded from ordinary JSON/debug output. Graph uses the existing client and `requestOnce`; Gmail's direct capability and its 65,536-byte acknowledgement default remain intact. Configured legacy reads still honor `prefer_immutable_ids=false`; each new phase explicitly requests immutable IDs.
- 2026-10-29: Final RED/green cases reproduced truncated empty 202 acceptance, missing safe verification headers, and malformed JSON `{}` being mistaken for an empty file collection. Unencoded declared lengths are now checked against actual bytes; failed verification retains filtered status/request headers through a source-compatible trailing `ApiRequestException::$headers` argument after the existing `providerCode` argument; provider JSON object/list shapes remain distinct. Ticket 11's Guzzle StreamHandler finding also received a public regression here: one-attempt receipts explicitly request identity encoding, set `decode_content=false`, and close unsupported encoded responses without reading. The small gzip fixture proves zero reads, retained safe status/headers and no replay, without a decompression-bomb fixture.
- 2026-10-29: The final explicit-file affected gate passed **225 tests / 1,358 assertions**, **exit 0**, in **16.66 seconds**, with two workers. It includes **64 Graph submission scenarios / 301 assertions**, Gmail submission, both clients, shared transport, explicit grants, Graph sync, configured/custom driver resolution and architecture. Targets were SDK-forbidden DB or the inspected Testbench SQLite memory/owned worker files with existing RefreshDatabase cleanup. The temporary `C:/Users/hello/.codex/scratch/mailbox-sdk-implementation/ticket19-affected.xml` differs from the original PHPUnit config only in explicit selected file paths and absolute bootstrap path; bootstrap/environment/failure policies are preserved. The equivalent filtered run displayed 224 passes but returned exit 1 twice before the final encoded-response scenario; this selection discrepancy is recorded without claiming a diagnosed cause or suppressing a policy.
- 2026-10-29: Full source PHPStan passed with `--debug` (single process). After the final write-only encoded-response guard, the shared trait and both using client contexts were rechecked together with `--debug`, exit 0; unchanged source results were reused. Pint agent formatting, strict Composer validation, installed platform requirements (PHP 8.4.25), locked audit (zero advisories/abandoned packages) and `git diff --check` passed sequentially. Installed Guzzle 7.16.1 was verified; the Composer graph is unchanged. [Messages documentation](../../../messages.md#preparing-and-sending-a-graph-draft) records phased persistence, exact permitted normalization, one JSON body, request/response bounds, scope/shared Exchange prerequisites, leftover drafts, host scheduling and private recovery responsibilities.
- 2026-10-29: Package acceptance for this body-only slice is complete. Files, raw MIME, dual text+HTML, native reply/forward and sent-copy inspection remain explicit unsupported or separate capability work. No full-suite rerun, new dependency, Workbench build, active consumer/database access, live provider operation, provider setup, push, publication or deployment occurred. Live permissions, provider normalization, delivery, actual consumer compatibility and the broader platform matrix remain separate gates; fixtures do not close them.

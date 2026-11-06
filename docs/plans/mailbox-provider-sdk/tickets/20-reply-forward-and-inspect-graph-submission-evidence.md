# 20: Reply, forward and inspect Graph submission evidence

**What to build:** Replace inherited draft defaults with caller intent and inspect the same provider identity after submission.

**Blocked by:** [15: Read complete conversations within an explicit scope](15-read-complete-conversations-within-an-explicit-scope.md), [19: Prepare and submit a Graph draft safely](19-prepare-and-submit-a-graph-draft-safely.md)

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 68, 69, 70, 71, 72, 74, 75.

Prepare native Graph reply and forward drafts from explicit context, normalize them to caller intent and inspect the same submission artifact without modifying the original message.

## Acceptance criteria

- [x] Use the selected native reply/forward operation and immutable draft identity for the intended source context, with explicit unsupported/incomplete results when context is unavailable.
- [x] Replace inherited recipients and unselected files on the new submission draft so the caller's explicit To/Cc/Bcc and file manifest remain authoritative.
- [x] Preserve caller-chosen content and supported context while documenting harmless provider normalization; never select reply-all recipients or historical Bcc automatically.
- [x] Limit all preparation changes to the new draft. Verify the original message and its attachments are untouched.
- [x] Verify normalized intent before send, retaining original preparation identity and caller correlation through acceptance and later inspection.
- [x] Inspect sent-copy evidence using the same immutable identity and trusted scope; delayed, absent, mismatched or 404 evidence remains inconclusive and never triggers another send.
- [x] Keep draft-preparation uncertainty separate from send uncertainty, preserve no-replay behavior and document native reply/forward and evidence limitations.

## Verification

- Exercise native reply/forward creation, draft normalization, send and evidence inspection through real Graph HTTP/mapping paths.
- Assert exact caller recipients, removal of inherited unselected files, unchanged original resources and stable immutable identity.
- Cover provider-normalized content, stale/wrong context, missing draft acknowledgement and delayed/missing sent-copy evidence with no automatic send.

## Integration notes

The empty file-manifest case must be complete here. Uploading chosen small/large files is delivered by ticket 21 and combined native file cases are checked by the compatibility and live gates.

## Comments

- 2026-10-29: Claimed by `mailbox_ticket_20` on `feat/mailbox-sdk-20`, isolated from the clean integration tip `fe66693636b2151ae15c5057df4591d4b82338a3` without an upstream. The approved seam is the public SDK with real Graph transport/mapping and provider HTTP fakes, with SDK database access forbidden. Heavy checks wait for the shared check slot. Publication, live sends, provider setup and consumer changes are outside this claim.
- 2026-10-29: Added the trailing optional `PreparedMessage::context`, containing `ReplyForwardContext(ReplyForwardOperation, ConversationRead, sourceMessageId)`. Graph rejects incomplete or mismatched provider/grant/generation/mailbox/source context before draft creation, checks the current source through its selected scope, and invokes native `createReply` or `createForward`. Caller sender, subject, body and complete To/Cc/Bcc arrays replace draft defaults. Correlation/custom headers are supplied during creation because Graph does not offer those headers in the later writable-field PATCH. Only the new draft and its copied attachments are mutated; the public scoped-conversation integration and HTTP histories verify that original resources remain untouched.
- 2026-10-29: The empty manifest removes copied inline and ordinary files regardless of `hasAttachments`, using one complete response bounded to 1,000 distinct IDs and 4 MiB. Paged, malformed, duplicated or over-budget metadata stops before deletion. An interrupted DELETE or normalization retains the acknowledged immutable draft ID and conversation with `PreparationOutcome::Uncertain`; it does not create another draft or send. Chosen-file upload/resume remains ticket 21. The existing one-attempt transport, raw clients, mandatory interfaces, configured modes and constructors are preserved.
- 2026-10-29: Added optional `SupportsDraftSubmissionInspection::inspectSubmission(preparation, message)` and shared `SubmissionEvidence` / `SubmissionEvidenceOutcome::{SentCopyObserved,Inconclusive}`. Inspection binds the selected authorization, immutable artifact, caller correlation, original intent and retained provider conversation, using one bounded immutable-ID GET. Delayed drafts, 404/503/timeouts, encoded/truncated/oversized/malformed responses and mismatched recipients/content/headers/files/identity remain inconclusive without replay. It preserves original preparation and acceptance facts and makes no delivery claim. `SubmissionResult` now has a trailing nullable `GrantContext`, populated by Graph; evidence artifact IDs are nullable for other providers without an acknowledged ID, while Graph requires its known immutable ID. Gmail context consumption and result binding are owned by ticket 17; no Gmail implementation region was changed here.
- 2026-10-29: TDD established the missing native context and inspection capability, then reproduced two native handle reconstructions missing the retained provider conversation and an equivalent cutoff instant changing the serialized identity fingerprint. Native handles now require their retained conversation before any send/inspection I/O. Fingerprints include chosen fields plus operation/source/conversation and canonical authorization/scope scalars, including the cutoff instant, rather than unrelated history or timezone representation. A separate missing-result-context RED established the additive receipt binding. Ordinary ticket 19 handles remain compatible.
- 2026-10-29: Merged current integration `a8e8a3ae2867a24b13a0f45c69d01c16aa8b101c` through `9c6db2041d773260e5d0c0700be2e50c4aa245e2`, preserving ticket 18 typed files/streaming and named Gmail guard/limits copies. The final same-policy explicit-file affected gate passed **361 tests / 2,132 assertions**, exit 0, in **18.39 seconds**, with two workers. It includes **72 native/evidence cases / 436 assertions**, **64 ordinary Graph cases / 307 assertions**, Gmail submission, complete conversations, grant isolation, Graph synchronization, real clients/transport, configured/custom driver resolution, direct resource construction and architecture. SDK tests forbid database access; existing feature/architecture tests use inspected Testbench SQLite memory/owned worker files and existing migration cleanup. The temporary `ticket20-affected.xml` and JUnit receipt under the implementation scratch directory preserve original bootstrap/environment/failure policies while selecting explicit files. Rejected multi-path ParaTest and malformed temporary XML setup attempts ran no tests and were not counted.
- 2026-10-29: Full-source PHPStan passed with `--debug` in one process. Pint agent formatting, strict Composer validation, installed platform requirements (PHP 8.4.25), locked audit (no advisories) and `git diff --check` passed sequentially. The Composer graph is unchanged. The later evidence ID type widening and formatting do not change non-null Graph behavior; source static checks include that widening. [Messages documentation](../../../messages.md#native-graph-replies-and-forwards) records the public calls, bounded cleanup, exact normalization tolerance, reconstruction requirements, leftover drafts, lookup uncertainty and host-owned durable scheduling. Official v1.0 Graph references were checked; no live-provider result is claimed.
- 2026-10-29: Package-local acceptance for this native empty-manifest/evidence slice is complete. No full suite, new dependency, Workbench build, active consumer/database operation, live send, provider setup, push, PR, release or deployment occurred. Ticket 21 uploads, ticket 17 Gmail context/inspection, live permissions/normalization/delivery and the full compatibility/platform gates remain separate work.

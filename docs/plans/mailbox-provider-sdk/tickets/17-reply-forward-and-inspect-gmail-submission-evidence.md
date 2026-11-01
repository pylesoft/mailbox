# 17: Reply, forward and inspect Gmail submission evidence

**What to build:** Preserve chosen reply/forward context and inspect original correlation evidence without resending.

**Blocked by:** [15: Read complete conversations within an explicit scope](15-read-complete-conversations-within-an-explicit-scope.md), [16: Submit a prepared Gmail message once](16-submit-a-prepared-gmail-message-once.md)

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 67, 68, 74, 75.

Use explicit caller context to submit Gmail replies and forwards, then inspect submission evidence without ever using absent evidence as a resend instruction.

## Acceptance criteria

- [x] Preserve the intended Gmail thread identity, matching subject and required reply identifiers/references when the caller explicitly requests a reply.
- [x] Keep forwarding distinct from replying, retaining only caller-chosen content, recipients, headers and file intent; do not choose reply-all policy or inherit historical Bcc.
- [x] Use complete scoped message/conversation content for context where requested, with explicit failure/incomplete results for unavailable or mismatched context.
- [x] Retain original caller correlation and provider acknowledgement identity for subsequent evidence inspection.
- [x] Inspect Sent/message identifiers through trusted scoped reads; delayed, absent, mismatched or incomplete evidence leaves uncertainty unresolved.
- [x] Evidence inspection performs no send and never converts a 404, timeout or missing sent copy into permission to replay submission.
- [x] Document supported threading/correlation behavior, forward semantics and the limits of acceptance evidence.

## Verification

- Exercise actual Gmail serialization, send and evidence-read paths for replies and forwards with international subjects, References/In-Reply-To and explicit recipient changes.
- Test wrong-thread context, delayed/missing/mismatched evidence and interrupted reads; assert original correlation survives and no second send occurs.

## Integration notes

Attachment-bearing forwards use the separate file capability when available. This slice is verifiable with body-only replies/forwards and must not silently select or copy files.
## Comments

- 2026-10-29: Claimed by mailbox_ticket_17 for local implementation with matt:implement-spec, TDD and the approved public SDK/HTTP-fake seam. Based on integration a8e8a3ae2867a24b13a0f45c69d01c16aa8b101c; waiting for ticket 20 shared context/evidence contracts before behavioral RED/GREEN. No publication or live provider operations.

- 2026-10-29: Completed locally on `feat/mailbox-sdk-17`; measured runtime is frozen at `342823a6f90b8c6aa1225a99b47c979da6f3b950`, after integrating shared context/evidence contracts, captured authorization guards and current integration `e300c2335c8d0c490de3293fc3016936f52b263e`. Reused ticket 20's shared DTO contracts and left Graph implementation unchanged.
- Reply submission consumes complete scoped context, preserves an observed thread subject (including the original subject when replying to a later prefixed message), supplies typed reply identifiers and the actual Gmail JSON thread ID, and preserves explicitly chosen recipients, Bcc, content and immutable files. Forwarding validates context without inheriting recipients, files or reply headers. Complete attachment-only sources remain usable with their validated metadata. One strictly validated caller Message-ID is supported; duplicate case variants and malformed IDs fail before HTTP.
- `SupportsGmailSubmissionInspection::inspectSubmission(SubmissionResult)` requires exact original connection/grant/generation/mailbox binding. A known message ID gets one bounded metadata GET; the same ID/thread, SENT label and one logically matching correlation establish only `SentCopyObserved`. Encoded correlation uses the existing received-header decoder. Missing provider identity returns `Inconclusive` with a null artifact and no guessed Gmail correlation search. Missing, malformed, delayed, mismatched or interrupted observations remain inconclusive and never change the original receipt or issue another send.
- TDD reproduced actual missing wire thread ID, forbidden caller Message-ID, ambiguous source IDs, malformed label evidence, wrong/missing contextual acknowledgement threads, rejection of complete attachment-only content, and a serialized UTF-8 correlation being falsely inconclusive. Each received its focused GREEN after the minimal correction. Test seams are the public SDK, real serialization/HTTP/mapping and faked provider responses; SDK persistence is disabled with a forbidden database driver.
- Final focused behavior: **69 tests / 433 assertions**, two Pest workers, terminal exit 0. Affected compatibility selection: **452 tests / 2,540 assertions**, two workers, terminal exit 0 with an explicit temporary same-policy file selection (removed afterward); unchanged paths were reused after the final narrow header repair. The wire file was excluded from that selection, so it has zero wire skips. Earlier broad regex selections printed passes with exit 1 and are not counted as a gate. Legacy database-backed cases use only Testbench-owned SQLite memory/worker files with `RefreshDatabase`, never application databases.
- Static/format/dependencies: full-source PHPStan `--debug` single process exited 0 on settled source; isolating the client alone produced two trait-use scope artifacts, so the complete source scope was checked instead without ignores. Final Pint, including `--dirty --format agent`, exited 0. Strict Composer validation, platform requirements, locked audit and direct dependency inspection exited 0; zero advisories and no dependency/lock changes. Whitespace checks passed.
- Final serial TLS measurement fingerprint: **`71c3a03a34730ed48783b263f9f4fc41c5d2f9690686412143e0af032bb3c184`**, PHP **8.4.25**, **64-bit**, Guzzle **7.16.1**, Mime **8.1.10**. Tiny cold control: **9,276,040 B** absolute PHP heap growth. Cold 10 MiB: **14,349,539 MIME / 19,132,729 JSON bytes**, **9,267,264 B** absolute growth, **0 B** above control. Cold 20 MiB: **28,698,660 MIME / 38,264,890 JSON bytes**, **9,266,376 B** absolute growth, **0 B** above control. Separate invocations passed **1/10**, **1/12** and **1/13** tests/assertions, each exit 0.
- Complementary warm 20 MiB attachment-bearing reply: a tiny fixture then the target in the same test process, sequential owned TLS servers. The actual incremental peak was **161,488 B**, below the unchanged **8,388,608 B** budget; received MIME **28,698,767 B**, JSON **38,265,058 B**. The server independently verified the requested thread, typed reply identifiers and exact file bytes/hashes/names/types. The test retained owned process and temporary-file cleanup. **1 test / 27 assertions**, exit 0. The one operation budget starts before context/header preparation and remains active through JSON spooling and HTTP reads. There is no production warm-up or full-message buffering.
- Reproducible fixture logs and distinct cold/warm control receipts are retained outside the repository under `C:/Users/hello/.codex/scratch/mailbox-sdk-implementation/ticket17-wire-*-342823a.{log,json}`; settled static log is `ticket17-phpstan-settled.log`. These prove local protocol and PHP heap behavior, not Gmail account acceptance, delivery, native/RSS memory, other runtimes or live provider parity. Live gates 26/27 and full integration verification remain separate. No push, PR, release, provider setup, live send, consumer migration or application database change occurred.

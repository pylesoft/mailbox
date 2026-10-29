# 10: Read externalized Gmail bodies completely

**What to build:** Hydrate externalized text under limits and distinguish it from named or inline attachments.

**Blocked by:** [03: Read through an explicit grant without package tables](03-read-through-an-explicit-grant-without-package-tables.md), [09: Read RFC addresses and encoded headers correctly](09-read-rfc-addresses-and-encoded-headers-correctly.md)

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 33, 34, 35, 49, 53, 54, 55.

Return complete Gmail text bodies when the provider externalizes MIME parts, without misclassifying body fragments as downloadable files.

## Acceptance criteria

- [x] Identify text/plain, text/html and multipart body parts through the actual message/MIME mapping path, including text returned by a separate attachment/body fetch.
- [x] Hydrate externalized body bytes and decode supported content encodings without replacing missing content with an empty body.
- [x] Distinguish unnamed body parts from named files and inline files, retaining filename, media type and Content-ID metadata where applicable.
- [x] Reject malformed/truncated base64url and inconsistent content, or return explicit unfinished work where bounded hydration cannot complete.
- [x] Enforce actual response/content and hydration budgets, not only a count of HTTP calls; missing or budget-limited content cannot be reported as complete.
- [x] Keep sanitization, presentation and application file policy in the host. Preserve existing resource behavior where compatible and document completeness/limit results.

## Verification

- Read messages through real Gmail HTTP and mapping code with inline/externalized text, nested multipart, encodings, named text files, inline images and empty-but-valid bodies.
- Cover malformed encoding, missing part responses, actual payload overflow and interrupted hydration; assert both returned content and the absence of false attachments.

## Comments

- 2026-10-29: Claimed by the ticket 10 implementer on `feat/mailbox-sdk-10`, based on integration `0e68d17`. Scope is Gmail body hydration, strict body decoding and shared body/file classification through the existing message resources and DTOs. Q13 already confirms the public resource plus real Guzzle/provider-HTTP test seam. Shared bounded HTTP transport remains ticket 12's responsibility and will be reused after integration. No live provider, consumer, dependency or publication changes.
- 2026-10-29: Implemented in `3a662a2`, `f22e427` and verified source `da3da754379c2b61ee3d36111b895631c4884768`. Merged ticket 12's bounded transport and the integration tip containing ticket 05 (`00beee6f4eff5a0ab01b9399d946b1a63e5164ef`). `GmailMessageReader` is shared by direct, query and legacy delta reads. It hydrates unnamed text, validates canonical base64url and both original/fetched lengths, converts supported charsets to UTF-8, preserves valid empty/zero text and refuses missing or inconsistent content. Shared MIME classification retains named/inline files, decoded filenames, part IDs and Content-ID metadata, including multipart/related root selection.
- 2026-10-29: Added optional `BodyReadLimits` to the concrete Gmail message resource's `get()` and `body()` methods: 8 MiB aggregate response bytes, 4 MiB decoded/normalized body bytes, 32 external body parts and 256 examined MIME parts by default. The reader can share ticket 12's `ResponseBudget`. Overflow throws `ResponseLimitExceededException`; incomplete content never returns a partial body/DTO or a completed legacy delta round. Mixed sibling HTML/plain text is explicitly unsupported by the existing single-format `BodyDto`; alternatives prefer HTML and same-format fragments retain their order. Sanitization and host file policy remain outside the SDK. See [body read limits](../../../messages.md#gmail-body-limits).
- 2026-10-29: An interrupted-stream regression exposed raw read exceptions in the shared bounded transport. With parent coordination, the receipt/read section now closes streams and redacts unknown failures. An optional `getBounded(..., retry: false)` control preserves safe retries for the existing Gmail read path when enabled. Every error/success attempt consumes the same response budget and rechecks authorization. Existing retry/backoff policy is reused; bounded reads never release the enclosing job. Ticket 12's default remains one attempt.
- 2026-10-29: Verification after the ticket 05 merge: affected Pest gate **234 passed, 969 assertions**, `--parallel --processes=2`, 38.65 seconds. Filter: `GmailBodyReadTest|GmailMessage|GmailDeltaSyncTest|GmailAttachmentDownloadTest|GmailMailboxResourceDirectTest|MessageDtoTest|ArraySerializableTest|ReceivedMessageMappingTest|PerformsApiRequestsTest|ExplicitGrantReadTest|GmailInitialSyncTest|GrantRefreshTest|ArchitectureTest|StrictTypesAndPhpStanTest|TypeCoverageTest`. Tests use SDK-forbidden database access or the inspected isolated SQLite harness. Real Guzzle fixtures cover nested/related MIME, inline and external text, named/inline files, supported/invalid charsets, malformed/truncated bytes, wrong lengths, missing external content, actual/aggregate overflow, interrupted stream cleanup, safe 503/network retries, stale lifecycle before retry, no queue release and default single-attempt synchronization. Full PHPStan with `--debug` (one process), Pint on dirty and all ticket PHP paths, and `git diff --check` passed. Formatting afterward changed style only. The parent owns the next combined integration suite; no extra full-suite run was started in this worktree under the machine resource constraint. Live Gmail, consumer applications, dependency changes and publication were not exercised.


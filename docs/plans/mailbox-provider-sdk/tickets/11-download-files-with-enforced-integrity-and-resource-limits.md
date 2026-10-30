# 11: Download files with enforced integrity and resource limits

**What to build:** Retrieve valid file bytes through real transports with safe destinations, decoding, byte limits and owned cleanup.

**Blocked by:** [01: Follow Graph continuations safely](01-follow-graph-continuations-safely.md), [10: Read externalized Gmail bodies completely](10-read-externalized-gmail-bodies-completely.md)

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 27, 33, 35, 36, 55, 56, 57.

Download provider files through the public resource interface with integrity and resource limits enforced along the entire transport and decoding path.

## Acceptance criteria

- [x] Return actual complete attachment bytes and metadata for Gmail and Graph, including valid empty files, without converting malformed or truncated data into empty success.
- [x] Validate declared and observed sizes, decoding completeness and supported content forms; expose explicit failure/incomplete results when bytes cannot be obtained within limits.
- [x] Enforce byte and memory budgets through HTTP receipt, base64 decoding and file output; a stream-shaped API that buffers the full body is insufficient.
- [x] Restrict authenticated download URLs and redirects to trusted provider destinations and redact private access material from diagnostics.
- [x] Use private owned temporary resources only where required, with bounded allocation and cleanup on success, cancellation and error. Do not delete host-owned sources.
- [x] Leave private storage, file authorization, allowed media types and consumer size policy to the host.
- [x] Preserve compatible attachment APIs and document actual memory/size guarantees and limitations.

## Verification

- Exercise public attachment reads using real client/decoding paths with controlled provider HTTP; compare exact bytes, lengths and inline metadata.
- Test oversized actual responses despite small declarations, malformed/truncated encoding, interrupted streams, hostile redirects and owned-resource cleanup.
- Measure bounded-memory behavior on representative large payloads; distinguish verified limits from untested constant-memory claims.

## Comments

- 2026-10-29: Claimed by the ticket 11 implementer on `feat/mailbox-sdk-11`, based on integration `258a45ad76a18ebefbacb93b9184ea9029257a1b`. Work is limited to complete bounded attachment reads, compatible download storage/events/DTOs, tests, and actual guarantee documentation. Approved Q13 public SDK/real Guzzle/host-adapter seams apply. Dependency setup and first RED are queued behind the globally serialized check slot; no active application disk/database or live provider is used.
- 2026-10-29: Initial public SDK/real Guzzle RED→GREEN cases reject truncated Gmail base64, conflicting decoded size declarations, missing content and aggregate actual responses that exceed the byte budget despite a small HTTP declaration. Explicit zero-byte content remains valid. A dense JSON fixture with 20,000 small objects now fails before native decoding and stays below its 2 MiB read-memory budget. The representative 10 MiB Gmail stream is generated through a file-backed HTTP fixture: exact bytes match, and measured PHP heap peak growth fell from 63,092,304 bytes before chunked decoding to 29,375,400 bytes after it, on 64-bit PHP 8.4.25 with a 50 MiB read-memory budget. This establishes a bounded encoded-JSON implementation on this runtime, not constant memory or a runtime-independent allocator bound. The terminal focused tranche passed 9 tests / 20 assertions. Storage output, Graph reads and final compatibility gates remain pending; the current Gmail `download()` still buffers until the next storage tracer removes that cast.
- 2026-10-29: Completed in source `861a644`, documentation `d97d2bd`, and tested merge `981b4688af2e1274c3181ca4db44c165eb05f0c3`, which includes current integration `b94d894436066d8482f136e868897ea65da94dee`. The mandatory `AttachmentResource::metadata()`, `download()`, `stream()`, existing DTO shape, configured storage, SHA-256 naming/deduplication and download/skipped events remain compatible. Built-ins expose optional `SupportsAttachmentReadLimits::withReadLimits(AttachmentReadLimits): AttachmentResource`; defaults are 64 MiB response bytes, 32 MiB file bytes and a 128 MiB controlled-allocation budget. Each operation shares its response/memory budget across metadata and content. No new dependency, parser framework, host file-type policy, private-storage policy or authorization model was added.
- 2026-10-29: Real HTTP receipt disables transparent decompression, requests identity content encoding and rejects other HTTP content encodings before any body read. Gzip media files still retain their exact bytes. Reads retain the trusted provider target/port checks and reject redirects; rejected responses close without logging private access material. Gmail uses the existing MIME classifier, strict chunked base64url validation and owned temporary output. Graph compares ordinary file declarations with observed bytes, keeps item MIME representation semantics distinct, and rejects unsupported reference forms. `metadata()->size` retains the provider declaration (zero when omitted); `download()->size` reports actual stored bytes. Provider-derived path segments and dot-only names are safe. Existing preferred files and conflicting hash-suffix files are retained. The shared hash/storage path has no whole-file string cast; interrupted reads, explicit close and caller abandonment clean owned temporary resources without deleting host files.
- 2026-10-29: Allocation RED→GREEN cases cover dense valid/truncated JSON, Graph error JSON before reset-code inspection, dense MIME parameter headers before the existing parser and long path-copy inputs before filesystem output. Conservative native-allocation reservations have overflow checks and are verified on 64-bit PHP 8.4.25. Final serial 10 MiB measurements were 29,375,912 bytes of extra heap for Gmail under a 50 MiB budget, and 92,816 bytes for the warmed public Graph metadata→download path under 2 MiB, including receipt, hash and local storage. Cold initialization is separate: a cold 2 MiB operation rejected after 4,593,400 bytes of framework/transport allocation. The configured amount is not a hard cold-process heap ceiling; dependency/host initialization, caller string casts/detached resources and arbitrary host HTTP/filesystem adapters are outside the controlled-buffer proof. Gmail remains O(bounded encoded JSON), with no constant-memory or runtime-independent allocator claim.
- 2026-10-29: Final checks on `981b468`: 305 affected tests / 1,267 assertions, parallel with exactly two workers, exit 0 (18.10 s); the seven allocation/large-file cases ran separately and serially, 24 assertions, exit 0 (1.64 s). The affected selection covers attachment SDK reads, both legacy download fixtures, shared transport, both provider clients, Gmail body reads, Gmail/Graph synchronization, architecture/custom-driver resolution and both provider submission contracts. A temporary PHPUnit selection config preserved the repository bootstrap, environment and failure policies; it selected those files without changing repository test configuration. Full-source PHPStan `analyse --debug --no-progress` ran separately, exit 0 with no errors. Pint applied formatting and passed its final dirty check; strict Composer validation, installed PHP 8.4.25 platform requirements and `git diff --check` passed. SDK cases use the forbidden-database harness; legacy fixtures use only Testbench SQLite memory/owned worker files and fake storage. No active application database/disk, Workbench build, live provider, publication or full-suite run was used. Cross-runtime/live acceptance remains with the later plan gates.

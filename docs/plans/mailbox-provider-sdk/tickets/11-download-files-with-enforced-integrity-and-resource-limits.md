# 11: Download files with enforced integrity and resource limits

**What to build:** Retrieve valid file bytes through real transports with safe destinations, decoding, byte limits and owned cleanup.

**Blocked by:** [01: Follow Graph continuations safely](01-follow-graph-continuations-safely.md), [10: Read externalized Gmail bodies completely](10-read-externalized-gmail-bodies-completely.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 27, 33, 35, 36, 55, 56, 57.

Download provider files through the public resource interface with integrity and resource limits enforced along the entire transport and decoding path.

## Acceptance criteria

- [ ] Return actual complete attachment bytes and metadata for Gmail and Graph, including valid empty files, without converting malformed or truncated data into empty success.
- [ ] Validate declared and observed sizes, decoding completeness and supported content forms; expose explicit failure/incomplete results when bytes cannot be obtained within limits.
- [ ] Enforce byte and memory budgets through HTTP receipt, base64 decoding and file output; a stream-shaped API that buffers the full body is insufficient.
- [ ] Restrict authenticated download URLs and redirects to trusted provider destinations and redact private access material from diagnostics.
- [ ] Use private owned temporary resources only where required, with bounded allocation and cleanup on success, cancellation and error. Do not delete host-owned sources.
- [ ] Leave private storage, file authorization, allowed media types and consumer size policy to the host.
- [ ] Preserve compatible attachment APIs and document actual memory/size guarantees and limitations.

## Verification

- Exercise public attachment reads using real client/decoding paths with controlled provider HTTP; compare exact bytes, lengths and inline metadata.
- Test oversized actual responses despite small declarations, malformed/truncated encoding, interrupted streams, hostile redirects and owned-resource cleanup.
- Measure bounded-memory behavior on representative large payloads; distinguish verified limits from untested constant-memory claims.

## Comments

- 2026-10-29: Claimed by the ticket 11 implementer on `feat/mailbox-sdk-11`, based on integration `258a45ad76a18ebefbacb93b9184ea9029257a1b`. Work is limited to complete bounded attachment reads, compatible download storage/events/DTOs, tests, and actual guarantee documentation. Approved Q13 public SDK/real Guzzle/host-adapter seams apply. Dependency setup and first RED are queued behind the globally serialized check slot; no active application disk/database or live provider is used.
- 2026-10-29: Initial public SDK/real Guzzle RED→GREEN cases reject truncated Gmail base64, conflicting decoded size declarations, missing content and aggregate actual responses that exceed the byte budget despite a small HTTP declaration. Explicit zero-byte content remains valid. A dense JSON fixture with 20,000 small objects now fails before native decoding and stays below its 2 MiB read-memory budget. The representative 10 MiB Gmail stream is generated through a file-backed HTTP fixture: exact bytes match, and measured PHP heap peak growth fell from 63,092,304 bytes before chunked decoding to 29,375,400 bytes after it, on 64-bit PHP 8.4.25 with a 50 MiB read-memory budget. This establishes a bounded encoded-JSON implementation on this runtime, not constant memory or a runtime-independent allocator bound. The terminal focused tranche passed 9 tests / 20 assertions. Storage output, Graph reads and final compatibility gates remain pending; the current Gmail `download()` still buffers until the next storage tracer removes that cast.


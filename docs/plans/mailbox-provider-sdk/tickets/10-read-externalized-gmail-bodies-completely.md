# 10: Read externalized Gmail bodies completely

**What to build:** Hydrate externalized text under limits and distinguish it from named or inline attachments.

**Blocked by:** [03: Read through an explicit grant without package tables](03-read-through-an-explicit-grant-without-package-tables.md), [09: Read RFC addresses and encoded headers correctly](09-read-rfc-addresses-and-encoded-headers-correctly.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 33, 34, 35, 49, 53, 54, 55.

Return complete Gmail text bodies when the provider externalizes MIME parts, without misclassifying body fragments as downloadable files.

## Acceptance criteria

- [ ] Identify text/plain, text/html and multipart body parts through the actual message/MIME mapping path, including text returned by a separate attachment/body fetch.
- [ ] Hydrate externalized body bytes and decode supported content encodings without replacing missing content with an empty body.
- [ ] Distinguish unnamed body parts from named files and inline files, retaining filename, media type and Content-ID metadata where applicable.
- [ ] Reject malformed/truncated base64url and inconsistent content, or return explicit unfinished work where bounded hydration cannot complete.
- [ ] Enforce actual response/content and hydration budgets, not only a count of HTTP calls; missing or budget-limited content cannot be reported as complete.
- [ ] Keep sanitization, presentation and application file policy in the host. Preserve existing resource behavior where compatible and document completeness/limit results.

## Verification

- Read messages through real Gmail HTTP and mapping code with inline/externalized text, nested multipart, encodings, named text files, inline images and empty-but-valid bodies.
- Cover malformed encoding, missing part responses, actual payload overflow and interrupted hydration; assert both returned content and the absence of false attachments.


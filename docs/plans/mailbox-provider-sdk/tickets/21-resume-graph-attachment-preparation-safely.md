# 21: Resume Graph attachment preparation safely

**What to build:** Attach small and large files with observable upload progress, immutable bytes and no duplicate work after ambiguity.

**Blocked by:** [18: Freeze file streams for Gmail submission](18-freeze-file-streams-for-gmail-submission.md), [19: Prepare and submit a Graph draft safely](19-prepare-and-submit-a-graph-draft-safely.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 28, 36, 56, 58, 59, 60, 66, 72.

Prepare Graph attachments using size-appropriate operations and resumable bounded upload sessions, without leaking mailbox authority or duplicating attachments after uncertain acknowledgements.

## Acceptance criteria

- [ ] Consume the shared immutable file manifest and source binding, preserving exact bytes, caller identity, name, media type, length and supported inline metadata.
- [ ] Respect Graph's write-request limit and choose the provider-documented small-attachment versus large-file upload-session path. Validate the host's 10 MiB-per-file and 20 MiB aggregate targets without making them global SDK limits.
- [ ] Send bounded sequential byte ranges, expose acknowledged progress and returned attachment identity, and allow the host to retain private recovery state before resuming.
- [ ] Treat upload URLs as preauthenticated secrets obtained through the trusted provider flow. Never attach the Graph bearer token, expose the URL publicly or rely on unrestricted redirects.
- [ ] Revalidate exact source content across every open/resume. After an acknowledged prefix, a same-length changed source must stop before any additional upload or send.
- [ ] Distinguish explicit failed preparation, lost attachment acknowledgement, expired/unusable session and uncertain send. Inspect ambiguous attachment completion before adding or replaying another attachment.
- [ ] Follow Outlook-specific session expiry/recovery semantics without assuming OneDrive recovery endpoints or guarantees.
- [ ] Verify the final selected file manifest before sending the immutable draft, retain lifecycle guards and no-replay behavior, and document known shared/delegated large-file limitations as live gates.

## Verification

- Exercise small and upload-session attachment preparation through real Graph transport, asserting range headers, exact bytes, persisted progress, attachment identity and final manifest.
- Cover ambiguous final range, interrupted/expired sessions, response limits, source mutation after an acknowledged prefix, missing binding and lifecycle changes.
- Assert no bearer on preauthenticated uploads, no secret URL in public/debug output, no duplicate attachment after uncertainty and no send before complete preparation.

## Integration notes

Package HTTP fixtures establish protocol behavior only. Shared/delegated 10 MiB support remains unproven until the Microsoft live-validation gate passes.

## Comments

- 2026-10-29: Claimed by the ticket 21 implementer on `feat/mailbox-sdk-21`, based on integration `36bd463`. Authorized scope is local Graph attachment preparation, public SDK/real HTTP-fake tests, and verification. No provider setup, live sends, publication, or consumer changes. The shared check slot remains with ticket 23; prepare the first TDD tracer without running checks until the root grants the slot.


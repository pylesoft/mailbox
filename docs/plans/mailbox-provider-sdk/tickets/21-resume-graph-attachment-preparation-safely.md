# 21: Resume Graph attachment preparation safely

**What to build:** Attach small and large files with observable upload progress, immutable bytes and no duplicate work after ambiguity.

**Blocked by:** [18: Freeze file streams for Gmail submission](18-freeze-file-streams-for-gmail-submission.md), [19: Prepare and submit a Graph draft safely](19-prepare-and-submit-a-graph-draft-safely.md)

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 28, 36, 56, 58, 59, 60, 66, 72.

Prepare Graph attachments using size-appropriate operations and resumable bounded upload sessions, without leaking mailbox authority or duplicating attachments after uncertain acknowledgements.

## Acceptance criteria

- [x] Consume the shared immutable file manifest and source binding, preserving exact bytes, caller identity, name, media type, length and supported inline metadata.
- [x] Respect Graph's write-request limit and choose the provider-documented small-attachment versus large-file upload-session path. Validate the host's 10 MiB-per-file and 20 MiB aggregate targets without making them global SDK limits.
- [x] Send bounded sequential byte ranges, expose acknowledged progress and returned attachment identity, and allow the host to retain private recovery state before resuming.
- [x] Treat upload URLs as preauthenticated secrets obtained through the trusted provider flow. Never attach the Graph bearer token, expose the URL publicly or rely on unrestricted redirects.
- [x] Revalidate exact source content across every open/resume. After an acknowledged prefix, a same-length changed source must stop before any additional upload or send.
- [x] Distinguish explicit failed preparation, lost attachment acknowledgement, expired/unusable session and uncertain send. Inspect ambiguous attachment completion before adding or replaying another attachment.
- [x] Follow Outlook-specific session expiry/recovery semantics without assuming OneDrive recovery endpoints or guarantees.
- [x] Verify the final selected file manifest before sending the immutable draft, retain lifecycle guards and no-replay behavior, and document known shared/delegated large-file limitations as live gates.

## Verification

- Exercise small and upload-session attachment preparation through real Graph transport, asserting range headers, exact bytes, persisted progress, attachment identity and final manifest.
- Cover ambiguous final range, interrupted/expired sessions, response limits, source mutation after an acknowledged prefix, missing binding and lifecycle changes.
- Assert no bearer on preauthenticated uploads, no secret URL in public/debug output, no duplicate attachment after uncertainty and no send before complete preparation.

## Integration notes

Package HTTP fixtures establish protocol behavior only. Shared/delegated 10 MiB support remains unproven until the Microsoft live-validation gate passes.

## Comments

- 2026-10-31 correction during AI Clerk integration: designated Global Graph application-mode checks verified the original raw bytes and SHA-256 for a 2,999,999-byte direct attachment and a 3,000,000-byte upload-session attachment, then acknowledged deletion of the owned unsent draft. The direct JSON request was 4,000,151 bytes. Graph returned a session on the exact `outlook.office365.com` host and reported attachment sizes different from their raw lengths. The SDK now accepts that additional exact host, binds completion to the original session host, and retains strict original raw length/hash verification independently of reported size. Small-file encoding honors the explicit caller budget; successful echoed receipts have a finite separate response allowance. Public request/memory defaults and other account-mode live gates remain unchanged. A separate real HTTP direct-attachment fixture measured 13,591,184 bytes of cold incremental heap under an explicit 16 MiB budget; it establishes that tested runtime path only. The historical checks below remain historical and did not qualify these provider differences.

- 2026-10-29: Claimed by the ticket 21 implementer on `feat/mailbox-sdk-21`, based on integration `36bd463`. Authorized scope is local Graph attachment preparation, public SDK/real HTTP-fake tests, and verification. No provider setup, live sends, publication, or consumer changes. The shared check slot remains with ticket 23; prepare the first TDD tracer without running checks until the root grants the slot.

- 2026-10-29: Implemented and verified at runtime commit `3d4cd49`, after merging the latest integration `f84fda6` (tickets 17, 18, 19, 20, 23 and 24 included). The optional `SupportsDraftAttachmentPreparation` capability advances one small-file POST, session creation or bounded range per call, with serializable private progress. `inspectAttachments` checks the original immutable draft and candidate raw SHA-256/length before accepting a lost acknowledgement; absent, duplicated or mismatched evidence remains uncertain. Every resume verifies the original source binding; final send verifies all original sources and the exact remote file manifest. Native reply/forward context, conversation identity, original-message safety, lifecycle guards and one-attempt send behavior remain intact.

- TDD evidence: missing staged capability, unsupported large-file path and missing explicit inspection each failed through the public SDK before implementation. Later regressions caught definite HTTP 400/429 responses mislabeled as uncertainty, missing large-source memory preflight, and raw cURL client defaults able to override the managed upload request. The final transport suppresses origin credentials/cookies and managed URL/method/header/body/read/redirect/debug overrides while preserving proxy authentication, TLS/network settings, timeouts, `CONNECT_TO` and supported security callbacks. Constants are checked before use when cURL is unavailable.

- Terminal verification: the exact 22-file affected selection used the original PHPUnit environment policy, SDK-forbidden database or isolated Testbench SQLite, no CLI filter, and two Pest workers: **637 passed, 8 explicitly skipped, 3,107 assertions** (35.99s, exit 0). The eight skips were opt-in Gmail/Graph wire cases. After the mixed-state/list correction and final formatting, focused Graph preparation coverage passed **49 tests, 395 assertions** (3.51s, exit 0); unchanged affected results were retained. Full-source PHPStan `--debug` passed without errors in one process; touched-file Pint and required `--dirty --format agent` passed. Composer strict validation, installed platform requirements, locked audit and direct-version inspection all exited 0; audit reported no advisories. Composer files and dependencies are unchanged by this ticket.

- Four fresh **serial** real TLS/cURL cases passed on PHP 8.4.25, 64-bit, with the same source-and-lock fingerprint `e4a000de00d1ceb14ee05daac46f0093800c76728328ac4ebe78b301a2cf371c`. Cold 3,000,001-byte control: 5,244,128-byte peak growth (1 test / 13 assertions). Cold 10 MiB: 5,244,136-byte peak, 8 bytes above control (1 / 17). Cold 20 MiB aggregate: 5,244,136-byte peak, 8 bytes above control (1 / 26). A complementary warm 20 MiB case had a direct 263,800-byte incremental peak (1 / 26). All exited 0. This establishes the tested payload allocation scope against the unchanged 8 MiB budget; it is not a universal cold-process heap promise. An independent owned local server verified sequential ranges, exact bytes/SHA and absence of Authorization, using the original trusted Outlook HTTPS URL and local CA. Dummy raw cURL credentials and wrong target/method/body defaults did not reach the wire. Host source files remained present and opened source streams closed.

- `docs/messages.md` documents the staged API, private recovery storage, fixed Outlook expiry, lack of an assumed session-status GET, final verification, host callback responsibilities and live limitations. No live provider/account operation, shared/delegated large-file acceptance, full package suite, consumer migration, push, PR or release was performed. Shared/delegated 10 MiB and tenant/provider behavior remain the separate open Microsoft live gate; closing this implementation ticket does not close that gate or the parent specification.

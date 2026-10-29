# 18: Freeze file streams for Gmail submission

**What to build:** Bind separate prepared-file streams to immutable content and serialize bounded Gmail attachments safely.

**Blocked by:** [16: Submit a prepared Gmail message once](16-submit-a-prepared-gmail-message-once.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 55, 56, 57, 59, 61, 63, 64.

Add prepared files to Gmail submission and establish the immutable file contract reused by Graph upload preparation. Validate the actual MIME/base64url transport behavior.

## Acceptance criteria

- [ ] Accept caller file identity, name, media type, byte length, inline/Content-ID metadata and reopenable or seekable bytes, without exposing third-party MIME objects.
- [ ] Bind every open to the exact immutable content using host-guaranteed immutability or a verified fingerprint strategy. Matching path, identity, name and length alone is insufficient.
- [ ] Reject changed or unestablished byte binding before further serialization/upload/send; reopening a same-length modified source cannot mix file versions.
- [ ] Serialize selected files and inline parts with safe international names and the caller's exact recipient/content intent. Do not inherit or silently replace files with external links.
- [ ] Enforce actual decoded/wire size and memory budgets through MIME, base64url and JSON construction, using private owned temporary spooling only where needed.
- [ ] Exercise the existing consumer's 10 MiB-per-file and 20 MiB aggregate parity targets while leaving limits configurable; report provider/request limits explicitly.
- [ ] Clean up owned temporary resources on success/failure without modifying host sources, and retain the one-attempt/uncertainty submission contract.
- [ ] Document immutable-source obligations, real memory guarantees, host file policy and supported inline/attachment behavior.

## Verification

- Send file-bearing messages through real preparation, encoding and HTTP inspection; decode and compare exact file bytes, names, content types and Content-IDs.
- Mutate a same-length source between opens and verify rejection before send. Cover incorrect length, interrupted reads, resource limits, cleanup and one-send behavior.
- Measure representative 10 MiB and aggregate-target cases through the actual wire path; a stream interface alone is not proof of bounded memory.

## Integration notes

Storage/checksum ownership remains with the host. Reuse the standard file/source representation; do not add a storage framework or a new digest abstraction.

## Comments

- 2026-10-29: Claimed by `mailbox_ticket_18` for authorized local SDK implementation on `feat/mailbox-sdk-18`, clean at integration `b94d894436066d8482f136e868897ea65da94dee`, with ancestry confirmed and no upstream. The approved Q13 public SDK/real MIME/Guzzle/host-port seams apply under `matt:tdd`. This ticket owns the shared immutable prepared-file representation and bounded outbound Gmail MIME/base64url/JSON path; ticket 11 owns bounded read/stream primitives and ticket 21 will reuse the file contract. Storage, expected SHA-256, authorization and type policy remain host responsibilities. Each opened source must match that original expected fingerprint and length before exposing any bytes for serialization or upload. Tests use the SDK-forbidden database harness; installs/checks use only the root's explicitly granted shared slot. No new dependency, live provider operation, active consumer/database access or publication is authorized.
- 2026-10-29: Serialized setup installed the unchanged 145-package lock with Composer scripts disabled, exit 0. The first public file-submission tracer failed for the missing prepared-file contract, then passed **1 test / 20 assertions**, exit 0, through actual Symfony MIME, Gmail base64url/JSON and Guzzle HTTP inspection. Independent parser assertions recover exact binary bytes, international names, media types, inline disposition/Content-ID, text/HTML, explicit To/Cc/Bcc and correlation. `PreparedFile` holds the host's original expected SHA-256 and reopens into a private verified snapshot before serialization; its source and fingerprint are excluded from ordinary debug/JSON fields. This first small-message slice still buffers MIME/base64/JSON; large-file, exact no-rewrite metadata, resource-limit, cleanup and measured actual-wire acceptance remain open. The shared check slot was released after the tracer.
- 2026-10-29: Merged integration `0dca168e0c6af8c3869ebd2a5803bad5cf28da05` as `8375b098a2ddcbf8cf0f6828bcb1f434cc350456` without conflicts, retaining ticket 11's bounded-read guards. The next public RED/green tranche reproduced automatic HTML/Content-ID rewriting (including Symfony RelatedPart's generated IDs), an overly strict shared Content-ID restriction, and accepted duplicate file identities/Content-IDs. Necessary owned MIME-part construction now preserves chosen content and inline metadata; inline disposition does not require or invent a Content-ID. The provider-neutral source accepts safe bare IDs; Gmail MIME explicitly requires addr-spec IDs before opening sources. **10 tests / 59 assertions passed**, exit 0, in 0.73 seconds. Original-fingerprint reopening after same-length mutation, wrong lengths/hash, interrupted reads, source closure and individually asserted private-error exclusions also pass. Limits and measured large-file behavior remain open; the shared check slot was released.


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


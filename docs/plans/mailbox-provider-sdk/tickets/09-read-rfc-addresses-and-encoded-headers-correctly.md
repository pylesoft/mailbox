# 09: Read RFC addresses and encoded headers correctly

**What to build:** Preserve quoted/grouped addresses and international headers through the real provider mapping path.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 51, 52, 55, 92.

Replace lossy address splitting and header decoding at the real incoming-message mapping seam. Callers continue receiving package-owned values rather than parser-specific objects.

## Acceptance criteria

- [ ] Parse quoted display names containing commas, comments, groups, empty groups and multiple mailbox lists without losing or inventing addresses.
- [ ] Decode supported encoded names, subjects and header character sets while preserving usable sender and recipient data.
- [ ] Report malformed, truncated or unsupported content explicitly instead of turning it into a valid empty value.
- [ ] Use the approved inbound parser behind Mailbox's own interfaces; do not expose parser classes as the public message contract.
- [ ] Recheck maintained versions, advisories, PHP requirements, lowest/latest resolution and production-only installation before adding required dependencies. The reviewed parser candidate is ^4.0.8; plan constraints are not installation evidence.
- [ ] Keep existing DTO/resource consumer shapes compatible where safe and document parsing corrections and explicit limitations.

## Verification

- Feed realistic Gmail and Graph message/header payloads through actual SDK mapping with quoted/grouped/international fixtures and malformed negatives.
- Verify direct runtime dependency declarations and an isolated production-only install; development-only transitive packages are insufficient.
- Reuse existing mapping tests and assert consumer-visible values rather than parser internals.

## Integration notes

This slice does not choose recipient defaults or reply-all policy. Outbound Symfony Mime and explicit Egulias requirements are completed with the first submission slice.


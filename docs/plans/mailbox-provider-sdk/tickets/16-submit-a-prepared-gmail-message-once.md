# 16: Submit a prepared Gmail message once

**What to build:** Introduce the prepared-message and safe submission contracts through a real Gmail text/HTML send path.

**Blocked by:** [05: Refresh delegated grants safely across workers](05-refresh-delegated-grants-safely-across-workers.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 29, 30, 31, 32, 36, 61, 62, 63, 64, 65, 73, 76, 92.

Introduce structured prepared-message input and irreversible-submission outcomes through one complete Gmail text/HTML send. Reuse existing clients and expose provider evidence without owning an outbox.

## Acceptance criteria

- [ ] Accept package-owned explicit sender, To/Cc/Bcc, body, headers and caller correlation; keep composition, recipient defaults and authorization decisions in the host.
- [ ] Encode international names/subjects and safe headers with Symfony Mime behind the SDK interface. Reject header injection and retain intentional Bcc delivery despite serializers that remove Bcc headers; never inherit historical Bcc.
- [ ] Declare explicit Symfony Mime and Egulias runtime requirements after checking maintained versions, advisories, supported platforms, lowest/latest resolution and production-only installation. Reviewed candidates are ^7.4.22 || ^8.1.10 and ^4.0.4, subject to verification.
- [ ] Begin provider submission only after an explicit host request; editing a private draft alone causes no provider operation.
- [ ] Separate safe read retry policy from writes: issue one irreversible send attempt and return explicit uncertainty after timeout, 5xx or malformed acknowledgement that may follow acceptance.
- [ ] Never release the host's enclosing job or automatically resubmit an uncertain message. Preserve meaningful response status, headers, validated provider IDs and correlation without inventing delivery or a fully fetched message.
- [ ] Check selected grant/lifecycle before provider phases, redact access material and provide explicit unsupported capability/raw-MIME size or transformation results.
- [ ] Document accepted, rejected and uncertain outcomes, host durability responsibilities and raw-MIME limitations.

## Verification

- Submit through the real public SDK, MIME serializer and HTTP transport; inspect resulting sender, recipients, encoded body/headers, Bcc routing and request count.
- Cover success with provider evidence, empty/malformed acknowledgement, timeout, 5xx, lifecycle changes and header injection; assert one send and no surrounding-job release.
- Retain safe-read retry regressions and verify production-only dependency availability.

## Integration notes

This is the first shared prepared-message and safe-write contract. File-bearing submission is explicitly unsupported until ticket 18; no arbitrary oversized or signed/encrypted MIME rewriting is introduced.


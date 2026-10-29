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

## Comments

- 2026-10-29: Claimed by mailbox_ticket_16 for the authorized SDK implementation. Branch `feat/mailbox-sdk-16` starts clean at integration `d1aa4374246c123290de5b6e923f0f67292aa758`, with no upstream. Q13's approved public SDK, real MIME and fake external HTTP/host-port seams apply. Heavy checks and dependency installation wait for the shared resource slot; no live submission, provider setup or publication is authorized.
- 2026-10-29: Dependency preparation verified the current maintained Symfony 7.4 LTS/8.1 branches, their secure minima and source-level Bcc removal, plus Egulias 4.0.4's current release and requirements. Separate fresh Composer solves passed for runtime-only PHP 8.2.0 lowest (72 packages, Laravel 12.0.0, Mime 7.4.22), runtime-only PHP 8.4.25 latest (73 packages, Laravel 13.39.0, Mime 8.1.10), and full declared latest dependencies (148 packages). All selected Egulias 4.0.4 and Parser 4.0.8 and reported no advisories. The production-only install, installed platform check and explicit audit passed (zero advisories/abandoned packages). The main lock keeps existing versions; only its content hash changes for the two new direct runtime requirements. Existing main Mime 8.1.10/Egulias 4.0.4 were already runtime transitives. The exact 145-package main installation passed. Actual lowest runtime and production-only SDK encoder checks remain pending. Detailed receipts and retained graphs are outside the repository at `C:/Users/hello/.codex/scratch/mailbox-sdk-implementation/ticket16-dependency-receipts.md` and sibling `ticket16-{lowest,production,latest}/` directories for ticket 25 reuse.
- 2026-10-29: First public submission tracer failed for the missing prepared-message API, then passed with 1 test / 20 assertions through the real SDK, Symfony MIME and Guzzle HTTP history, using the SDK-forbidden database harness. It establishes explicit international content/recipients/Bcc, correlation and a validated Gmail acceptance acknowledgement. The ticket remains open for input validation, limits, failure/lifecycle evidence and the remaining dependency checks.


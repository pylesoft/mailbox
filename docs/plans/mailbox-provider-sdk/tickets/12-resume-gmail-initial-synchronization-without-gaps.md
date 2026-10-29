# 12: Resume Gmail initial synchronization without gaps

**What to build:** Establish the shared paged-change contract through a resumable Gmail initial scan with a safe baseline.

**Blocked by:** [03: Read through an explicit grant without package tables](03-read-through-an-explicit-grant-without-package-tables.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 26, 34, 37, 38, 39, 40, 41, 42, 46, 47.

Introduce bounded change pages through a complete Gmail initial-import path. A host can retain each page and resume without mistaking a capped or interrupted scan for completion.

## Acceptance criteria

- [ ] Capture a safe Gmail history baseline before initial listing so arrivals during the scan remain available for reconciliation.
- [ ] Honor the host's explicit mailbox/scope, import cutoff and work budgets without imposing retention policy.
- [ ] Return change work, unfinished continuation and completed synchronization checkpoint as distinct outcomes; only a finished round can produce completion.
- [ ] Bind continuation/checkpoint state to provider, selected grant identity/generation, target mailbox, scope/import policy and format version; reject cross-target or unsupported reuse.
- [ ] Report page ceilings, repeated/missing tokens, response limits and interrupted work honestly. Empty pages and duplicates cannot manufacture completion.
- [ ] Allow the host to retain page work durably before promoting its continuation. Replay after interruption must not skip uncommitted work.
- [ ] Expose upserts and removal/reset semantics through optional capabilities without breaking legacy eager collection methods.
- [ ] Document the first shared bounded-progress contract, baseline ordering and host checkpoint responsibilities.

## Verification

- Reproduce the current page-ceiling false completion and concurrent-arrival/baseline-after-scan risks using real Gmail query and transport paths.
- Exercise multi-page imports, empty/duplicate pages, interrupted retention/resume, cyclic or missing tokens, budgets and cross-grant/mailbox/scope checkpoint rejection.

## Integration notes

This slice establishes the shared paging contract. Incremental history and provider-specific Graph delta behavior remain separate slices; unfinished content must remain explicit when encountered.

## Comments

- 2026-10-29: Claimed by `mailbox_ticket_12` on `feat/mailbox-sdk-12`, based on integration `d9e2b882929615f925a38427051485f9603a03e4`, with no upstream. Implementation is limited to the first shared bounded-progress contract and Gmail initial synchronization. Q13's approved SDK/real-Guzzle HTTP-fake seam applies; the SDK test base forbids database access. No provider, consumer, publication or production operations are authorized.


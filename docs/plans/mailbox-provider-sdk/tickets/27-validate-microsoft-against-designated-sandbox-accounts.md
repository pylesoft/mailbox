# 27: Validate Microsoft against designated sandbox accounts

**What to build:** Record authorized Microsoft own/personal/shared acceptance, including 10 MiB files and known provider limitations.

**Blocked by:** [25: Verify consumer upgrades and package compatibility](25-verify-consumer-upgrades-and-package-compatibility.md)

**Status:** needs-info

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 4, 5, 6, 58, 69, 70, 71, 78, 86, 87, 96.

Validate the candidate SDK against designated Microsoft organizational, personal and shared-mailbox sandboxes, explicitly resolving the known shared/delegated large-file uncertainty.

## Acceptance criteria

- [ ] Record the immutable package candidate, account/grant/target matrix, scoped permissions, designated senders/recipients and authorized live operations before execution.
- [ ] Validate work/school and Outlook.com/Hotmail delegated enrollment/refresh, preserved application access, authorized delegated shared delta-only access and identity/lifecycle isolation.
- [ ] Verify required shared live mode uses one explicit scoped application grant for actual read/send/watch. Missing push or consent must fail clearly without mixed-grant elevation.
- [ ] Exercise per-folder initial/delta reads, conversation content, removals, expiry/reset and missed-notification catch-up without deleting retained history.
- [ ] Send ordinary messages and native own/shared replies/forwards; inspect normalized recipients, content, selected files, unchanged originals and immutable submission identities.
- [ ] Validate small files, 10 MiB-per-file and 20 MiB aggregate targets through real upload sessions, including applicable shared/delegated combinations. Record the provider's documented limitation beside each result; unverified or failing combinations are not supported parity.
- [ ] Verify real subscription delivery, renewal/removal, basic notification/lifecycle validation and host reconciliation using designated public callbacks.
- [ ] Exercise controlled interrupted/uncertain preparation and delayed sent-copy inspection without automatic resend; distinguish empty-202 acceptance, unresolved evidence and actual delivery observations.
- [ ] Record each case as passed, failed or concretely blocked with redacted evidence and account-specific prerequisites. Fixture success cannot close a live gate.
- [ ] Update live claims only for proven combinations; retain unresolved required rows as blockers and require separate authorization for provider setup changes or package publication.

## Verification

- Use designated tenant/personal/shared sandboxes with permission for the exact operations and recipient targets; tie evidence to the tested package candidate.
- Inspect recipient/file/native-draft outcomes and subscription lifecycle in the real service, retaining redacted traces and controlled cleanup boundaries.
- Treat known shared/delegated large-file restrictions as an explicit acceptance decision based on results, never as a silent file-limit reduction.

## External prerequisites

- Designated Microsoft 365 tenant and Outlook.com/Hotmail accounts, shared test mailboxes, authorized test recipients and the intended delegated/application grants.
- Appropriate app registrations, consent, Exchange mailbox permissions and administrator-approved scoped application access for shared live read/send/watch.
- A reachable public callback, host subscription/client-state storage, durable receipt/reconciliation wiring and a verified shared refresh-lock store.
- Controlled files/content and authorization for the specific send, upload, subscription and recovery exercises.
- Permission for any tenant, consent, mailbox or callback setup changes; creating this ticket does not authorize them.

## Integration notes

Status remains needs-info and Resolution remains open until these prerequisites are supplied. This live gate is independent of Gmail after ticket 25. Relevant candidate changes invalidate only the affected live evidence.


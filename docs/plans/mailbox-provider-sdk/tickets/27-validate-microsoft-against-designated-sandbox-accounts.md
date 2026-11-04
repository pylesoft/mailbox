# 27: Validate Microsoft against designated sandbox accounts

**Current phase — November 4:** Ian deferred personal-account testing: personal Gmail G1 and Outlook.com/Hotmail M3 remain unqualified future coverage. Do not request or enroll those accounts, or treat their missing live evidence as a current-phase blocker. Workspace/organizational and shared-mailbox profiles, recovery, isolation and production gates remain in scope. Historical all-profile requirements below describe the earlier plan; this deferral changes neither supported contracts nor a case to passed.

**What to build:** Record authorized Microsoft own/personal/shared acceptance, including 10 MiB files and known provider limitations.

**Blocked by:** [25: Verify consumer upgrades and package compatibility](25-verify-consumer-upgrades-and-package-compatibility.md)

**Status:** needs-info

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 4, 5, 6, 58, 69, 70, 71, 78, 86, 87, 96.

Validate the candidate SDK against designated Microsoft organizational, personal and shared-mailbox sandboxes, explicitly resolving the known shared/delegated large-file uncertainty.

## Acceptance criteria

- [ ] Record the immutable package candidate, account/grant/target matrix, scoped permissions, designated senders/recipients and authorized live operations before execution.
- [ ] Validate work/school delegated enrollment/refresh, preserved application access (personal Outlook.com/Hotmail M3 is deferred), authorized delegated shared delta-only access and identity/lifecycle isolation.
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

### Completed designated M6 controls — November 3, 2026

Host `d2d1b71cd7e36f3b4365da2afaa19987f89ebe4b` with installed SDK `5ad04592180ec99c3116fc2c7232565c0ed58eda` completed provider scope denial, interrupted preparation/upload and a controlled Orders disconnect/reapproval/reconnect. The allowed folder-ID read returned 200 and the designated excluded target returned `403 ErrorAccessDenied`, without requesting message content. One lost draft-creation acknowledgement stayed held with zero sends and no replay. A fresh process resumed the same 6,000,000-byte upload, resolved its lost final acknowledgement by original-file inspection, sent once and matched a sent copy plus one recipient delivery.

Reconnect used fresh independent approval, rejected old authority and preserved retained messages/files/manifests, collaboration state and cutoff. Its watch required one explicit operator recovery after a verified empty provider lookup, so unattended repair remains unqualified. New receipts processed without new failures, but generation 4 full reconciliation was still pending. The later temporary hostname change does not establish current Microsoft callback reachability.

These completed cases supersede stale pending entries only for the designated M6 setup. See the [reconciled live ledger](../live-acceptance.md#completed-m6-scope-and-recovery-controls--november-3-2026) and the [host acceptance record](https://github.com/pylesoft/ai-clerk/blob/feat/mailbox-integration/docs/plans/mailbox-integration/tickets/18-validate-microsoft-through-designated-live-accounts.md). Private original receipts remain under `C:/Users/hello/.codex/scratch/mailbox-live-setup`; no new live action is performed by this documentation update. Other Microsoft profiles, fresh post-recovery reconciliation, production latency, stable callbacks and broader coordination/recovery cases remain open.

Status remains needs-info and Resolution remains open until these prerequisites are supplied. This live gate is independent of Gmail after ticket 25. Relevant candidate changes invalidate only the affected live evidence.

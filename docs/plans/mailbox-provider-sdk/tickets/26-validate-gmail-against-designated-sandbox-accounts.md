# 26: Validate Gmail against designated sandbox accounts

**What to build:** Record authorized personal/Workspace lifecycle, send/files, watch and recovery acceptance separately from mocks.

**Blocked by:** [25: Verify consumer upgrades and package compatibility](25-verify-consumer-upgrades-and-package-compatibility.md)

**Status:** needs-info

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 1, 2, 3, 58, 67, 68, 77, 96.

Validate the candidate SDK against designated personal Gmail and Workspace sandboxes, keeping real account evidence separate from HTTP fixtures.

## Acceptance criteria

- [ ] Record the immutable package candidate, account/authentication matrix, authorized sender/recipient identities and exact scoped live operations before execution; do not expose credentials or message content unnecessarily.
- [ ] Validate personal Gmail delegated authorization, Workspace delegated authorization and preserved Workspace service-account access, including actual refresh and identity isolation where testable.
- [ ] Exercise initial/incremental synchronization, selected-conversation content, files and missed-notification catch-up with the host's explicit scope and retention policy.
- [ ] Send designated text/HTML messages, replies, forwards, inline files and the 10 MiB-per-file/20 MiB aggregate parity cases; inspect recipient/content/thread and acceptance evidence without treating acceptance as delivery proof.
- [ ] Verify real watch registration, authenticated Pub/Sub delivery, renewal/stop and host receipt-to-reconciliation behavior using a verified public callback setup.
- [ ] Check uncertain/interrupted recovery only through controlled authorized cases, inspect existing evidence and never automatically resend a possibly accepted message.
- [ ] Record pass/fail/blocked evidence per account/mode/case, including missing consent, infrastructure or limits. Mock success cannot close a live row.
- [ ] Update live capability claims only for proven combinations and leave unresolved required rows open. Provider setup changes and package publication require separate authorization.

## Verification

- Use designated sandboxes and recipients with explicit permission for the planned live actions; tie every result to the tested package candidate and setup.
- Retain redacted request/registration/outcome evidence, document controlled cleanup of test artifacts and never delete unrelated mailbox data.

## External prerequisites

- Designated personal Gmail and Workspace accounts, plus a scoped Workspace service account/domain delegation where that mode is tested.
- OAuth client/consent and permitted test senders/recipients with authorization for the specific send, watch and recovery exercises.
- Trusted Pub/Sub topic/subscription, raw-request cryptographic verifier, a reachable public callback and host durable receipt/reconciliation wiring.
- A configured host shared lock store with separately verified cross-process semantics for delegated refresh.
- Controlled test content/files and permission for any account or registration setup changes; these are not authorized by creating this ticket.

## Integration notes

Status remains needs-info and Resolution remains open until these prerequisites are supplied. Completion of ticket 25 alone does not make this ticket available. If the candidate changes in a relevant way, rerun only the affected live cases and retain their source identity.


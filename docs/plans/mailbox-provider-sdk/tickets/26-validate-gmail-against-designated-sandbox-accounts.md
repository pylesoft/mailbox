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

On November 3, the host selected separate Workspace user OAuth (G2) and Workspace service-account delegation (G3) sandboxes. Personal Gmail (G1) remains in scope and still needs a designated account. The dedicated project and restricted read/send domain delegation are prepared; service-account key creation remains prohibited. At the initial enrollment checkpoint, the designated G2 user completed consent/enrollment, a bounded label read, an actual guarded refresh and another bounded label read through host `0f87b68` and installed SDK `d392e4c`. G3 subsequently passed primary-address verification, operator-approved enrollment, real keyless token acquisition, a bounded read of 17 labels and encrypted token-cache reuse through unchanged host runtime `c0ca67b`. That local caller had only resource-scoped `signJwt` permission until 17:00 UTC and a short-lived credential expiry of 15:39:39 UTC. Those historical enrollment checks did not import content or send mail and do not establish production identity or rotation.

SDK `d392e4c1aa68f907eeeeb4acf2a0b913ee859944` adds `GmailServiceAccountTokenExchange`. The host supplies the current `GrantContext`, authority guard and trusted short-lived IAM credential. The SDK signs fixed mailbox/scopes claims through IAM `signJwt` and exchanges the assertion at Google's OAuth endpoint. It rechecks authority around provider phases and returns an expiring credential without persisting it. Existing configured service-account access remains unchanged. Fixture coverage and green package CI do not qualify deployed runtime identity or domain delegation.

The host will run on Laravel Cloud; its production runtime identity and automatic credential rotation remain prerequisites. No static service-account key, organization-policy exception, personal production credential or unsupported delegate-OAuth assumption is used to bypass that requirement. Authorized test recipients and live send/recovery operations remain unselected.

Through installed SDK `21226a9` and host `ec357b6`, the later initial imports reached idle with zero blockers. Exact provider-ID and thread reconciliation matched G2's 110 retained messages (99 original eligible messages, nine new eligible messages and two older conversation-context messages) and G3's 45 retained messages (44 eligible messages and one older context message), with no missing messages, duplicates or thread mismatches. This qualifies the designated initial discovery and selected-conversation snapshots. Delta synchronization, missed-notification catch-up and identity isolation remain open. All G1–G3 and M1–M6 profiles remain required and open.

The SDK and host now preserve validated UTF-8 body data when Gmail retains the original charset's byte count. Two observed G3 accented-text/HTML cases were corrected and reverified against provider evidence while preserving other retained message and conversation fields. This is scoped evidence for those cases, not a claim that every imported body was independently compared byte for byte.

All 344 received-file records reached ready by 17:22:48 UTC (G2: 23; G3: 321), with no pending or failed record. Final physical reads verified all byte lengths, SHA-256 checksums and original-manifest sizes/bindings without missing streams or read errors; all 344 message/part groups are unique. The original 334 rows preserved their IDs and original manifests, with no unexpected field change against the encrypted pre-repair backup. Received inline coverage passed for one G2 file and 74 G3 files; 323 records carry Content-ID, which alone does not establish inline classification. This qualifies these received-file snapshots. Outbound inline files and the 10 MiB/20 MiB submission cases remain open. No Gmail email was sent.

One bounded empty G3 history round completed through the normal host UI at 17:16:02 UTC. With no new message in that round, general delta and missed-notification recovery gates remain open.

The Pub/Sub topic and dedicated authenticated push subscription were created, but the inherited `iam.allowedPolicyMemberDomains` restriction denied the exact Gmail publisher binding. No organization policy changed, and no Gmail watch or authenticated delivery is claimed. The project-only policy proposal still needs complete administrator policy snapshots and separate approval. The user accepted a temporary Expose callback for Gmail; existing Microsoft registrations and historical evidence remain untouched after its hostname changed. See the [live acceptance ledger](../live-acceptance.md) for the separate case matrix and remaining gates.

Status remains needs-info and Resolution remains open until these prerequisites are supplied. Completion of ticket 25 alone does not make this ticket available. If the candidate changes in a relevant way, rerun only the affected live cases and retain their source identity.

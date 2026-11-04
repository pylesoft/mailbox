# 26: Validate Gmail against designated sandbox accounts

**Current phase — November 4:** Ian deferred personal-account testing: personal Gmail G1 and Outlook.com/Hotmail M3 remain unqualified future coverage. Do not request or enroll those accounts, or treat their missing live evidence as a current-phase blocker. Workspace/organizational and shared-mailbox profiles, recovery, isolation and production gates remain in scope. Historical all-profile requirements below describe the earlier plan; this deferral changes neither supported contracts nor a case to passed.

**What to build:** Record authorized personal/Workspace lifecycle, send/files, watch and recovery acceptance separately from mocks.

**Blocked by:** [25: Verify consumer upgrades and package compatibility](25-verify-consumer-upgrades-and-package-compatibility.md)

**Status:** needs-info

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 1, 2, 3, 58, 67, 68, 77, 96.

Validate the candidate SDK against designated personal Gmail and Workspace sandboxes, keeping real account evidence separate from HTTP fixtures.

## Acceptance criteria

- [ ] Record the immutable package candidate, account/authentication matrix, authorized sender/recipient identities and exact scoped live operations before execution; do not expose credentials or message content unnecessarily.
- [ ] Validate Workspace delegated authorization and preserved Workspace service-account access (personal Gmail G1 is deferred), including actual refresh and identity isolation where testable.
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

The host will run on Laravel Cloud; its production runtime identity and automatic credential rotation remain prerequisites. No static service-account key, personal production credential or unsupported delegate-OAuth assumption is used to bypass that requirement. The separately approved project-only publisher-policy exception below does not establish production runtime identity. At this enrollment checkpoint, authorized test recipients and live send/recovery operations were still unselected; the later approved two-mailbox run is recorded below.

Through installed SDK `21226a9` and host `ec357b6`, the later initial imports reached idle with zero blockers. Exact provider-ID and thread reconciliation matched G2's 110 retained messages (99 original eligible messages, nine new eligible messages and two older conversation-context messages) and G3's 45 retained messages (44 eligible messages and one older context message), with no missing messages, duplicates or thread mismatches. This qualifies the designated initial discovery and selected-conversation snapshots. Delta synchronization, missed-notification catch-up and identity isolation remain open. All G1–G3 and M1–M6 profiles remain required and open.

The SDK and host now preserve validated UTF-8 body data when Gmail retains the original charset's byte count. Two observed G3 accented-text/HTML cases were corrected and reverified against provider evidence while preserving other retained message and conversation fields. This is scoped evidence for those cases, not a claim that every imported body was independently compared byte for byte.

All 344 received-file records reached ready by 17:22:48 UTC (G2: 23; G3: 321), with no pending or failed record. Final physical reads verified all byte lengths, SHA-256 checksums and original-manifest sizes/bindings without missing streams or read errors; all 344 message/part groups are unique. The original 334 rows preserved their IDs and original manifests, with no unexpected field change against the encrypted pre-repair backup. Received inline coverage passed for one G2 file and 74 G3 files; 323 records carry Content-ID, which alone does not establish inline classification. This qualifies these received-file snapshots. Outbound inline files and the 10 MiB/20 MiB submission cases remain open. No Gmail email was sent.

One bounded empty G3 history round completed through the normal host UI at 17:16:02 UTC. With no new message in that round, general delta and missed-notification recovery gates remain open.

At the first Pub/Sub checkpoint, the topic and dedicated authenticated push subscription existed, but inherited `iam.allowedPolicyMemberDomains` denied the exact Gmail publisher binding. No policy exception, watch or authenticated delivery existed at that checkpoint; the project-only proposal still required administrator snapshots and separate approval. The user accepted a temporary Expose callback for Gmail; existing Microsoft registrations and historical evidence remain untouched after its hostname changed.

After separate approval, the managed project policy was created at 18:27:15 UTC and the legacy project override at 18:42:42 UTC. Effective readback matched the reviewed scope, and organization policy was unchanged. A bounded retry added only the exact Gmail publisher identity and topic-scoped Publisher role at 18:46:26 UTC after cached legacy enforcement rejected the first attempt without changing the topic. Temporary Org Policy Administrator access was removed and verified at 18:56:49 UTC, preserving prior roles. The resource-scoped signing grant runs from 18:29:21 to 19:29:21 UTC; the local caller token expires at 19:12:39 UTC. No new renewable credential file or static key was created, and short Cloud Shell/Downloads copies were removed. This is temporary test access, not deployed automatic renewal.

Both normal host UI activations registered confirmed revision-1 watches, G2 at 18:49:00 UTC and G3 at 18:49:17 UTC, expiring November 10 at 18:49:01 and 18:49:18 UTC. At the final 19:06:04 UTC read-only checkpoint, both bindings were ready with inspection revision 1 and exact configuration/context/registration. Four authenticated receipts, three G2 and one G3, were processed on attempt 1 without errors. The first three were created at 19:00:38 UTC and processed at 19:01:09–10 UTC; the fourth was created at 19:04:54 UTC and processed at 19:05:25 UTC. Every receipt's binding, registration, POST method/path, trusted subscription, mailbox, single scoped history hint and delivery identity matched exactly.

Actual Google push encoded `historyId` as a JSON integer, causing HTTP 400 under the previous SDK. Installed SDK `3098e0086b2ae960a61513ea2c4006cf062f1d75` preserves large integers losslessly and normalizes integer hints to the existing string contract without weakening authentication or binding checks. The host's raw signed callback regression failed before the fix and passes afterward (2 tests / 53 assertions). The full SDK suite passes 1,789 tests / 8,851 assertions with nine opt-in wire cases skipped, PHPStan and Pint; all 12 exact-head SDK checks passed.

At that final checkpoint, both histories were idle in discovery phase with completed checkpoints, no pending page, continuation or round checkpoint, zero blockers and no reconciliation request. G2 reached revision 283, last synchronized at 19:05:31 UTC / reconciliation version 3; G3 was at revision 104, last synchronized at 19:01:13 UTC / version 1. G2 retained 118 published messages, adding eight distinct eligible post-cutoff arrivals with no added older context to its earlier 110-message snapshot; G3 retained 45. All 344 received files remained ready (23 G2 / 321 G3), and Gmail application drafts, submissions, operations and sends remained zero. The 19:06:45 UTC acceptance receipt confirms exact integer-to-string normalization for all four deliveries and empty Gmail queues. Current file counts/states were checked; the earlier physical length/checksum/manifest proof was not rerun. These observed reconciliation rounds completed; this is not a fresh complete provider inventory or general missed-notification recovery qualification. The host also corrected future Unicode header decoding and image-free Original HTML, with a separate bounded retained-header repair of 41 G2 titles; these host changes preserve SDK contracts and do not qualify general historical repair.

These historical results qualify designated watch registration and authenticated receipt processing. Later live sends and partial recovery supersede the earlier send-unperformed status below; the broader lifecycle and production cases remain open.

### Later G2/G3 submission and recovery evidence

The approved two-mailbox run on November 3, 2026 (Toronto) used host `c7c107acc520ac393a8b03e1c1ff4a5701bd983b` and installed SDK `c2b6e14351b3a737bf875355911151947cedd231`. Both user OAuth and keyless Workspace modes passed real replies, consecutive reply-all after Gmail-rewritten Message-IDs, separate-thread forwards, file-only replies and selected-original-file forwarding. Both modes delivered two exact 10 MiB PDFs in one 20 MiB send. Ten recipient files across six sends matched the sender's lengths and SHA-256, recipient manifests and immutable part bindings. Over-limit and invalid-file rejection retained valid private draft content/files.

Operation 2508 recovered its original uncertain send through SENT inspection and published once without resend. Original operation 2504 remained unconfirmed and was not replayed at that checkpoint. G3 operation 2605 received direct HTTP 200 acceptance for the maximum-size file-only reply. The normal G3 import also recovered to 59 up-to-date messages before its new sends. These are scoped lost-acknowledgement and backlog-recovery results, not every failure mode. Outgoing inline and standalone new-email composition are not exposed host features; broader ordinary HTML/inline SDK cases remain separate.

See the [live acceptance ledger](../live-acceptance.md#gmail-submission-and-recovery-follow-up--november-3-2026-toronto) for the reconciled case matrix. Private row-level evidence is retained in `C:/Users/hello/.codex/scratch/gmail-acceptance-20261103/gmail-test-matrix.md`. The later retirement/storage changes did not rerun these live cases. Required remaining cases include deliberate duplicate/concurrent sends, worker termination, absent/rejected/ambiguous SENT evidence, disposable-grant revocation/reconnect and isolation, watch renewal/stop/repair, missed or expired-history notifications, G1 and production identity/callback/pilot qualification. The temporary signer expired; no production continuity is claimed. All required profiles and the compound acceptance criteria remain open.

Status remains needs-info and Resolution remains open until these prerequisites are supplied. Completion of ticket 25 alone does not make this ticket available. If the candidate changes in a relevant way, rerun only the affected live cases and retain their source identity.

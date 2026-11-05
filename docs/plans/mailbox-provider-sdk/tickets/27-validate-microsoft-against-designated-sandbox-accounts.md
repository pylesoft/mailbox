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

### Current M1/M5/M6 qualification — November 4, 2026

Host `f8c803c` installs SDK `4afccc2`. The full SDK suite passes 1,886 tests / 9,244 assertions with nine opt-in wire cases skipped; all twelve exact-head SDK CI checks are green. These results supplement the historical evidence below without closing the broad live gate.

M1 uses Ian's approved own-user delegated C12/I9 v1 epoch 3. Native incoming/Sent cases F/G and normal reply, forward and reply-all H/J/K passed within the designated recipient scope, with received TXT/PNG bytes and unique recipient deliveries independently verified. The controlled lost-acknowledgement operation 3101 made one real Graph send with HTTP 202. Original-operation recovery at 21:23:38 UTC published once using one GET and zero additional preparation writes or sends, preserving newer private draft 3098 at version 2 and its checksum.

The later M1 file cases also passed: W / 3138 forwarded a selected-original 126-byte TXT, X / 3141 replied with two uploaded files of exactly 10,485,760 bytes each (20 MiB aggregate), and Y / 3146 replied-all with an uploaded 126-byte TXT. Each produced one independently verified delivery to `ian@pylesoft.com`, with the approved recipient scope, matching correlation and complete file-stream lengths/SHA-256 against frozen intent. Original-job inspection at 22:01:23 UTC made seven GETs and zero provider writes; all three recorded the bound sent copy and final group without changing frozen intent. Draft 3142 was saved after X published, so this case does not prove concurrent-draft preservation; operation 3101 / draft 3098 provides that separate proof. At 22:01:44 UTC, the temporary C12/I9 file capability returned to `false`; credentials, authority and all other source fields remained unchanged. These named small-file, 10 MiB-per-file and 20 MiB aggregate results qualify own-user M1 only, with outgoing inline and delegated shared M4 combinations still unqualified.

M1 automatic refresh also passed under ordinary running work. Read-only observations at 22:22:13 and 22:25:39 UTC found expiry advanced from 22:25:43 to 23:36:15 UTC and the credential revision changed, while grant identity, normalized scopes, mailbox, connection version 1 and activation epoch 3 stayed unchanged. This control forced no token request and exercised neither provider denial nor reduced scopes. Receipt: `m1-automatic-refresh-20261104.json` in the private directory below.

The 22:26–22:27 UTC lifecycle control passed for C12/I9 v1 epoch 3, binding 13 / watch 5. A controlled local `reauthorizationRequired` hint received HTTP 202 and retained receipt 2937; it was not provider-originated. Actual Graph calls renewed the exact registration with PATCH 200, inspected it with GET 200, stopped it with DELETE 204, created a replacement with POST 201 and inspected that replacement with GET 200. Final watch/binding health was `ready` at inspection revision 6. The private callback handle rotated under the same reserved origin. Import generation 2, epoch, cutoff, cursors, import/folder state, messages, drafts, operations, files and other Microsoft bindings stayed unchanged, with no email sent. This qualifies the named renewal/stop/replacement path, not missed-notification catch-up or completed initial backfill. Receipt: `m1-watch-lifecycle-20261104.json`. Grant revocation/reduced scopes and full reconciliation remain open.

The recovery exposed Graph assigning a final conversation ID to the same immutable sent artifact. The SDK now reports that final group only after complete sent-copy proof. Immutable identity, frozen context/intent, exact contents, recipients, files and correlation remain required; foreign artifacts, malformed groups and missing proof remain inconclusive. Earlier harness artifact 3081 was blocked before any Graph preparation write or send, then retired locally under guards after encrypted backup, retaining submission evidence and its private draft. It was never reset or replayed and is not provider-rejection evidence.

Orders and Invoice are shared-mailbox application authorities in M6 required live mode. Marked D/E sends and their exact 126-byte recipient files remain valid evidence; their historical M2 wire markers are unchanged. They do not qualify M2 own-mailbox application access. M2 still needs its exact own-mailbox target and independently approved application scope. M4 still needs an explicitly authorized delegated shared target, shared delegated consent and Exchange mailbox/send permissions. The SDK supports delegated shared delta-only access, but the current host binds delegated grants to the signed-in owner's mailbox and has no delegated shared Inbox flow. Whether to implement that product flow is pending Ian's decision. Neither new consent nor host support is established by the M6 evidence, and no broader application grant is used as a fallback.

At 21:32:36 UTC, an M5 SDK read-only variation reused Invoice C8/I5 v1 epoch 2 and Orders C9/I6 v3 epoch 4. Each performed three successful delta GETs covering checkpoint advancement, input-checkpoint replay and the advanced checkpoint. All results had zero upserts/removals, with zero Graph writes or host checkpoint writes; source approvals and M6 registrations remained unchanged. This qualifies empty-delta checkpoint/replay only. Nonempty missed-change catch-up and an independent host delta-only activation remain open; the host first-activation contract still requires watch readiness.

At 22:07:48 UTC, M1 generation 2 was still importing without a retained import error: fourteen folder workers complete, folder 763 at 265 pages with a continuation, 6,620 complete candidates and five queued. Its earlier folder-only GET reported 32,276 provider items, not a completed eligible-message inventory. M1 had 11,395 complete candidates and five queued overall; its coordinator summary was 467 pages / 18 imported messages / fourteen folders. Extra helper consumers were stopped. Orders C9/I6 generation 4 reached terminal `complete` at round 158, revision 70,660, with 9,902 pages / 977 imported messages / 49 folders. Fresh provider-inventory equality remains unverified for both sources; the failed-job count remained one historical row.

Host `e3bdc05` fixes the PostgreSQL database-cache unique-lock race found during backfill: dispatch now acquires unique-job locks after the retained transaction commits, avoiding a duplicate INSERT followed by `25P02` on the fallback UPDATE. Six cases failed before the fix and passed afterward; the Microsoft selection passed 671 tests / 9,620 assertions. The 22:07:01 UTC native check made two normal schedule calls and retained one matching job, with no pre-commit lock SQL and all later lock SQL at transaction depth zero. A separate guarded run completed 33 normal import jobs without a released exception. This repairs host orchestration; it changes no SDK protocol and does not establish terminal import or inventory equality.

Private receipts remain in `C:/Users/hello/.codex/scratch/mailbox-stable-setup-20261104`: `microsoft-user-lost-ack-3101.json`, `microsoft-user-3101-inspect.json`, `retired-unsent-m1-harness-3081.json`, encrypted backup `before-retire-harness-3081.enc`, `microsoft-m5-delta-audit.json`, `m1-file-recipient-audit-W-X-Y.json`, `m1-file-sent-copy-inspection.json`, `m1-file-source-status-W-X-Y.json`, `m1-file-capability-restored.json`, `microsoft-m1-final-progress.json`, `microsoft-m1-folder-count.json`, `microsoft-pg-schedule-qualification.json` and `microsoft-pg-dispatch-qualification.json`. See the [current live ledger](../live-acceptance.md#current-local-checkpoint--november-4-2026) for the profile matrix. M1 authorization reduction/revocation, complete backfill/reconciliation and representative latency remain open; M2 approval, M4's consent/permissions and host product decision, and independent host M5 activation remain unresolved. Personal M3 and production are deferred. This record performs no new provider operation or cleanup.

### Completed designated M6 controls — November 3, 2026

Host `d2d1b71cd7e36f3b4365da2afaa19987f89ebe4b` with installed SDK `5ad04592180ec99c3116fc2c7232565c0ed58eda` completed provider scope denial, interrupted preparation/upload and a controlled Orders disconnect/reapproval/reconnect. The allowed folder-ID read returned 200 and the designated excluded target returned `403 ErrorAccessDenied`, without requesting message content. One lost draft-creation acknowledgement stayed held with zero sends and no replay. A fresh process resumed the same 6,000,000-byte upload, resolved its lost final acknowledgement by original-file inspection, sent once and matched a sent copy plus one recipient delivery.

Reconnect used fresh independent approval, rejected old authority and preserved retained messages/files/manifests, collaboration state and cutoff. Its watch required one explicit operator recovery after a verified empty provider lookup, so unattended repair remains unqualified. New receipts processed without new failures, but generation 4 full reconciliation was still pending. The later temporary hostname change does not establish current Microsoft callback reachability.

These completed cases supersede stale pending entries only for the designated M6 setup. See the [reconciled live ledger](../live-acceptance.md#completed-m6-scope-and-recovery-controls--november-3-2026) and the [host acceptance record](https://github.com/pylesoft/ai-clerk/blob/feat/mailbox-integration/docs/plans/mailbox-integration/tickets/18-validate-microsoft-through-designated-live-accounts.md). Private original receipts remain under `C:/Users/hello/.codex/scratch/mailbox-live-setup`; no new live action is performed by this documentation update. Other Microsoft profiles, fresh post-recovery reconciliation, production latency, stable callbacks and broader coordination/recovery cases remain open.

Status remains needs-info and Resolution remains open until these prerequisites are supplied. This live gate is independent of Gmail after ticket 25. Relevant candidate changes invalidate only the affected live evidence.

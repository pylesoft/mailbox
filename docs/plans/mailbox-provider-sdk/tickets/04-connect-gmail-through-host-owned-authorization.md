# 04: Connect Gmail through host-owned authorization

**What to build:** Complete delegated Gmail enrollment and read mail using the host's selected grant and lifecycle checks.

**Blocked by:** [02: Keep OAuth callback returns trusted](02-keep-oauth-callback-returns-trusted.md), [03: Read through an explicit grant without package tables](03-read-through-an-explicit-grant-without-package-tables.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 1, 2, 16, 17, 18, 19.

Complete Gmail delegated enrollment through host-owned authorization and storage, ending with a real SDK mailbox read. Support personal Gmail and Google Workspace without introducing mandatory package models.

## Acceptance criteria

- [ ] Host authorization guards approve initiation and callback binding for the explicitly selected connection; arbitrary user references or enabled package routes do not establish ownership.
- [ ] OAuth state is expiring, single-use and bound to the initiating host context, provider, selected grant and lifecycle generation.
- [ ] Exchange the code and verify provider subject, issuer and OAuth client identity before persisting selected-grant credentials. Different host contexts authorizing the same account remain independent.
- [ ] Preserve an omitted refresh token only for the same selected grant and verified provider identity. A changed identity cannot inherit an earlier token; missing renewable authorization is explicit.
- [ ] Persist returned rotations through guarded writes and reject callback completion after reconnect, disconnect or state replay.
- [ ] Use the enrolled grant for an actual Gmail read, while preserving configured Workspace service-account access and safe callback/result fields.
- [ ] Document required consent, callback binding, host storage and the actual implemented runtime capability without claiming refresh until ticket 05 is complete.

## Verification

- Exercise initiation, callback exchange, storage and a subsequent public SDK read with real OAuth/provider HTTP faked.
- Cover personal/Workspace identities, two connections for one external subject, expired/replayed/wrong-context state, host denial, omission/rotation, identity change and lifecycle changes during exchange.

## Integration notes

Reuse the trusted return policy from ticket 02 and the explicit runtime from ticket 03. Provider setup and live consent are not performed by this ticket.


# 05: Refresh delegated grants safely across workers

**What to build:** Refresh before Gmail access under shared per-grant coordination, preserving identity and rejecting stale writes.

**Blocked by:** [04: Connect Gmail through host-owned authorization](04-connect-gmail-through-host-owned-authorization.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 18, 19, 20, 21, 22, 23, 24.

Make an expired Gmail delegated grant usable safely when several workers or mailbox targets request it simultaneously. Establish the shared refresh contract that Microsoft and optional persistence will reuse.

## Acceptance criteria

- [ ] Coordinate delegated refresh using one shared atomic lock per grant across processes and mailbox targets, with the host's configured shared cache-lock store.
- [ ] Failed, unavailable or contended coordination returns a retryable failure and issues no refresh exchange. A process-local array store or mailbox-only lock cannot satisfy deployment requirements.
- [ ] Reload credentials under the lock and conditionally commit only when selected identity, expected refresh revision and reconnect/disconnect generation still match.
- [ ] Preserve an omitted refresh token only for the same verified grant, subject, issuer/tenant and OAuth client; store rotations atomically and reject identity changes without renewable credentials.
- [ ] Ordinary refresh preserves the connection generation. Reconnect/disconnect invalidates cached authority and rejects stale refresh commits and subsequent provider phases.
- [ ] Distinguish transient refresh failure from revoked, invalid or insufficient delegated authorization, without implicit fallback to configured application authority.
- [ ] Return control and retry information to the host without releasing its enclosing job, leaking secrets or introducing a package-owned refresh workflow.
- [ ] Document shared-store prerequisites and retain existing configured authentication modes.

## Verification

- Use controlled real HTTP exchanges and state changes to prove lock/reload/refresh/read behavior, a single exchange for competing targets, rotation and stale-write rejection.
- Test unavailable/failed/contended locks with zero exchanges, same-identity omission and negative subject/tenant/client changes.
- Keep deterministic package adapter tests separate from verification that a deployed host actually provides a shared cross-process lock store.


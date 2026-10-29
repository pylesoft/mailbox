# 06: Connect and refresh Microsoft mailboxes

**What to build:** Use Microsoft work/school, personal and supported shared delegated access with explicit identities and renewable grants.

**Blocked by:** [05: Refresh delegated grants safely across workers](05-refresh-delegated-grants-safely-across-workers.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 4, 5, 6, 16, 17, 18, 19, 20, 21, 22, 23, 24.

Add Microsoft delegated enrollment and refresh using the established host-owned grant lifecycle. Make supported own-mailbox and delegated shared-mailbox reads work without replacing existing application-permission integrations.

## Acceptance criteria

- [ ] Support work/school Microsoft 365 and personal Outlook.com/Hotmail enrollment under an appropriate explicit app registration and verified issuer, tenant, subject and OAuth client identity.
- [ ] Invoke host guards and single-use, expiring, context-bound state on initiation and callback; preserve safe callback fields and the trusted return policy.
- [ ] Use the selected delegated grant for actual reads and refresh through the shared lock, reload and conditional replacement contract.
- [ ] Apply same-identity omission preservation, token rotation, transient-versus-reconnect failures, cache isolation and lifecycle invalidation consistently with Gmail.
- [ ] Permit a distinct authorized shared-mailbox target when the selected delegated grant and actual mailbox permissions support it; do not infer shared access from token acquisition.
- [ ] Preserve configured application-permission access. Rejected delegated authority never selects that broader grant automatically.
- [ ] Expose actual capability/setup results, including delegated shared delta-only access; do not claim required shared live mode before its dedicated capability slice.
- [ ] Document account-specific scope, consent, registration and permission prerequisites.

## Verification

- Exercise enrollment, expired-token refresh and reads through the public SDK with real Microsoft HTTP faked for organizational, personal and authorized shared targets.
- Cover wrong tenant/client/subject, same-identity omission, reconnect during exchange, no fallback, independent grants and existing application authentication.

## Integration notes

Reuse the provider-neutral enrollment and refresh seams introduced by tickets 04 and 05 rather than creating a second manager hierarchy.

## Comments

- 2026-10-29: Claimed by the ticket 06 implementer on `feat/mailbox-sdk-06`, based on integration `00beee6f4eff5a0ab01b9399d946b1a63e5164ef`; ancestry confirmed and no upstream configured. Approved Q13 seams are the public SDK/provider APIs with HTTP fakes and host enrollment/state/store/lock ports. Waiting for the global verification slot before dependency setup or checks.


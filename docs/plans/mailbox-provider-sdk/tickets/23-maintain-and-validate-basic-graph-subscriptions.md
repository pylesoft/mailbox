# 23: Maintain and validate basic Graph subscriptions

**What to build:** Create/renew/remove basic subscriptions and validate handshake, identity and lifecycle events.

**Blocked by:** [06: Connect and refresh Microsoft mailboxes](06-connect-and-refresh-microsoft-mailboxes.md), [14: Resume Graph folder synchronization](14-resume-graph-folder-synchronization.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 78, 81, 82, 83, 84, 85, 88.

Create and maintain basic Graph subscriptions, validate callbacks and lifecycle signals, and return scoped reconciliation hints without owning host callback orchestration.

## Acceptance criteria

- [ ] Expose subscription creation, renewal and removal with selected grant, target resource, provider identity and expiry; support host-managed registration maintenance.
- [ ] Request basic notifications with includeResourceData=false and explicitly reject rich encrypted mode, certificate management or decryption requests.
- [ ] Handle validation handshakes according to the provider contract and validate secret client state, subscription, tenant/resource and lifecycle binding before emitting usable signals.
- [ ] Accept legitimate basic notifications that carry resourceData identity metadata; its presence alone does not mean rich mode.
- [ ] Reject unknown/mismatched resources, stale generations and invalid secrets without exposing secrets in public results or logs.
- [ ] Surface missed, removed and reauthorization lifecycle signals. Duplicates/out-of-order notifications only request reconciliation from committed per-folder progress.
- [ ] Keep endpoint authorization/configuration, durable receipt acknowledgement, scheduling and dispatch in the host; report actual capability/setup limitations.
- [ ] Document subscription prerequisites, renewal, basic-mode scope and recovery scans.

## Verification

- Exercise real subscription create/renew/delete HTTP plus raw handshake and notification/lifecycle validation paths.
- Cover legitimate basic identity metadata, rich-mode rejection, bad client state, wrong tenant/resource/subscription, batched/malformed events, expiry, duplicates and stale generations.
- Assert no direct provider-data publication, checkpoint advancement or package-owned callback queue.

## Comments

- 2026-10-29: Claimed by the ticket 23 implementer on `feat/mailbox-sdk-23`, created clean without an upstream from integration `472743cf90f7855a9a495b3b8fecd6c7933e1463`. Scope is optional basic Graph subscription maintenance and raw callback validation through the approved public SDK → real provider/client seam with HTTP fakes and SDK database access forbidden. Public endpoints, receipts, scheduling, dispatch, live setup and provider calls remain host responsibilities. Heavy checks and dependency bootstrap wait for the root's serialized check slot.


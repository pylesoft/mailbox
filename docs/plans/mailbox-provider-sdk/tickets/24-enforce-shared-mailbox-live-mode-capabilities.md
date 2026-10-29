# 24: Enforce shared-mailbox live-mode capabilities

**What to build:** Use one explicit application grant for shared live read/send/watch and report missing setup without elevation.

**Blocked by:** [19: Prepare and submit a Graph draft safely](19-prepare-and-submit-a-graph-draft-safely.md), [23: Maintain and validate basic Graph subscriptions](23-maintain-and-validate-basic-graph-subscriptions.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 6, 12, 24, 86, 87, 88.

Make required live Microsoft shared-mailbox operation an explicit capability contract. A valid setup uses one selected application-permission grant for read, send and watch.

## Acceptance criteria

- [ ] Validate supported push setup, tenant consent, scoped mailbox access and required read/send/watch authority for the explicitly selected application grant and shared mailbox.
- [ ] Demonstrate shared-mailbox reading, body-only draft submission and subscription registration through the same grant/target context.
- [ ] Return a clear setup/capability error when requested live mode lacks a supported subscription or required permission; token acquisition alone is insufficient proof.
- [ ] Never combine a user's delegated authority with a broader application grant or fall back after delegated rejection.
- [ ] Keep authorized delegated/application delta-only shared-mailbox usage available when live mode is not requested, reporting its actual reduced discovery capability.
- [ ] Retain catch-up reconciliation in live mode because push delivery can be missed; server discovery capability does not dictate host browser-update implementation.
- [ ] Preserve lifecycle invalidation, secret handling and host ownership of durable orchestration, and document the complete account/authentication capability matrix.
- [ ] Keep shared native reply/forward and large-file parity claims gated by combined and live validation rather than inferring support from basic text operations.

## Verification

- Use real SDK paths with controlled Graph HTTP to verify one selected application grant across shared read, preparation/send and subscription registration.
- Test missing consent, insufficient scope/mailbox access, unsupported push, delegated live requests and delta-only success; assert no implicit authority elevation.

## Integration notes

This slice enforces setup and provider behavior. It does not grant administrator consent, configure a tenant, provision callbacks or implement AI Clerk browser events.


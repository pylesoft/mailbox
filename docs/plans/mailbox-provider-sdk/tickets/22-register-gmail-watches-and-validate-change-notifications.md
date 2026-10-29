# 22: Register Gmail watches and validate change notifications

**What to build:** Register/stop watches, require trusted Pub/Sub verification and return safe reconciliation signals.

**Blocked by:** [05: Refresh delegated grants safely across workers](05-refresh-delegated-grants-safely-across-workers.md), [13: Reconcile Gmail history and expired checkpoints](13-reconcile-gmail-history-and-expired-checkpoints.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 77, 79, 80, 83, 84, 85, 88.

Expose Gmail watch/stop operations and turn authenticated Pub/Sub requests into scoped reconciliation hints. The host retains endpoint configuration, durable receipts and dispatch.

## Acceptance criteria

- [ ] Register and stop watches for the explicit grant/mailbox and return provider identity, expiry and lifecycle information suitable for host-managed renewal.
- [ ] Require a configured trusted host verifier on the raw request for signature, issuer, expiry, audience and service-account identity; absent or rejected verification fails closed.
- [ ] Validate returned verified claims, trusted registration/subscription mapping and Gmail account binding before emitting a usable change signal.
- [ ] Derive topic binding from trusted registration/configuration rather than assuming every notification carries a trustworthy topic field. An authenticated boolean or unverified decoded JWT is insufficient.
- [ ] Treat duplicate/out-of-order notifications as hints to reconcile from the last committed checkpoint; notification history IDs never promote checkpoints or directly publish provider content.
- [ ] Surface expiry, missed delivery, stopped/removed registrations, reconnect and stale-generation conditions for host renewal/catch-up.
- [ ] Leave receipt persistence, acknowledgement, endpoint dispatch and scheduling in the host; redact secrets and report missing capability/setup clearly.
- [ ] Document Pub/Sub prerequisites, verifier responsibilities and why catch-up reconciliation remains necessary in live mode.

## Verification

- Exercise real watch/stop HTTP requests and notification validation with a host-verifier test double only at the approved trust seam.
- Cover missing/rejected verifier, wrong audience/principal/account/subscription, forged decoded claims, malformed envelopes, duplicates, out-of-order hints and stale generations.
- Assert verifier invocation receives the raw request, no callback advances committed history and no SDK-owned durable receipt/job is created.

## Integration notes

Cryptographic-verifier correctness and actual Pub/Sub delivery require designated host/live integration evidence; protocol fixtures alone do not prove either.


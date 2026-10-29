# 22: Register Gmail watches and validate change notifications

**What to build:** Register/stop watches, require trusted Pub/Sub verification and return safe reconciliation signals.

**Blocked by:** [05: Refresh delegated grants safely across workers](05-refresh-delegated-grants-safely-across-workers.md), [13: Reconcile Gmail history and expired checkpoints](13-reconcile-gmail-history-and-expired-checkpoints.md)

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 77, 79, 80, 83, 84, 85, 88.

Expose Gmail watch/stop operations and turn authenticated Pub/Sub requests into scoped reconciliation hints. The host retains endpoint configuration, durable receipts and dispatch.

## Acceptance criteria

- [x] Register and stop watches for the explicit grant/mailbox and return provider identity, expiry and lifecycle information suitable for host-managed renewal.
- [x] Require a configured trusted host verifier on the raw request for signature, issuer, expiry, audience and service-account identity; absent or rejected verification fails closed.
- [x] Validate returned verified claims, trusted registration/subscription mapping and Gmail account binding before emitting a usable change signal.
- [x] Derive topic binding from trusted registration/configuration rather than assuming every notification carries a trustworthy topic field. An authenticated boolean or unverified decoded JWT is insufficient.
- [x] Treat duplicate/out-of-order notifications as hints to reconcile from the last committed checkpoint; notification history IDs never promote checkpoints or directly publish provider content.
- [x] Surface expiry, missed delivery, stopped/removed registrations, reconnect and stale-generation conditions for host renewal/catch-up.
- [x] Leave receipt persistence, acknowledgement, endpoint dispatch and scheduling in the host; redact secrets and report missing capability/setup clearly.
- [x] Document Pub/Sub prerequisites, verifier responsibilities and why catch-up reconciliation remains necessary in live mode.

## Verification

- Exercise real watch/stop HTTP requests and notification validation with a host-verifier test double only at the approved trust seam.
- Cover missing/rejected verifier, wrong audience/principal/account/subscription, forged decoded claims, malformed envelopes, duplicates, out-of-order hints and stale generations.
- Assert verifier invocation receives the raw request, no callback advances committed history and no SDK-owned durable receipt/job is created.

## Integration notes

Cryptographic-verifier correctness and actual Pub/Sub delivery require designated host/live integration evidence; protocol fixtures alone do not prove either.

## Comments

- 2026-10-29: Claimed by the ticket 22 implementer on `feat/mailbox-sdk-22`, based on integration `0dca168e0c6af8c3869ebd2a5803bad5cf28da05`. Implementing only the optional Gmail watch/stop and raw-request verified notification capability at the specification's approved public SDK/HTTP/host-verifier seam. Checks wait for the shared resource slot; no live setup or delivery is authorized.
- 2026-10-29: Completed at source `34179ed7960dfe931013a3d7c059d446cef156e1`, including integration `472743cf90f7855a9a495b3b8fecd6c7933e1463`. Optional `SupportsGmailWatch` exposes watch, stop, raw-request notification validation and a host-invoked catch-up hint. Registration/configuration retain the trusted account/subscription/topic and service-account binding. The existing `GrantAccessGuard` checks before and after provider I/O/verification reject superseded generations; neither notification nor recovery hint contains a synchronization checkpoint. Missed delivery and subscription removal are host-supplied knowledge, not inferred Gmail lifecycle callbacks. Existing Gmail constructors remain compatible through an optional trailing guard, passed by name from the explicit-grant driver.
- Verification: public watch, raw-verifier, stop and recovery tracers each failed before implementation and passed afterward. Wrong verified identity/audience/issuer/times, absent/rejected/boolean verification, malformed/oversized/unreadable envelopes, account/subscription mismatches, duplicates/order, uncertain provider operations and privacy failures are covered. A reproduced resource debug-dump credential leak from the newly retained host guard was fixed. A combined public watch/conversation/notification test proves the ticket 15 merge preserves both capabilities and rejects stale work.
- Final checks on that source: `php vendor/bin/pest --configuration=<external selection XML> --parallel --processes=2 --compact` passed with exit 0: **164 tests, 618 assertions**, 4.71 seconds. The external XML selects `GmailWatchTest`, `ExplicitGrantReadTest`, `GmailInitialSyncTest`, `GmailIncrementalSyncTest`, `DriverResolutionTest` and `GmailMailboxResourceDirectTest`, retaining the original bootstrap/environment/failure policies. The broad `--filter` parallel runner printed passing results but exited 1; it is not counted as a passed gate. SDK cases forbid database access; feature cases use isolated Testbench SQLite memory/worker files with `RefreshDatabase` and worker cleanup.
- Changed-file PHPStan (`--debug --no-progress`, single process), Pint (`--dirty --format agent`), `composer validate --strict`, `composer check-platform-reqs` and `git diff --check` passed. Dependency bootstrap used `composer install --no-scripts --no-interaction --prefer-dist`; Composer files are unchanged. No full suite, deployed cryptographic verifier, real Pub/Sub registration/delivery/renewal, provider setup or external mutation was performed. Host/live acceptance remains open in the designated live tickets; these protocol fixtures do not close it. See [Gmail watch guidance](../../../authentication/gmail.md#gmail-watches-and-authenticated-push).


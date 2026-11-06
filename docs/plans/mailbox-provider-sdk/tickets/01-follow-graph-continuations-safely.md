# 01: Follow Graph continuations safely

**What to build:** Follow all returned pages with intact opaque queries and trusted authenticated destinations.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 25, 26, 27, 30, 36.

Repair continuation requests through the existing public read path before expanding provider capabilities. Keep opaque provider tokens intact while restricting where mailbox credentials can be sent.

## Acceptance criteria

- [x] Reproduce Graph continuation query loss through the real Guzzle request path, then follow next and delta links without replacing, decoding or rebuilding their opaque query values.
- [x] Resolve only trusted provider origins and resources for the selected mailbox context. Reject foreign origins, schemes, resource targets and redirect destinations before forwarding authorization.
- [x] Preserve useful retry behavior for replay-safe reads and existing configured-provider request behavior.
- [x] Retain explicit malformed, missing or cyclic continuation failures rather than inventing a completed result.
- [x] Redact bearer tokens and secret continuation/recovery values from public errors, debug representations and logs.
- [x] Update continuation and authenticated-request documentation with the corrected behavior and trust boundary.

## Verification

- Use Guzzle MockHandler and request history behind public provider reads; assert the exact first and continued request URI and authorization destination.
- Cover encoded/repeated query values, absolute and relative provider links, foreign origins, redirects, cyclic links and retryable reads. Mocking a high-level client method is insufficient.

## Integration notes

Keep this repair in the shared transport seam. The bounded synchronization tickets will add the complete checkpoint format and scope contract.

## Comments

- 2026-10-29: Claimed locally by the ticket 01 implementation agent on `feat/mailbox-sdk-01`, starting from approved integration commit `b899dab`. Verification uses public provider reads with real Guzzle transport, MockHandler and request history. Resolution remains open until the acceptance criteria and affected checks pass.
- 2026-10-29: Closed after implementation commits `a58b3b9` and `bbc7319`, followed by merging integration tip `7d673704` in `8645256`. The initial real-Guzzle regression failed with both continued queries missing, then passed with exact encoded/repeated queries retained. Further red-before-green cases covered untrusted destinations, redirects, relative paths, malformed/missing progress, cycles, encoded traversal, missing collections, secret diagnostics and Gmail mailbox targeting. Existing configured-mode/token-manager signatures remain compatible.
- Verification after the integration merge, on PHP 8.4.25 and locked Guzzle 7.10.0: `php vendor/bin/pest tests/Feature/MsGraph --parallel --processes=4 --compact` passed 71 tests / 180 assertions; `php vendor/bin/pest tests/Feature/Gmail/AuthenticatedTransportTest.php --compact` passed 5 / 15; `php vendor/bin/pest tests/Feature/Gmail/GmailClientTest.php --compact` passed 4 / 6; `php vendor/bin/pest tests/Feature/Infrastructure/PerformsApiRequestsTest.php --compact` passed 10 / 33; `php vendor/bin/pest tests/Feature/DriverResolutionTest.php --parallel --processes=4 --compact` passed 3 / 4. The Gmail signing test used the process-local `OPENSSL_CONF=C:/Program Files/Git/usr/ssl/openssl.cnf`. Testbench targets isolated SQLite memory or worker files with `RefreshDatabase`; no active application database was used.
- PHPStan passed for the ten changed source files; Pint dirty formatting, `git diff --check`, `composer validate --strict` and `composer check-platform-reqs` passed. No dependency manifests or lockfiles changed. The branch has no upstream configured.
- Limits: this closes the existing read/transport repair only. Grant-bound versioned checkpoint formats and bounded page interfaces remain with their later tickets. Native debug dumps redact checkpoints; their public property and array/JSON serialization intentionally retain private persistence state. Authenticated redirects are refused. No live-provider, actual consumer, dependency-resolution/advisory or release-readiness acceptance is claimed; no push, PR, provider setup or production operation occurred.

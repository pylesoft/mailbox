# 02: Keep OAuth callback returns trusted

**What to build:** Return both successful and failed OAuth attempts only to permitted application destinations.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 16, 17.

Close the existing OAuth return-destination bypass for both providers while preserving safe callback results. This slice handles redirects independently of new delegated-runtime enrollment.

## Acceptance criteria

- [x] Reproduce the existing leading-slash bypass before correcting success and error return paths for Gmail and Microsoft OAuth.
- [x] Allow valid local application paths and explicitly trusted absolute return URLs under the configured policy.
- [x] Reject scheme-relative URLs, slash/backslash combinations, control characters and browser-normalized destinations that can escape the trusted application origin.
- [x] Apply the same policy to every success, cancellation and provider-error branch; do not trust a return value simply because it survived OAuth state transport.
- [x] Preserve safe existing callback fields and document the intentional security exception for previously accepted unsafe destinations.
- [x] Do not broaden allowed hosts or change provider enrollment ownership as a side effect of the redirect repair.

## Verification

- Exercise actual initiation/callback behavior for both providers with local paths, configured trusted absolute URLs, untrusted absolute URLs, double slashes, slash/backslash combinations and control-character variants.
- Assert the final redirect destination on both successful and failed provider exchange, rather than testing only a URL helper.

## Comments

- 2026-10-29: Claimed by the ticket 02 implementation agent on `feat/mailbox-sdk-02` at the approved `b899dab` integration baseline. Scope is the existing Gmail/Microsoft OAuth return policy and callback compatibility only. The approved Q13 seam uses real initiation/callback services/controllers, external-provider HTTP fakes and isolated SQLite persistence; no new grant runtime or live-provider actions are included.
- 2026-10-29: Closed after verification. Both existing services now share their private return normalization and unchanged host-allowlist policy. Scheme-relative URLs, backslashes and ASCII control characters are rejected during initiation and callback-state recovery, including configured defaults. Existing controllers, token enrollment/ownership writes and public result shapes are unchanged. The intentional rejection of previously accepted unsafe destinations is documented in `docs/authentication/user-oauth.md`.
- Red/green evidence: the original 8 OAuth feature tests passed (64 assertions). Before the fix, `php vendor/bin/pest tests/Feature/OAuth/TrustedOAuthReturnsTest.php --compact` reproduced 8 external-return failures. After the initial scheme-relative fix, the expanded command `php vendor/bin/pest tests/Feature/OAuth/TrustedOAuthReturnsTest.php --parallel --processes=4 --compact` reproduced 56 backslash/control failures and 72 passes; after the complete policy fix, all 128 cases passed (640 assertions). Additional policy-revocation/invalid-default checks passed for all 8 provider/outcome combinations and retained JSON success/error statuses and fields.
- Final checks: `php vendor/bin/pest --filter=OAuth --parallel --processes=4 --compact` passed 155 tests (983 assertions), covering both providers' real initiation/callback flows, safe/unsafe destinations, all success/cancellation/exchange-failure/missing-code paths, existing persistence/ownership behavior and legacy controller contracts. `php vendor/bin/pest tests/Arch --parallel --processes=4 --compact` passed 13 tests (251 assertions). Targeted PHPStan level 8 passed for both services and `NormalizesReturnTo.php`; Pint dirty plus explicit new-file formatting and `git diff --check` passed. Tests used `RefreshDatabase` with isolated SQLite memory/worker files, never an active application database.
- Dependencies were installed from the existing lock with `composer install --no-interaction --prefer-dist --no-progress`; Composer manifests/lock are unchanged. Integration tip `6f0920474a8b009c508322b5e0059ef4a0d212ed` was merged before completion (planning text only; no conflicts or source changes). Full integration verification remains with the integration branch. No live-provider validation, push, PR, provider configuration or active-database action was performed.


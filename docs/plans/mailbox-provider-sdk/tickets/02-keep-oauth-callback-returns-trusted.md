# 02: Keep OAuth callback returns trusted

**What to build:** Return both successful and failed OAuth attempts only to permitted application destinations.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 16, 17.

Close the existing OAuth return-destination bypass for both providers while preserving safe callback results. This slice handles redirects independently of new delegated-runtime enrollment.

## Acceptance criteria

- [ ] Reproduce the existing leading-slash bypass before correcting success and error return paths for Gmail and Microsoft OAuth.
- [ ] Allow valid local application paths and explicitly trusted absolute return URLs under the configured policy.
- [ ] Reject scheme-relative URLs, slash/backslash combinations, control characters and browser-normalized destinations that can escape the trusted application origin.
- [ ] Apply the same policy to every success, cancellation and provider-error branch; do not trust a return value simply because it survived OAuth state transport.
- [ ] Preserve safe existing callback fields and document the intentional security exception for previously accepted unsafe destinations.
- [ ] Do not broaden allowed hosts or change provider enrollment ownership as a side effect of the redirect repair.

## Verification

- Exercise actual initiation/callback behavior for both providers with local paths, configured trusted absolute URLs, untrusted absolute URLs, double slashes, slash/backslash combinations and control-character variants.
- Assert the final redirect destination on both successful and failed provider exchange, rather than testing only a URL helper.


# 06: Connect and refresh Microsoft mailboxes

**What to build:** Use Microsoft work/school, personal and supported shared delegated access with explicit identities and renewable grants.

**Blocked by:** [05: Refresh delegated grants safely across workers](05-refresh-delegated-grants-safely-across-workers.md)

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 4, 5, 6, 16, 17, 18, 19, 20, 21, 22, 23, 24.

Add Microsoft delegated enrollment and refresh using the established host-owned grant lifecycle. Make supported own-mailbox and delegated shared-mailbox reads work without replacing existing application-permission integrations.

## Acceptance criteria

- [x] Support work/school Microsoft 365 and personal Outlook.com/Hotmail enrollment under an appropriate explicit app registration and verified issuer, tenant, subject and OAuth client identity.
- [x] Invoke host guards and single-use, expiring, context-bound state on initiation and callback; preserve safe callback fields and the trusted return policy.
- [x] Use the selected delegated grant for actual reads and refresh through the shared lock, reload and conditional replacement contract.
- [x] Apply same-identity omission preservation, token rotation, transient-versus-reconnect failures, cache isolation and lifecycle invalidation consistently with Gmail.
- [x] Permit a distinct authorized shared-mailbox target when the selected delegated grant and actual mailbox permissions support it; do not infer shared access from token acquisition.
- [x] Preserve configured application-permission access. Rejected delegated authority never selects that broader grant automatically.
- [x] Expose actual capability/setup results, including delegated shared delta-only access; do not claim required shared live mode before its dedicated capability slice.
- [x] Document account-specific scope, consent, registration and permission prerequisites.

## Verification

- Exercise enrollment, expired-token refresh and reads through the public SDK with real Microsoft HTTP faked for organizational, personal and authorized shared targets.
- Cover wrong tenant/client/subject, same-identity omission, reconnect during exchange, no fallback, independent grants and existing application authentication.

## Integration notes

Reuse the provider-neutral enrollment and refresh seams introduced by tickets 04 and 05 rather than creating a second manager hierarchy.

## Comments

- 2026-10-29: Claimed by the ticket 06 implementer on `feat/mailbox-sdk-06`, based on integration `00beee6f4eff5a0ab01b9399d946b1a63e5164ef`; ancestry confirmed and no upstream configured. Approved Q13 seams are the public SDK/provider APIs with HTTP fakes and host enrollment/state/store/lock ports. Waiting for the global verification slot before dependency setup or checks.

- 2026-10-29: Implemented in `b12a1e8` and documented in `9cc8463`. Merged integration `72a728a1f2c143333c3b50d411ad783830352fec` (including tickets 10 and 13) as `abf020158d37547f61155d3ef89eb92c97605811` before the final gates. `MsGraphGrantOAuthService(guard, states, grants)` exposes the same `authorizationUrl(context, hostContext, returnTo, userReference)` and `handleCallback(context, hostContext, state, code)` shape as host-owned Gmail enrollment. `MsGraphGrantRefresher` implements the existing `GrantRefresher`; callers use the unchanged `RefreshingGrantTokenProvider` and `Mailbox::forGrant()` path. No second manager, mandatory package tables, dependency change or global runtime configuration mutation was added.

- 2026-10-29: Authority accepts only `common`, `organizations`, `consumers` or a tenant GUID. The trailing optional `OAuthState::authority` field binds and rechecks that choice through callback; ticket 07's state adapter must preserve it with the private nonce and PKCE verifier. Enrollment validates the direct HTTPS token response's v2 issuer, tenant, subject, audience/optional authorized party, time bounds and nonce. Graph `/me.id` must match `oid`, while `GrantIdentity::subject` retains the distinct pairwise OIDC `sub`. Grant storage must preserve non-secret `metadata['object_id']` and the private original `nonceHash`. No Graph access token is parsed. Fixed token/profile endpoints verify TLS and disable redirects. The default Microsoft OAuth scopes now include `User.Read`; explicit registration, supported account types, callback URI, mail scopes and consent prerequisites are documented in the existing OAuth and upgrade guides.

- 2026-10-29: Callback omission preserves a refresh secret and original nonce fingerprint only for the same selected verified provider/issuer/tenant/subject/client/object identity. Refresh uses that verified tenant's endpoint under the shared lock, authoritative reload and host conditional replacement; it preserves omissions, stores rotations, advances the revision and keeps the lifecycle generation. Returned refresh identity and optional nonce must match the stored evidence. Typed setup/refresh/access-denied errors distinguish temporary coordination/transport/429/provider failures from setup and reconnect requirements, without raw response bodies or secret-bearing causes. Scope metadata and successful enrollment/profile checks do not prove a target permission; an actual selected-target read can succeed or fail. Delegated shared access remains delta-only, and ticket 24 owns required live shared application-grant setup. Rejected delegated 401/403 reads never use an available configured application token; configured application authentication remains usable independently.

- 2026-10-29: TDD evidence: the first public organizational/personal enrollment-to-Graph tracer failed twice for the missing service, then passed 2 scenarios / 32 assertions. The expired-grant refresh-to-Graph tracer failed three times for the missing refresher, then passed 3 scenarios / 33 assertions for organizational, personal and distinct shared targets. The safety batch reproduced malformed nested scope configuration raising `ErrorException` and explicit null `azp` acceptance in enrollment and refresh; minimal validation fixes produced 99 passing Microsoft scenarios / 402 assertions. These scenarios also cover state expiry/replay/context/client/authority binding, identity/profile mismatch, same-identity omission/rotation, nonce evidence, independent grants, cross-target coordination, reconnect/CAS conflicts, safe returns, redacted failures and no application fallback.

- 2026-10-29: Final merged-source checks on `abf020158d37547f61155d3ef89eb92c97605811`: `php vendor/bin/pest tests --filter='MsGraphGrant|GmailGrantEnrollment|GrantRefreshTest|ExplicitGrantReadTest|MsGraphOAuthFlowTest|TrustedOAuthReturnsTest|TokenManagerTest|DriverResolutionTest|ArchitectureTest|StrictTypesAndPhpStanTest|OAuthControllerTest' --parallel --processes=2 --compact` passed 398 tests / 2142 assertions in 74.66 seconds. This covers the new Microsoft paths, Gmail enrollment/shared refresh/explicit grants, legacy OAuth/token managers, trusted returns, driver resolution and architecture. SDK tests disable package migration loading and forbid database access; other affected tests use isolated Testbench SQLite memory/worker files with their existing `RefreshDatabase` cleanup. `OPENSSL_CONF` was process-local for RSA fixtures. Full source `php vendor/bin/phpstan analyse --debug --no-progress`, Pint agent formatting/checks for the seven changed PHP paths, `composer validate --strict`, `composer check-platform-reqs` and `git diff --check` passed. Installed PHP 8.4.25, Laravel 13.39.0 and Guzzle 7.16.1 were checked; dependencies were installed from the unchanged lock.

- 2026-10-29: Package acceptance is complete. The controlled HTTP and deterministic host/lock doubles do not establish live account consent, actual Exchange shared permissions, a deployed cross-process shared-lock topology, subscription setup or supported live parity. Those remain the designated live/capability gates, including tickets 24 and 27. No full-suite rerun, Workbench build, active consumer/database access, live provider operation, publication, push or deployment was performed.


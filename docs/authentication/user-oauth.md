# User OAuth (Browser Sign-In)

Your application may connect personal Gmail, Google Workspace, Microsoft 365 and personal Microsoft mailboxes through explicit delegated authorization. The host owns its enrollment endpoints, selected connection, authorization checks and storage. Mailbox handles the provider exchange, verified identity, guarded credential replacement and delegated runtime.

If you use configured [Microsoft application permissions](ms-graph.md) or [Gmail service-account delegation](gmail.md), continue using those modes. They remain separate from delegated OAuth and do not select stored user credentials automatically.

The [provider SDK guide](../provider-sdk.md) maps these authorization modes to optional capabilities. Account-specific consent, permissions and live acceptance remain explicit [open gates](../plans/mailbox-provider-sdk/live-acceptance.md).

## Upgrading Legacy Browser Enrollment

`GmailUserOAuthService` and `MsGraphUserOAuthService` retain their public signatures but no longer initiate enrollment or exchange codes. They throw `OAuthEnrollmentException` with a reconnect instruction before creating state, contacting a provider or writing tokens. The old signatures cannot establish trusted host ownership, verified provider/client identity or a current lifecycle generation. Earlier global subject/email lookups could reassign a token to another connection.

Keep the optional package routes disabled:

```env
MAILBOX_OAUTH_ENABLED=false
```

Use the [Gmail](#enrolling-gmail-with-host-owned-storage) or [Microsoft](#enrolling-and-refreshing-microsoft-with-host-owned-storage) service from your own authorized endpoints. Configure an explicit registered callback URI. An authenticated route or a caller-supplied `mailbox_connection_id` / `user_reference` is not connection authorization.

If the legacy routes remain enabled, their redirect endpoints return HTTP 400 JSON with `success=false`, `error=oauth_setup_required` and a safe `error_description`. Historical callback requests retain their safe error-return fields (`mailbox_oauth`, `mailbox_oauth_error`, `mailbox_oauth_error_description`, `user_reference`) or the existing JSON error shape when no trusted return remains. They cannot produce new credentials. `stateContext()` only reads old cached return context; it does not consume or authorize an OAuth attempt. Callback-result classes and custom service/controller extensions remain available.

Existing token rows, encrypted attributes, references and configured integrations are preserved. Legacy `gmail-user` / `ms-graph-user` rows remain historical records, including rows with a connection foreign key. A matching email, subject or tenant does not reconstruct lost ownership or verified identity. See the [legacy inventory and reconnect steps](../upgrade-guide.md#legacy-oauth-records-and-reconnect). This security change intentionally stops previously unsafe browser enrollment.

## Provider Setup

Register a Web OAuth client with the provider and allow the exact HTTPS callback URI used by your host. Configure only the delegated mail permissions your application needs, with the identity and offline-consent scopes required below. Provider registration, consent, actual mailbox permissions and deployed shared-lock behavior remain separate integration prerequisites.

| Provider | Client settings | Callback and scopes |
| --- | --- | --- |
| Gmail | `mailbox.oauth.gmail.client_id`, `client_secret` | `mailbox.oauth.gmail.redirect_uri`, `scopes` including `openid` and the intended Gmail permissions |
| Microsoft | `mailbox.drivers.ms-graph.client_id`, `client_secret`, `tenant_id` | `mailbox.oauth.ms_graph.redirect_uri`, `scopes` including `openid`, `profile`, `offline_access`, `User.Read` and the intended Graph mail permissions |

Store credentials and OAuth state privately. The optional Eloquent store uses Laravel's encryption key; retain that key or re-encrypt existing values during key rotation. If the original key or ownership evidence is lost, reconnect rather than trying to infer it from an email address.

## Safe Return Destinations

Both enrollment services accept local application paths such as `/settings/mailbox` and absolute HTTP(S) URLs whose host appears in `mailbox.oauth.allowed_return_hosts`. Invalid destinations fall back to a valid `mailbox.oauth.default_return_url`, otherwise the result has no return URL. Scheme-relative URLs, backslashes and control characters are rejected. Revalidate old cached returns too; the legacy error controllers use the same return policy.

```env
MAILBOX_OAUTH_ALLOWED_RETURN_HOSTS=acme.com,app.acme.com
```

## Reading With an Explicit Grant

You may read through a grant chosen by your application without querying Mailbox's tables. Supply the connection ID, grant ID, current reconnect/disconnect generation, configured driver name and target mailbox together:

```php
use Pyle\Mailbox\Contracts\GrantTokenProvider;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Facades\Mailbox;

/** @var GrantTokenProvider $tokens Your application's storage and lifecycle adapter. */
$context = new GrantContext(
    connectionId: 'connection-42',
    grantId: 'grant-17',
    lifecycleGeneration: '3',
    driver: 'gmail', // Or ms-graph / an existing configured alias.
    mailbox: 'person@example.com',
);

$message = Mailbox::forGrant($context, $tokens)->message('provider-message-id')->get();
```

Implement `GrantTokenProvider` in your application:

```php
interface GrantTokenProvider
{
    public function assertActive(GrantContext $context): void;

    public function accessToken(GrantContext $context): string;
}
```

`assertActive()` must check that the selected grant belongs to the connection, that its provider identity matches the configured driver, that the generation is current and connected, and that it permits the target mailbox. Check the verified subject, tenant/issuer and OAuth client registration where applicable. Throw `Pyle\Mailbox\Exceptions\GrantAccessDeniedException` when access is no longer valid. `accessToken()` loads a currently valid token for that exact grant; it must never search by mailbox address or choose the first active token.

The SDK checks the host guard before loading the token and again before each HTTP attempt, including retries and later reads through a retained resource. Reconnect and disconnect must change the host generation or reject the old context. Ordinary token rotation keeps the same lifecycle generation. Separate connections can select different grants for the same external account, while one grant can permit several mailbox targets.

Each call creates an isolated provider resource and leaves the configured-driver cache and global configuration unchanged. Configured `mailbox()`, `forMailbox()`, custom creators, resolver replacements and existing driver/client constructors remain available. Custom drivers may implement the optional `SupportsGrantMailbox` contract; drivers without it throw `UnsupportedCapabilityException` only when asked to serve `forGrant()`.

This entrypoint accepts valid supplied tokens or the refreshing provider below. Use the Gmail or Microsoft enrollment service to obtain a selected grant; the runtime does not discover old OAuth rows. A provider `401` fails with `AuthenticationException` without using configured service-account or application credentials. Host storage, authorization and durable job handling remain in your application. For SDK-only boot, [disable package persistence](../configuration.md#using-your-own-persistence) and leave the database-backed OAuth routes disabled.

## Enrolling Gmail With Host-Owned Storage

You may connect personal Gmail or Google Workspace accounts without package tables. Configure `mailbox.oauth.gmail.client_id`, `client_secret`, `redirect_uri` and scopes, including `openid` and a Gmail read scope. Register that exact callback URI on your Google Web OAuth client and configure the consent screen for the accounts you intend to support. Offline consent requests a refresh token, but Google may omit it.

Supply three host adapters to the enrollment service:

| Contract | Host responsibility |
| --- | --- |
| `OAuthEnrollmentGuard` | `assertAllowed($context, $hostContext)` authorizes the current principal, selected connection, grant and generation at initiation, before exchange and before commit. Derive the opaque host context from trusted session/application state. |
| `OAuthStateStore` | `put($state, $payload)` stores the private `OAuthState` until `expiresAt`; `consume($state)` atomically removes it once across all callback workers. A cache `get` followed by `forget`, including Laravel's `Cache::pull`, does not satisfy this contract. |
| `GrantStore` | `load($context)` loads that selected grant. `replace($context, $expected, $replacement)` atomically checks ownership, lifecycle generation and the expected identity/revision. A null expectation requires absence; return false on conflict. |

```php
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Services\OAuth\GmailGrantOAuthService;

// These adapters are implemented by your application.
$oauth = new GmailGrantOAuthService($guard, $states, $grants);
$context = new GrantContext(
    connectionId: 'connection-42',
    grantId: 'grant-19',
    lifecycleGeneration: 'generation-3',
    driver: 'gmail', // 'google-workspace' uses the same enrollment flow.
    mailbox: 'person@gmail.com',
);

// Initiation: persist the selected context in your own trusted session state.
$url = $oauth->authorizationUrl($context, $hostContext, '/settings/mailboxes');

// Callback: recover context and hostContext from that session, not query input.
$result = $oauth->handleCallback($context, $hostContext, $state, $code);

// Your existing GrantTokenProvider loads this stored grant's valid access token.
$message = Mailbox::forGrant($context, $tokens)->message($messageId)->get();
```

`OAuthState` binds the provider, selected context, client, redirect URI, host context, expected credential revision, nonce and S256 PKCE verifier. Callback consumption happens before the code exchange, including failed exchanges. Missing, expired, mismatched or replayed state fails before provider I/O. The host should consume abandoned error callbacks through its state adapter and restart authorization after failure.

The SDK validates Google's issuer, subject, client audience/authorized party, expiry, issue time and initiation nonce. Its private decoder accepts only the ID token returned by the fixed Google token endpoint over verified TLS with client authentication and redirects disabled. This follows the [direct token-endpoint trust rule](https://openid.net/specs/openid-connect-core-1_0.html#IDTokenValidation) and [Google's server flow](https://developers.google.com/identity/openid-connect/openid-connect#obtainuserinfo). It is not a verifier for caller-supplied JWTs and does not accept an `id_token` from callback input or use a profile fallback.

`StoredGrant` contains the verified `GrantIdentity`, expiry, granted scopes, a new revision, optional email and non-secret metadata (`display_name` and `hosted_domain`). Email and Workspace domain metadata do not choose credentials or authorize the host. If your application restricts Workspace domains, enforce that policy in its guarded `replace()` before accepting the new grant; an email suffix or authorization hint is insufficient. `GrantEnrollmentResult` returns the context, verified identity, email, expiry, safe `returnTo` and optional `userReference`, without tokens or a package model.

A missing refresh-token field preserves an earlier token only from this same selected grant with exactly matching provider, normalized issuer, subject, client and tenant identity. An explicit invalid token or a different identity without a new refresh token fails. A returned token replaces the earlier one through the conditional write. Separate connections to the same account stay independent.

Keep credentials and state encrypted in private storage. Use the `StoredGrant::accessToken()`, `refreshToken()`, `nonceHash()`, `OAuthState::nonce()` and `codeVerifier()` accessors when mapping private storage; their JSON/debug representations intentionally omit private values. The trailing optional `StoredGrant` constructor field `nonceHash` retains a SHA-256 fingerprint of the original verified enrollment nonce. Preserve it when retaining an earlier refresh secret; a new enrollment secret records the new nonce fingerprint. Never reuse credential revisions. Reconnect/disconnect must invalidate the previous lifecycle generation, while ordinary token rotation keeps it. The store must enforce these checks atomically even if ownership changes between the final guard and the write. A rejected write never falls back to another grant.

Your host-owned endpoints call this service directly. The default package browser controllers now fail closed; they do not enroll or bind legacy records. Safe return paths and allowed hosts use the same return policy as those controllers. Provider setup, live consent and live mailbox validation remain separate acceptance gates.

## Refreshing Host-Owned Gmail Grants

You may use `RefreshingGrantTokenProvider` to read with a stored Gmail grant and renew it before expiry. Supply your host's `GrantAccessGuard` and `GrantStore`, an explicitly named shared Laravel cache store, and the Gmail refresher:

```php
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Services\OAuth\GmailGrantRefresher;
use Pyle\Mailbox\Support\CacheGrantRefreshLock;
use Pyle\Mailbox\Support\RefreshingGrantTokenProvider;

// $guard implements GrantAccessGuard; $grants implements GrantStore.
// Configure cache.stores.mailbox-refresh to use your central Redis or database store.
$tokens = new RefreshingGrantTokenProvider(
    $guard,
    $grants,
    new CacheGrantRefreshLock('mailbox-refresh'),
    new GmailGrantRefresher,
);

$message = Mailbox::forGrant($context, $tokens)->message($messageId)->get();
```

`GrantAccessGuard::assertActive()` has the same authorization checks described above. Existing `GrantTokenProvider` implementations already satisfy this guard contract. The Gmail refresher uses `mailbox.oauth.gmail.client_id` and `client_secret`; its selected grant must have the same verified Google identity and client. Both `gmail` and `google-workspace` contexts use this delegated path. Existing configured service-account and application modes keep their current authentication.

Every worker and server must use the same central lock backend, connection, database and key prefix. `CacheGrantRefreshLock` rejects array, file, null, failover and non-locking stores. An unavailable, failed or contended lock stops before the refresh exchange. Custom stores must provide cross-process atomic lock ownership; package tests and the `LockProvider` interface alone cannot establish that deployment property.

The lock key uses the exact connection/grant tuple across mailbox targets, revisions and lifecycle generations. A token with more than 30 seconds remaining is used directly. Otherwise, the provider acquires a 60-second lock, reloads authoritative credentials, and exchanges only if they still need refresh. Google HTTP exchanges have a 30-second timeout, verified TLS and no redirects. The host's atomic `replace()` must still check identity, revision and generation because a lock lease may expire during I/O. Refresh advances the credential revision and preserves the lifecycle generation; reconnect and disconnect must invalidate the previous generation.

An omitted refresh token retains the secret from that same reloaded, verified identity. A returned rotation replaces it atomically and keeps the original nonce fingerprint. A refresh response may omit its ID token. If present, its issuer, subject and audience must match the selected identity, with valid issue time and expiry. An optional returned nonce must match the stored original fingerprint; a mismatch or missing baseline fails closed. An omitted nonce remains valid. Returned scopes replace the stored scopes; an omitted scope retains the previous list.

Catch `GrantRefreshException` to inspect `retryable` and `retryAfterSeconds`. Coordination failures, transport errors, rate limits and temporary provider failures are retryable. Client setup and malformed credential responses are non-retryable refresh errors. `GrantAccessDeniedException` reports revoked, invalid or insufficient delegated authority and identity/lifecycle changes; request reconnect or apply your host's authorization policy. Exceptions omit raw provider bodies and secrets. The SDK performs no refresh retries, releases no enclosing job and never falls back to configured credentials. Your application decides when to retry.

## Enrolling and Refreshing Microsoft With Host-Owned Storage

You may connect work/school Microsoft 365 and personal Outlook.com or Hotmail accounts without package tables. `MsGraphGrantOAuthService` uses the same host guards, atomic state consumption, conditional grant storage and safe return policy as Gmail enrollment. Keep the package's legacy browser routes disabled when your host owns these endpoints.

Configure `mailbox.drivers.ms-graph.client_id`, `client_secret` and `tenant_id` for the selected Entra Web app registration, plus `mailbox.oauth.ms_graph.redirect_uri` and `scopes`. Register the exact redirect URI and choose supported account types that match the intended users. The authority must be one of these values:

| `tenant_id` | Accounts accepted by the SDK |
| --- | --- |
| An explicit tenant GUID | That tenant, including users signing into it as guests |
| `organizations` | Organizational tenants |
| `consumers` | The personal Microsoft account tenant |
| `common` | Organizational and personal Microsoft accounts |

The actual ID-token issuer must be `https://login.microsoftonline.com/{tid}/v2.0`; `common`, `organizations` and `consumers` are authority selectors, never literal issuers. Other clouds, tenant-domain aliases and arbitrary authority URLs are not supported by this flow.

Request `openid`, `profile`, `offline_access` and `User.Read`, plus the delegated mail permissions your application needs. `User.Read` permits the identity check through Graph `/me`. For own-mailbox reads, request `Mail.Read` or `Mail.ReadWrite`; organizational shared reads require `Mail.Read.Shared` or `Mail.ReadWrite.Shared` and the signed-in user's actual Exchange access to that target. Configure those scopes explicitly and obtain user or administrator consent according to the tenant's policy. A registration that only accepts organizational users cannot enroll personal accounts by changing the authority alone.

```php
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Services\OAuth\MsGraphGrantOAuthService;
use Pyle\Mailbox\Services\OAuth\MsGraphGrantRefresher;
use Pyle\Mailbox\Support\CacheGrantRefreshLock;
use Pyle\Mailbox\Support\RefreshingGrantTokenProvider;

$oauth = new MsGraphGrantOAuthService($enrollmentGuard, $states, $grants);
$context = new GrantContext('connection-42', 'grant-19', 'generation-3', 'ms-graph', 'person@example.com');

// Initiation and callback recover these contexts from trusted host state.
$url = $oauth->authorizationUrl($context, $hostContext, '/settings/mailboxes');
$result = $oauth->handleCallback($context, $hostContext, $state, $code);

$tokens = new RefreshingGrantTokenProvider(
    $accessGuard, $grants, new CacheGrantRefreshLock('mailbox-refresh'), new MsGraphGrantRefresher,
);
$message = Mailbox::forGrant($context, $tokens)->message($messageId)->get();
```

The state binds the authority, client, redirect URI, selected context, host context, expected revision, nonce and S256 PKCE verifier. Host state adapters must persist the optional `OAuthState::authority` field as well as its private nonce and verifier. Changing the authority or client during enrollment requires a fresh authorization attempt.

The SDK checks the direct token response's v2 issuer, tenant, subject, audience, optional authorized party, expiry, issue time, not-before time and nonce. Graph `/me.id` must match the ID token's `oid`. The `GrantIdentity` subject is the distinct pairwise OIDC `sub`; `StoredGrant::metadata['object_id']` retains the verified Graph object ID. Email and profile names are display metadata, never grant selectors. The private decoder accepts only ID tokens received directly from the fixed Microsoft HTTPS token endpoint with client authentication, TLS verification and redirects disabled; it does not decode Graph access tokens or verify caller-supplied JWTs. See Microsoft's [identity claims](https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference) and [authorization-code flow](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow).

Enrollment requires a renewable token. An omitted refresh token preserves the previous secret and nonce fingerprint only for the same selected grant and matching verified identity and object ID. Refresh uses the verified tenant's token endpoint and the shared lock/reload/conditional replacement path described above. It preserves an omitted refresh secret, stores rotations, advances the credential revision and keeps the lifecycle generation. An optional refresh ID token must match the original subject, tenant, issuer, client and object ID; a returned nonce must match the original fingerprint. An omitted ID token is valid. Returned scope values describe the response; they do not establish consent or resource access by themselves.

`OAuthEnrollmentException` reports invalid or incomplete enrollment/setup, including a rejected profile identity check. Refresh uses `GrantRefreshException` for retryable coordination/transport/429/temporary failures and non-retryable client setup or malformed responses. `GrantAccessDeniedException` reports lost/insufficient delegated authority or a changed identity/lifecycle. These errors omit raw responses and credentials. None selects configured application credentials as a fallback.

### Shared-Mailbox Access

After the host authorizes another mailbox for the same grant, create a `GrantContext` with the same connection, grant and generation and that mailbox as the target. The shared refresh lock spans these targets. A Graph read must succeed under that delegated token; successful enrollment or `/me` does not prove permission to the shared mailbox. Provider denial remains an access error.

Delegated shared-mailbox discovery is delta-only. Microsoft [does not support shared/delegated change subscriptions with the shared delegated mail scopes](https://learn.microsoft.com/en-us/graph/outlook-share-messages-folders). Required live shared read/send/watch uses a separately selected, scoped application-permission grant and its dedicated capability/setup checks; this enrollment API does not establish that setup or combine grants. Existing configured application-permission integrations retain their current path. Live account, consent, shared-lock deployment and mailbox acceptance remain separate validation gates.

## What's Next

- [Microsoft Graph (App-Only)](ms-graph.md) -- client credentials setup for server-side Microsoft 365 access.
- [Gmail (Google Workspace)](gmail.md) -- service account setup for Google Workspace mailboxes.
- [Eloquent Models](../eloquent-models.md) -- working with `MailboxOAuthToken` and other Mailbox models.

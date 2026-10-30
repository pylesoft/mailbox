# Microsoft Graph (App-Only) Authentication

Mailbox connects to Microsoft 365 mailboxes through the OAuth 2.0 **client credentials flow** — the recommended path for server-side and background workloads where your application reads, modifies, and organizes mail without any interactive user sign-in. You register an app in Microsoft Entra, grant it the right permissions, and Mailbox handles token acquisition and caching automatically at runtime.

For browser consent by individual work/school or personal Microsoft users, see [host-owned User OAuth](user-oauth.md). Your application supplies its endpoints, authorization and grant storage; Mailbox does not discover stored user tokens from a mailbox address.

## What You Need

- A Microsoft 365 tenant with Exchange Online mailboxes.
- Azure / Entra admin access to register an application.
- Exchange admin access to scope mailbox access (recommended).

## Which Values You Need for `.env`

- `MS365_TENANT_ID`
- `MS365_CLIENT_ID`
- `MS365_CLIENT_SECRET`

Mailbox obtains and caches access tokens automatically at runtime. There is no manual token management required.

## Step 1: Register the Application in Entra

1. Open the [Microsoft Entra admin center](https://entra.microsoft.com/).
2. Navigate to **Identity > Applications > App registrations > New registration**.
3. Name your app (example: `Acme Mailbox Production`).
4. Supported account type: choose your tenant option (single-tenant is typical for internal apps).
5. Redirect URI: leave blank for client credentials — it is not required in this mode.
6. Click **Register**.

After creation, copy two values from the app overview page:

- **Application (client) ID** -- this becomes `MS365_CLIENT_ID`
- **Directory (tenant) ID** -- this becomes `MS365_TENANT_ID`

> **Tip** Bookmark the app's overview page. You will return here several times during setup.

## Step 2: Create a Client Secret

1. In the app registration, go to **Certificates & secrets**.
2. Click **New client secret**.
3. Enter a description and choose an expiration period.
4. Click **Add**.
5. Copy the **Value** column immediately.

> **Warning** The secret value is only shown once. If you navigate away before copying it, you will need to create a new secret. Copy the **Value**, not the **Secret ID**.

Use that value as `MS365_CLIENT_SECRET` in your environment.

> **Tip** Set a calendar reminder to rotate your client secret before it expires. Mailbox dispatches a `SecretExpirationWarning` event when the secret is within the configured warning window (default: 30 days). Listen for this event to send Slack alerts or emails automatically. See [Events](../events.md) for details.

## Step 3: Add API Permissions

1. In the app registration, go to **API permissions**.
2. Click **Add a permission > Microsoft Graph > Application permissions**.
3. Add the following permissions:
   - `Mail.ReadWrite` (required for mailbox read/write operations and staged draft preparation)
   - `Mail.Send` when submitting a prepared draft
   - `User.Read.All` (recommended for tenant-wide user/directory reads and legacy `/users` probes)
4. Click **Grant admin consent for [your tenant]**.

> **Warning** Without admin consent, token requests may succeed but every mailbox API call will fail with `403 Forbidden`. This is the most common setup oversight.

If you only call mailbox endpoints and do not need directory or user listing, you can omit `User.Read.All`. The health check will still pass by probing the Graph service root instead.

### Sharing One App for Both Flows

If you use one Entra app registration for both app-only and [user OAuth](user-oauth.md) flows, also add these **Delegated** permissions:

- `openid`
- `profile`
- `email`
- `User.Read`
- `offline_access`
- `Mail.ReadWrite`
- `Mail.ReadWrite.Shared` (for shared mailbox delegated access)

## Step 4: Restrict Mailbox Access (Recommended)

By default, application permissions grant access to every mailbox in your tenant. You should restrict the app to only the mailboxes it needs.

For new setup, follow Microsoft's [Application RBAC guidance](https://learn.microsoft.com/en-us/exchange/permissions-exo/application-rbac). The Application Access Policy example below describes the legacy policy path. RBAC and Entra permissions are independent; verify the application's complete effective access to the selected mailbox.

> **Warning** Skipping this step means your application can read every mailbox in the organization. Always scope access in production.

### Exchange Application Access Policy

Connect to Exchange Online PowerShell and create a policy:

```powershell
Connect-ExchangeOnline

# Create a mail-enabled security group to serve as the policy scope
New-DistributionGroup -Name "PyleMailboxAccess" -Type Security

# Add only the mailboxes your application needs
Add-DistributionGroupMember -Identity "PyleMailboxAccess" -Member "invoices@acme.com"
Add-DistributionGroupMember -Identity "PyleMailboxAccess" -Member "billing@acme.com"

# Restrict your app to that group
New-ApplicationAccessPolicy `
  -AppId "<MS365_CLIENT_ID>" `
  -PolicyScopeGroupId "PyleMailboxAccess@acme.com" `
  -AccessRight RestrictAccess `
  -Description "Restrict Pyle Mailbox app to approved mailboxes"

# Validate the policy for a specific mailbox
Test-ApplicationAccessPolicy `
  -AppId "<MS365_CLIENT_ID>" `
  -Identity "invoices@acme.com"
```

> **Note** Application Access Policies can take up to 30 minutes to propagate. If `Test-ApplicationAccessPolicy` returns "Denied" immediately after creation, wait and retry.

If your tenant uses newer Exchange authorization models (e.g., RBAC for Applications), use the equivalent scoping mechanism there. The requirement is the same: only grant access to the mailboxes your application needs.

## Step 5: Configure Laravel Environment

Add the following to your `.env` file:

```env
MAILBOX_DRIVER=ms-graph

MS365_TENANT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MS365_CLIENT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MS365_CLIENT_SECRET=your-secret-value
```

### Optional Runtime Tuning

```env
MAILBOX_CACHE_STORE=redis
MAILBOX_ATTACHMENT_DISK=local
MAILBOX_ATTACHMENT_PATH=mailbox-attachments
MAILBOX_LOG_LEVEL=info
MAILBOX_QUEUE_RETRY_STRATEGY=release
```

For a complete reference of every available configuration key, see [Configuration](../configuration.md).

## Step 6: Verify the Connection

Run the built-in test commands to confirm that authentication and mailbox access are working end-to-end:

```bash
php artisan mailbox:test-access invoices@acme.com --driver=ms-graph
```

Expected output on success:

```
 Connected successfully (245ms)
 Access to invoices@acme.com: Granted
```

Then run the health check:

```bash
php artisan mailbox:health --driver=ms-graph
```

Expected output:

```
 Token: Valid (expires in 3312s)
 API: Reachable (134ms)
 Status: Healthy
```

If either command fails, jump to [Troubleshooting](#troubleshooting) below.

## How Client Credentials Tokens Work

Understanding the token flow helps when debugging authentication issues. Here is what happens under the hood:

1. **Request a token.** The `TokenManager` sends a `POST` to the Entra token endpoint (`https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`) with the client ID, client secret, and the `https://graph.microsoft.com/.default` scope.

2. **Cache the token.** Mailbox caches the access token using Laravel's cache system. The default token lifetime is 3600 seconds (1 hour), and Mailbox subtracts a configurable buffer (default 300 seconds) to refresh proactively before expiry.

3. **Make API calls.** Every Graph request includes the token as a `Bearer` header.

4. **Handle expiry.** On `401` responses, Mailbox invalidates the cached token and re-authenticates once before throwing an exception. There is no refresh token in client credentials mode — Mailbox simply requests a new access token.

> **Tip** You can customize the cache store and refresh buffer in `config/mailbox.php` via the `cache_store` and `token_refresh_buffer` keys.

## Optional: Manually Request a Token (Debug)

If you need to verify your credentials outside of Laravel, you can request a token directly with `curl`:

```bash
curl -sS -X POST "https://login.microsoftonline.com/${MS365_TENANT_ID}/oauth2/v2.0/token" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "client_id=${MS365_CLIENT_ID}" \
  -d "client_secret=${MS365_CLIENT_SECRET}" \
  -d "scope=https://graph.microsoft.com/.default" \
  -d "grant_type=client_credentials"
```

A successful response returns a JSON object with `access_token`, `expires_in`, and `token_type`. If this fails, the issue is in your Entra configuration — not in Mailbox.

## Security Best Practices

### Store Secrets Securely

Never commit `MS365_CLIENT_SECRET` to version control. Use your deployment platform's secrets manager (Vault, AWS Secrets Manager, Laravel Forge environment variables, etc.) to inject the value at runtime.

### Rotate Client Secrets Before Expiry

Client secrets in Entra have a configurable expiration (default: 24 months). Create a rotation schedule:

1. Generate a new secret in **Certificates & secrets**.
2. Update `MS365_CLIENT_SECRET` in your environment.
3. Run `php artisan mailbox:test-access` to verify.
4. Delete the old secret from Entra.

> **Tip** Consider using a **certificate** instead of a client secret for even stronger security. Certificates eliminate the risk of secret leakage in logs or environment dumps.

### Keep Permissions Minimal

Only request the Graph permissions your application actually uses. If you only read and move mail, `Mail.ReadWrite` is sufficient. Avoid `Mail.ReadWrite` + `User.Read.All` unless you genuinely need directory queries.

### Restrict Mailbox Scope

Scope effective application authority to the intended mailboxes. Microsoft's current [Application RBAC guidance](https://learn.microsoft.com/en-us/exchange/permissions-exo/application-rbac) replaces Application Access Policies and explains that Exchange RBAC grants and Entra grants are independent. Existing policies remain relevant to legacy setups; verify all effective grants rather than assuming that a scoped RBAC assignment removes separately granted tenant-wide access.

### Monitor Token Events

Mailbox dispatches events at key moments in the authentication lifecycle:

- `TokenAcquired` — a new access token was obtained
- `TokenRefreshFailed` — token acquisition failed (alert on this)
- `SecretExpirationWarning` — the client secret is nearing expiry

Listen for these events to build proactive alerting. See [Events](../events.md) for details.

## Authenticated Request Destinations

Authenticated requests stay on the configured Graph API origin and API-version path. When you supply a mailbox context, the URL must target `users/{mailbox}` for that mailbox. Ordinary relative resource paths and trusted absolute Graph URLs remain supported. Foreign origins, schemes, ports, user information, fragments and unsafe paths are rejected before token acquisition or HTTP transmission.

Authenticated Graph and Gmail requests do not follow redirects, including redirects to the same origin. This prevents a redirect from moving credentials to another resource. Preauthenticated upload URLs require a separate provider upload flow; do not pass them to the authenticated raw client.

Gmail applies the same origin restriction using its configured `api_base_uri`. Its explicit `users/{mailbox}` target must match the selected mailbox; `users/me` uses that mailbox's authorization. These checks restrict request destinations and do not grant access to a mailbox.

## Troubleshooting

### `invalid_client`

The client ID or secret is wrong, or the secret has expired.

- Verify `MS365_CLIENT_ID` matches the **Application (client) ID** on the Entra app overview page.
- Verify `MS365_CLIENT_SECRET` is the secret **Value** (not the Secret ID).
- Check the secret's expiration date in **Certificates & secrets**. If expired, create a new one.

### `invalid_grant` or `AADSTS700016`

The tenant ID does not match, or the app registration does not exist in the specified tenant.

- Verify `MS365_TENANT_ID` matches the **Directory (tenant) ID** on the Entra app overview page.
- If using a multi-tenant app, ensure the app has been consented in the target tenant.

### `insufficient privileges` or `Authorization_RequestDenied`

The app has the right permissions listed, but admin consent has not been granted.

- Go to **API permissions** in the app registration and click **Grant admin consent**.
- Wait a few minutes for consent to propagate, then retry.

### `403 Access Denied` When Accessing a Mailbox

Authentication succeeds (you get a token), but the mailbox API call is rejected.

- If you configured an Application Access Policy, verify the target mailbox is a member of the security group.
- Run `Test-ApplicationAccessPolicy` in Exchange PowerShell to confirm.
- Remember that policy propagation can take up to 30 minutes.

### `403 MailboxNotEnabledForRESTAPI`

The target mailbox is not hosted on Exchange Online, or it is an on-premises mailbox in a hybrid environment.

- Verify the mailbox is a full Exchange Online mailbox (not on-premises or a shared mailbox without a license).

### Intermittent `429 Too Many Requests`

Microsoft Graph enforces per-app and per-mailbox throttling limits. Mailbox handles `429` responses automatically with retry logic. If you consistently hit limits:

- Spread requests across mailboxes rather than targeting a single one.
- Use [delta sync](../delta-sync.md) to reduce the number of full-list calls.
- Set `MAILBOX_QUEUE_RETRY_STRATEGY=release` to release queue jobs back for later processing instead of blocking workers with inline retries.

### `401 Unauthorized` After Previously Working

The cached token may have been invalidated server-side. Mailbox automatically retries once after clearing its token cache. If the error persists:

- Verify the client secret has not been rotated or deleted.
- Check the Entra app registration still has the correct permissions and admin consent.
- Clear the Mailbox token cache: run `php artisan cache:forget mailbox_token:{tenant}:{client}` or flush the configured cache store.

## Basic Graph Subscriptions

Explicit-grant Graph resources expose the optional `SupportsGraphSubscriptions` capability. You may create, renew and remove basic message subscriptions without Mailbox tables. Configured reads and delta-only consumers do not require subscription setup.

```php
use Pyle\Mailbox\Drivers\MsGraph\Contracts\SupportsGraphSubscriptions;
use Pyle\Mailbox\DTOs\GraphSubscriptionConfiguration;
use Pyle\Mailbox\Facades\Mailbox;

$mailbox = Mailbox::forGrant($context, $tokens);

if (! $mailbox instanceof SupportsGraphSubscriptions) {
    throw new RuntimeException('This provider does not support Graph subscriptions.');
}

$configuration = new GraphSubscriptionConfiguration(
    tenantId: $trustedTenantId,
    providerMailboxId: $trustedCanonicalMailboxId,
    notificationUrl: 'https://app.example.com/graph/changes',
    lifecycleNotificationUrl: 'https://app.example.com/graph/lifecycle',
    clientState: $privateRandomClientState,
    folderId: 'inbox',
);

$registration = $mailbox->createSubscription(
    $configuration,
    new DateTimeImmutable('+1 day'),
);
```

The request targets the mailbox in the selected grant context. The host establishes the canonical mailbox ID and tenant mapping from trusted provider or setup evidence. A callback's resource path cannot establish that mapping. Canonical mailbox IDs are opaque provider identities; the SDK does not require an organizational GUID shape. Omit `folderId` to subscribe to all messages in the selected mailbox. Email and GUID context aliases may differ in case; opaque mailbox and folder IDs remain exact. Only known folder aliases normalize case. Retain `clientState` privately: JSON and debug output omit that secret.

### Permissions and Endpoint Setup

Your host must configure public HTTPS notification and lifecycle endpoints. Use an appropriate Microsoft registration, read permission and consent for the selected mailbox. Delegated subscriptions cover the signed-in user's own mailbox. Shared or delegated folders require application permissions; Outlook sharing permissions such as `Mail.Read.Shared` do not support subscriptions. Required live shared-mailbox mode uses the same explicitly selected application grant for read, send and subscription operations. See Microsoft's [Outlook notification prerequisites](https://learn.microsoft.com/en-us/graph/outlook-change-notifications-overview).

### Renewal and Removal

Retain the registration in trusted host storage and renew it before its provider-returned expiry:

```php
$renewed = $mailbox->renewSubscription($registration, new DateTimeImmutable('+1 day'));
$removed = $mailbox->removeSubscription($renewed);
```

Creation and renewal request a future expiry within the basic Outlook limit of seven days. Microsoft may normalize short requests to its minimum lifetime, so retain the returned expiry. Renewal rejects expired or removed retained registrations before HTTP; use inspection to reconcile uncertain provider state before explicitly creating a replacement. Removal sends an empty-body `DELETE` and returns a registration whose status is `removed` after a confirmed empty `204` response. Your host owns scheduling and durable registration updates. See the [subscription lifetime reference](https://learn.microsoft.com/en-us/graph/api/resources/subscription?view=graph-rest-1.0#subscription-lifetime).

These operations make one provider attempt. An unconfirmed acknowledgement throws `ApiRequestException` with the available status and safe response headers. The SDK does not replay a possibly completed write or release the enclosing host job. Keep the original attempt and reconcile uncertain provider state before creating another subscription.

### Inspect and Recover Registrations

The separate optional `SupportsGraphSubscriptionInspection` capability obtains fresh provider evidence through the currently selected grant. Supply the trusted application client ID associated with that grant; neither `GrantContext` nor the configuration establishes this identity, and the SDK does not decode access tokens to infer it.

```php
use Pyle\Mailbox\Drivers\MsGraph\Contracts\SupportsGraphSubscriptionInspection;
use Pyle\Mailbox\DTOs\SyncBudget;

if (! $mailbox instanceof SupportsGraphSubscriptionInspection) {
    throw new RuntimeException('This provider cannot inspect Graph subscriptions.');
}

$registration = $mailbox->inspectSubscription(
    $retainedSubscriptionId,
    $configuration,
    $trustedApplicationClientId,
);
```

Inspection makes one actual `GET /subscriptions/{id}` and validates the application, subscription ID, mailbox/folder scope, private endpoints and client state, basic mode and actual future expiry. The returned registration belongs to this resource's captured context. It can reconcile a lost renewal acknowledgement even when the older retained expiry has passed. `null` means that actual GET returned `404` under this grant; it does not prove global deletion. Token acquisition, authorization failures and malformed responses remain failures, without fallback or another write. See Microsoft's [subscription GET contract](https://learn.microsoft.com/en-us/graph/api/subscription-get?view=graph-rest-1.0).

If a create acknowledgement was lost before its ID was retained, look up the exact private configuration:

```php
$lookup = $mailbox->lookupSubscriptions(
    $configuration,
    $trustedApplicationClientId,
    new SyncBudget(maxItems: 100, maxResponseBytes: 65_536),
    $privateLookupContinuation,
);
```

Each call reads at most one provider page and one request, within the supplied budget. Microsoft does not support OData query options for this endpoint. The item limit counts all returned rows; an oversized page returns `provider_item_limit` with unfinished progress, so the host may increase its budget and retry that page. A response that exceeds the actual byte limit fails. Further pages return `complete=false`, `reason=more_pages` and a private continuation. Repeated progress stays unfinished. Context, application or private configuration changes invalidate a continuation. See the [list contract and visibility rules](https://learn.microsoft.com/en-us/graph/api/subscription-list?view=graph-rest-1.0).

Only exact matching registrations appear in `registrations`. A watch for the same application, resource and change types with another private configuration sets `conflict=true`, without returning its identity or secrets. JSON and debug output contain summary metadata only; retain typed registrations and continuations in private host storage. The host persists each slice before advancing, combines conflicts across slices and resolves multiple matches. An unfinished scan never establishes absence or uniqueness. Even a complete empty scan means only that no matching active watch was visible to this selected grant at that observation; it neither settles an in-flight write nor authorizes an automatic create replay.

Microsoft may reject a duplicate resource/change-type registration with `409`; separate host grant IDs do not establish separate provider watches. Host coordination, independent approvals, callback routing, maintenance authority and durable recovery remain host responsibilities. When reusing an existing watch, each independently authorized context must obtain and retain its own real provider inspection evidence. Copying a registration into another context does not establish access or satisfy shared-mailbox live setup.

### Validate Raw Callbacks

Retain the trusted configuration before starting creation: Graph validates both callback URLs during the create request, before a registration has been returned. Pass the raw PSR-7 request to the handshake method:

```php
$response = $mailbox->subscriptionHandshake($request, $configuration);
```

Your host returns that response within ten seconds. It contains HTTP `200`, `text/plain; charset=utf-8` and the opaque token decoded exactly once. The SDK checks the configured callback URL, POST method and token shape. The validation token does not authorize a notification or establish a mailbox mapping. See Microsoft's [webhook validation contract](https://learn.microsoft.com/en-us/graph/change-notifications-delivery-webhooks#notificationurl-validation).

For a notification, select the context and registrations from trusted host storage, then validate the raw request:

```php
$hints = $mailbox->subscriptionNotifications($request, [$registration]);
```

Every registration must belong to this resource's captured connection, grant, generation and mailbox. Give each context an appropriate trusted endpoint or routing boundary; a batch containing unknown subscriptions fails as a whole. The SDK checks the client-state secret, tenant, subscription expiry evidence, endpoint and canonical mailbox resource. A folder subscription retains its original folder scope even when Graph identifies a changed message by its mailbox-level path.

Basic requests use `includeResourceData=false`. Legitimate basic callbacks may contain `resourceData` identity metadata such as the message ID, `@odata.id`, type and etag. Its presence alone does not request rich mode. Encrypted content, rich validation-token envelopes, certificate management and decryption are unsupported. Callback results contain no message body or synchronization checkpoint.

The default body limit is 64 KiB, enforced while reading the request stream. You may supply a smaller or larger host limit as the third argument. The host owns the request stream; use a seekable body if earlier middleware consumed it. Callback validation makes no provider request and dispatches no queue job or event.

### Lifecycle and Recovery

Lifecycle callbacks use the configured lifecycle URL and the same subscription, tenant and secret binding. Graph omits the message resource from these events; the SDK returns the retained registration scope with a `missed`, `subscriptionRemoved` or `reauthorizationRequired` reason. Your host reconciles and restores or reauthorizes the registration as appropriate. See Microsoft's [lifecycle guidance](https://learn.microsoft.com/en-us/graph/change-notifications-lifecycle-events).

Duplicates and out-of-order callbacks remain reconciliation hints. The host retains the complete receipt durably before acknowledgement, deduplicates dispatch and reconciles from committed per-folder synchronization progress. A hint never advances that progress or publishes provider data. Keep recovery scans even in live mode; webhook delivery can be delayed or lost.

Use `$registration->lifecycle()` to inspect `active`, `expired` or `removed` state. Late valid change notifications for expired or removed registrations return `subscription_expired` or `subscription_removed` hints. All operations invoke the captured grant's lifecycle guard, including after provider I/O or raw callback parsing. Reconnect or disconnect invalidates older work without choosing another grant.

Protocol fixtures verify these package paths. Account consent, actual shared-mailbox subscriptions, public callback delivery, renewal scheduling and deployed recovery remain separate host/live acceptance gates.

## Require Shared-Mailbox Live Setup

An explicit-grant resource also implements the optional `SupportsGraphSharedMailboxLive` capability. Call `requireSharedMailboxLive($registration)` when the host requires shared-mailbox live mode. Supply an active, unexpired `GraphSubscriptionRegistration` retained from a confirmed subscription operation in trusted host storage. Its connection, selected grant, lifecycle generation, provider and mailbox must match the resource exactly. Caller-supplied registration fields are not provider evidence.

```php
use Pyle\Mailbox\Drivers\MsGraph\Contracts\SupportsGraphSharedMailboxLive;

if (! $mailbox instanceof SupportsGraphSharedMailboxLive) {
    throw new RuntimeException('This provider has no shared-mailbox live setup contract.');
}

$mailbox->requireSharedMailboxLive($registration);
```

The captured host token/lifecycle adapter must additionally implement the optional `VerifiesGraphSharedMailboxLiveAccess` interface. Its `assertSharedMailboxLive(GrantContext, GraphSubscriptionConfiguration): void` method verifies trusted setup evidence for that exact context and the registration's configuration:

- The selected grant uses application permissions for one known application and tenant.
- Required consent and effective scoped mailbox authority are current. Body-only staged read/send needs `Mail.ReadWrite` and `Mail.Send`; basic message watch needs read authority. Delegated shared permissions cannot satisfy this application contract.
- The canonical mailbox ID and tenant map to the selected target. Read, send and watch use that same grant, without combining authority or falling back to configured credentials.
- The exact mailbox/folder scope and notification/lifecycle endpoints have supported, host-verified setup.

Throw `UnsupportedCapabilityException` with a clear setup reason when any fact is missing or unverified. The host owns authorization evidence, its storage and verification; the SDK does not decode opaque access tokens or infer permission from obtaining a token. An adapter that implements only `GrantTokenProvider` remains valid for ordinary operations but cannot establish required-live setup. If a host wraps the supplied refresh provider, its captured outer adapter must expose the verifier and preserve lifecycle checks.

The assertion makes no provider request and sends no probe mail. Success establishes configured, host-verified capability with a current bound registration. It does not establish live-provider acceptance, public callback delivery or recipient delivery. Explicit live acceptance must separately read, prepare/submit a chosen body-only message and create/maintain a subscription on the same selected grant; keep that evidence separate from setup facts.

This check stores no live flag and does not block later reads or sends merely because push expires. The host owns availability policy, renewal, recovery and when to require setup again. Existing per-request lifecycle checks still reject invalidated grants. Keep initial/incremental catch-up reconciliation in every mode: notifications can be delayed or missed, and server discovery does not select a host browser-update mechanism.

### Account and Authentication Capabilities

This matrix describes authorization and discovery contracts. Real provider permissions and notification delivery remain separate acceptance gates; a listed path is not proof that a specific account passed live validation.

| Account | Selected authentication | Discovery with required setup | Required shared-mailbox live contract |
| --- | --- | --- | --- |
| Personal Gmail | Delegated user grant | Gmail watch or delta-only | Not applicable; use Gmail watch setup |
| Google Workspace user | Delegated user grant | Gmail watch or delta-only | Not applicable; use Gmail watch setup |
| Google Workspace mailbox | Authorized service account with domain delegation | Gmail watch or delta-only | Not applicable; use Gmail watch setup |
| Microsoft 365 user's own mailbox | Delegated work/school grant | Basic Graph subscription or delta-only | Not a shared-mailbox application contract |
| Outlook.com/Hotmail user's own mailbox | Delegated personal-account grant | Basic Graph subscription or delta-only | Not a shared-mailbox application contract |
| Microsoft 365 mailbox | Explicit application grant | Basic Graph subscription or delta-only | Available for shared targets only after this setup assertion |
| Microsoft shared mailbox | Authorized delegated user grant | Delta-only; shared delegated scopes do not support subscriptions | Rejected; explicitly select a separate authorized application grant |
| Microsoft shared mailbox | Explicit scoped application grant | Basic Graph subscription plus mandatory catch-up, or delta-only when live is not requested | Requires one application grant for read/send/watch, current bound registration and host verifier |
| Legacy configured Gmail/service account or Graph/application mode | Existing configured driver | Existing reads/delta remain available; explicit notification contracts need a captured grant | Configured token acquisition alone cannot satisfy this assertion |

Shared native reply/forward and shared large-file combinations are not established by this body-only setup contract. Keep those parity claims gated on combined protocol checks and designated live validation. No tenant provisioning, host browser events, durable receipts or scheduling are performed by the SDK.

## What's Next

- [User OAuth](user-oauth.md) -- browser-based sign-in with per-user refresh tokens for MS Graph and Gmail.
- [Gmail (Google Workspace) Authentication](gmail.md) -- service account setup for Google Workspace mailboxes.
- [Configuration Reference](../configuration.md) -- all configuration keys, cache tuning, and runtime options.

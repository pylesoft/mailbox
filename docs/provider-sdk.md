# Provider SDK

Mailbox shares provider behavior while your application keeps its authorization, storage and durable work. Existing configured drivers remain available; delegated operations use an explicitly selected connection, grant, lifecycle generation and mailbox.

```php
use Pyle\Mailbox\Contracts\GrantTokenProvider;
use Pyle\Mailbox\Contracts\SupportsInitialSync;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Facades\Mailbox;

/** @var GrantTokenProvider $tokens Your host's guarded token adapter. */
$context = new GrantContext('connection-42', 'grant-17', 'generation-3', 'ms-graph', 'person@example.com');
$mailbox = Mailbox::forGrant($context, $tokens);

if ($mailbox instanceof SupportsInitialSync) {
    $page = $mailbox->initialSync(new SyncScope('inbox'), new SyncBudget);
    // Retain page work durably before advancing its continuation or checkpoint.
}
```

The host must verify the selected connection and permitted mailbox before returning a token. A mailbox address, provider subject, successful exchange or configured driver name cannot establish that authorization. See [host-owned enrollment and refresh](authentication/user-oauth.md) for both providers.

## Choosing a Capability

New operations use optional contracts. An existing custom driver can keep its current mandatory interfaces and [published stubs](extending/stubs.md).

| Capability | Gmail | Microsoft Graph |
| --- | --- | --- |
| Existing configured message, folder, attachment and raw-client APIs | Workspace service-account delegation | Application credentials |
| Explicit delegated grant reads and refresh | Personal Gmail and Workspace | Work/school and personal Microsoft accounts; target permissions still apply |
| Bounded initial/incremental synchronization | Mailbox/label scope and history baseline | Per-folder delta with explicit mailbox-wide folder progress |
| Complete conversation reads | Complete selected thread within actual byte/content budgets | Complete declared mailbox/folder scope within budgets |
| Structured submission | `SupportsMessageSubmission`, with explicit reply/forward context | `SupportsDraftSubmission`, with native reply/forward preparation |
| Chosen immutable files | MIME/base64url streaming with explicit wire limits | `SupportsDraftAttachmentPreparation`, small-file POSTs and staged Outlook upload sessions |
| Evidence inspection | Original acknowledgement identity; absent identity stays inconclusive | Original immutable draft identity; empty 202 establishes acceptance only |
| Change registration and validation | Watch/stop and trusted host Pub/Sub verifier | Basic subscription create/renew/remove, handshake and lifecycle hints |
| Required shared-mailbox live setup | Not a Microsoft shared-mailbox capability | One explicitly selected application grant and host-verified scoped read/send/watch setup |

These are implemented API contracts verified with controlled HTTP. They do not establish live account acceptance. Consult the [live acceptance ledger](plans/mailbox-provider-sdk/live-acceptance.md) before advertising a particular account, permission or file combination.

## Keeping Application State

Set `MAILBOX_PERSISTENCE_ENABLED=false` before boot when your host owns connections and grants. The core then works without Mailbox tables. Existing Eloquent consumers may keep persistence enabled and use the additive package migrations and [optional selected-grant store](configuration.md#storing-selected-grants-with-eloquent).

Your host owns actor authorization, atomic OAuth state, guarded credential replacement and a shared refresh lock. Ordinary token rotation changes the credential revision; reconnect and disconnect change the lifecycle generation. Retain those distinctions in pending work. Array-store tests do not prove the deployed lock's cross-process semantics.

Private editor drafts remain local. Before explicit submission, retain the chosen content, recipients, immutable file manifest and correlation identity privately. Graph preparation may then create an externally visible draft; retain every returned preparation before the next provider phase. Private serialized preparation contains upload authorization and must never appear in a response, log or untrusted input. Ordinary JSON/debug output intentionally omits that recovery material.

The host also owns exclusive send scheduling and durable receipts. An uncertain send never authorizes another send. Inspect the original evidence; delayed or missing sent-copy results remain inconclusive. The SDK does not supply a durable outbox, deduplicate separate explicit calls, acknowledge your webhook or dispatch reconciliation jobs. See [submission and evidence](messages.md#submitting-a-prepared-gmail-message).

## Reading Content and Files

Use the [bounded synchronization](delta-sync.md) and [conversation](messages.md#reading-a-complete-conversation) APIs for resumable imports. Persist work before promoting progress. A continuation is unfinished work; a notification history ID is not a completed checkpoint. Folder removals do not authorize deleting retained local conversation history.

Received addresses and headers use an internal MIME parser. Malformed or incomplete body/file bytes fail explicitly. Download limits cover actual transport/decoding; the host retains sanitization, private storage, type allowlists and file authorization. See [attachments](attachments.md) for read limits.

Outbound files carry the original expected SHA-256 and exact length. Reopen the same immutable bytes after every restart. A matching filename, path or size is insufficient. Graph verifies the selected remote files before send and can inspect ambiguous attachment completion without blindly uploading a duplicate.

Ten MiB per file and 20 MiB aggregate are consumer validation targets, not universal SDK defaults. Gmail MIME and JSON wire budgets are distinct; Graph uses staged Outlook ranges from 3 MB and stays below its 4 MB write limit. Budgeted PHP buffers are not a total process/RSS guarantee. Arbitrary raw MIME and signed/encrypted MIME reconstruction remain unsupported. The detailed [submission limits](messages.md#limits-and-unsupported-input) and [Graph limits](messages.md#graph-limits-and-permissions) describe the implemented boundaries.

## Handling Notifications

Validate the raw provider request using the retained registration and selected grant. Gmail requires a trusted cryptographic host verifier. Graph supports basic notifications, including legitimate identity metadata; encrypted rich mode is unsupported. Neither an unverified boolean nor a successful token exchange proves callback authority.

Retain a receipt before acknowledging a notification, then reconcile from the last committed checkpoint. Duplicates, older hints, missed delivery, removed registrations and reauthorization signals require host handling. Renew before expiry and retain recovery scans even when live mode is required. See [notification registration and validation](events.md).

## Upgrading an Existing Consumer

Keep configured calls, the `google-workspace` alias, existing constructors, resource resolvers and raw query contracts. Do not promote historical OAuth rows by matching an email or subject. Unsafe legacy enrollment now fails closed, serialized credentials are hidden, and raw authenticated destinations/redirects are restricted. The [upgrade guide](upgrade-guide.md) explains these deliberate security changes and reconnect requirements.

Validate the actual consumer in an isolated source snapshot with synthetic configuration, provider HTTP fakes and isolated SQLite. Package fixtures alone cannot prove its boot, migration, authorization and mapping path. The [compatibility receipt](plans/mailbox-provider-sdk/compatibility.md) records the local candidate, dependency graphs and remaining limits; it does not migrate either application or publish a package.

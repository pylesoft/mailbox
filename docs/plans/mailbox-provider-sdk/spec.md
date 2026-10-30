# Mailbox provider SDK specification

**Status:** ready-for-agent

**Resolution:** open

Synthesized from the design confirmed by Ian on 2026-10-29. This specification covers the Mailbox package; implementation, dependency installation, consumer migration and publication require separate authorization.

## Problem Statement

Applications using Mailbox need reliable access to Gmail and Microsoft email without implementing provider authentication, message parsing, synchronization and submission separately in each application. AI Clerk needs that shared provider behavior while retaining its existing conversation data, private editor drafts and collaboration rules. Ngest needs its current integration and stored data to keep working.

The released package provides useful read, folder, attachment and configured-authentication capabilities, but its user OAuth enrollment is disconnected from runtime requests and refresh. Connection records do not explicitly select the credentials used by a request. Existing parsing, continuation and synchronization behavior can lose information or report unfinished work as complete. There is no public outbound submission or provider-notification capability, and generic request retries are unsafe for a send that the provider may already have accepted.

These gaps make a direct Gmail migration risky and leave substantial provider work to add Microsoft support. The shared package must preserve explicit authorization, complete content, resumable progress and honest send outcomes without becoming another application's persistence or workflow engine.

## Solution

Provide a reusable provider SDK supporting personal Gmail, Google Workspace, Microsoft 365 work/school accounts, Outlook.com/Hotmail and authorized Microsoft shared mailboxes. Preserve existing service-account and application-permission integrations alongside delegated user authorization.

Applications explicitly select the connection, authorization grant and target mailbox. Mailbox handles provider authentication and refresh, bounded reads and synchronization, complete content acquisition, prepared-message serialization, staged submission, evidence inspection and basic change-notification operations. Applications supply storage and lifecycle adapters and retain authorization, durable operation state, scheduling, local projections and browser updates.

The core works without package database tables. Existing Eloquent and configured-driver integrations remain supported. New capabilities are additive, and incomplete work, unsupported capabilities, missing prerequisites and uncertain provider acceptance remain explicit.

## User Stories

### Provider access and application integration

1. As an application developer, I want to access personal Gmail mailboxes through delegated authorization, so that individual users can connect their email.
2. As an application developer, I want to access Google Workspace mailboxes through delegated authorization, so that business users can connect their own email.
3. As an existing consumer, I want Google Workspace service-account delegation to remain supported, so that background integrations keep working.
4. As an application developer, I want to access Microsoft 365 work/school mailboxes through the selected supported authorization mode, so that organizational email can use the same SDK.
5. As an application developer, I want to access Outlook.com and Hotmail mailboxes through delegated authorization, so that personal Microsoft accounts are explicitly supported.
6. As an application developer, I want to access authorized Microsoft shared mailboxes, so that organizational inboxes can participate without pretending the accessing identity owns the mailbox.
7. As an application developer, I want to use core provider operations without Mailbox tables, so that my application's existing data model stays authoritative.
8. As an existing consumer, I want the Eloquent integration to remain available, so that I can upgrade without replacing my persistence model.
9. As an application developer, I want to provide storage and lifecycle adapters, so that provider behavior can be shared without importing my application's ownership models.
10. As an application developer, I want to retain authorization, durable jobs, local projections and browser updates in my application, so that the package does not duplicate my workflow engine.
11. As a custom-driver maintainer, I want new provider capabilities to be optional, so that an existing driver remains usable without implementing unrelated methods.
12. As an application developer, I want explicit capability and setup results, so that my application can present supported behavior accurately.

### Authorization grants and lifecycle

13. As an application developer, I want each connection to select its authorization grant explicitly, so that a mailbox address cannot accidentally choose credentials.
14. As an application developer, I want separate host contexts to authorize the same external account independently, so that reconnecting one context cannot overwrite another context's grant.
15. As an application developer, I want one grant to authorize multiple permitted mailboxes, so that application-permission and shared-mailbox access have an honest model.
16. As an application developer, I want OAuth initiation and callback binding to invoke host authorization checks, so that optional package routes do not imply permission to manage a connection.
17. As an account owner, I want an OAuth attempt to be expiring, single-use and bound to its initiating context, with safe success and error return destinations, so that a callback cannot attach access elsewhere or redirect me to an untrusted site.
18. As an application developer, I want delegated grants to power real provider requests and refresh, so that successful enrollment leads to usable mailbox access.
19. As an account owner, I want an omitted refresh token preserved only for the same explicitly selected grant and verified provider subject, tenant and OAuth client identity, with returned rotations stored safely, so that another identity cannot inherit my authorization.
20. As an application developer, I want competing delegated refresh attempts across processes and mailbox targets to share one atomic lock per grant, then reload and conditionally replace credentials, so that concurrent exchanges and stale writes cannot corrupt authorization state.
21. As an application developer, I want ordinary token refresh to preserve the connection generation, so that valid pending work is not invalidated unnecessarily.
22. As an account owner, I want reconnect and disconnect to invalidate older work and cached authorization, so that delayed requests cannot restore or continue superseded access.
23. As an application developer, I want transient refresh failures to differ from lost authorization, so that my application can retry or request reconnect appropriately.
24. As an application developer, I want rejected delegated authorization to fail explicitly without falling back to a broader application grant, so that access never escalates silently.

### Transport correctness and trustworthy evidence

25. As an application developer, I want opaque continuation queries to reach the provider unchanged, so that pagination and delta tokens remain usable.
26. As an application developer, I want checkpoints bound to the selected provider, authorization identity, mailbox and scope, so that progress cannot be reused against another target.
27. As an account owner, I want authenticated requests and redirects restricted to trusted destinations, so that credentials are not forwarded to an unexpected origin.
28. As an application developer, I want preauthenticated upload requests to omit the Graph bearer token, so that upload authorization remains separate from mailbox authorization.
29. As an application developer, I want provider status, headers and identities preserved as evidence, so that an empty response does not erase a meaningful acknowledgement.
30. As an application developer, I want safe reads to retain useful transient retry behavior, so that improving submission safety does not unnecessarily weaken existing reads.
31. As a sender, I want an uncertain irreversible submission never to be repeated automatically, so that a timeout or provider error cannot produce duplicate email.
32. As an application developer, I want new provider primitives to return retry or uncertainty information without releasing my enclosing job, so that my application controls durable retries.
33. As an application developer, I want actual response and content limits enforced, so that one oversized provider response cannot bypass a nominal page or request budget.
34. As an application developer, I want interrupted or budget-limited work reported explicitly, so that partial results are not mistaken for completion.
35. As an application developer, I want malformed, truncated or incomplete provider responses to remain visible failures or incomplete work, so that missing information is not converted into empty success.
36. As an account owner, I want credentials, private upload URLs and recovery secrets excluded from public results and unredacted logs, so that diagnostics do not expose access material.

### Synchronization and conversation scope

37. As an application developer, I want to select mailbox and folder scope explicitly, so that synchronization covers the resources my application intends to import.
38. As an application developer, I want import cutoffs and local retention to remain application policy, so that the SDK does not impose another consumer's data lifecycle.
39. As an application developer, I want bounded pages of changes and separate continuations, so that jobs can retain progress and resume unfinished work.
40. As an application developer, I want a completed checkpoint only when the synchronization round is complete, so that an incomplete scan cannot skip future changes.
41. As an application developer, I want a safe Gmail baseline captured before the initial scan, so that messages arriving during import are still reconciled.
42. As an application developer, I want a capped listing, repeated token or missing continuation handled explicitly, so that a partial initial import is never reported as complete.
43. As an application developer, I want Graph progress tracked per selected folder, so that a folder delta token is not presented as a whole-mailbox checkpoint.
44. As an application developer, I want changes exposed as upserts and removal evidence, so that provider heuristics are not mistaken for reliable created-versus-updated classifications.
45. As an application developer, I want expired checkpoints to request a scoped reset, so that recovery retains the original import policy and existing local history.
46. As an application developer, I want to retain page work durably before promoting its continuation, so that interruption does not lose uncommitted changes.
47. As an application developer, I want duplicate items, empty pages and resumed work handled without false completion, so that reconciliation can be repeated safely.
48. As an application developer, I want removal from one remote folder distinguished from verified message deletion, so that my application does not erase retained conversation history accidentally.

### Complete content and file access

49. As an application developer, I want complete conversation content within an explicit scope, so that selected conversations retain useful older context.
50. As an application developer, I want unpaged or oversized Gmail threads to return explicit limits or unfinished work, so that a request count alone does not promise bounded conversation payloads.
51. As an application developer, I want received address lists, quoted names, comments and groups parsed correctly, so that sender and recipient data survive normalization.
52. As an application developer, I want encoded headers and supported text encodings decoded correctly, so that international names, subjects and message bodies remain readable.
53. As an application developer, I want externalized Gmail text hydrated as body content, so that a missing inline body is not silently replaced by an empty message.
54. As an application developer, I want text-body parts distinguished from named and inline files, so that body fragments do not become false attachments.
55. As an application developer, I want invalid or truncated content rejected or marked incomplete, so that corrupt bytes do not masquerade as a valid empty file.
56. As an application developer, I want file access to enforce byte and memory budgets through the real transport and decoding path, so that a stream-shaped interface does not hide full buffering.
57. As an application developer, I want private storage, file authorization and allowed types to remain application policy, so that the SDK does not impose AI Clerk's file rules on other consumers.
58. As an application developer, I want the Graph upload-session path needed for 10 MiB file cases, so that the existing consumer file contract can be validated without silently lowering its limits.
59. As an application developer, I want file manifests to include stable identity, byte length, media type and inline metadata with reopenable or seekable bytes bound to the exact immutable content across opens and resumes, so that preparation cannot combine bytes from different file versions.
60. As an application developer, I want acknowledged upload progress and attachment identity exposed, so that ambiguous attachment completion can be inspected before work is repeated.

### Prepared messages and submission outcomes

61. As an application developer, I want to provide a structured prepared message with explicit sender, recipients, content and context, so that the SDK handles provider serialization without choosing application content.
62. As an application developer, I want package-owned parsing and encoding hidden behind SDK interfaces, so that callers do not depend on third-party message objects.
63. As a sender, I want explicit To, Cc and Bcc choices preserved without inheriting historical Bcc, so that messages reach only their intended recipients.
64. As a sender, I want supported names, subjects, headers and files encoded safely, so that international content works without header injection or silent field loss.
65. As a sender, I want private editor drafts to remain local until the application explicitly starts submission, so that background editing does not create external drafts.
66. As an application developer, I want Graph draft creation and file preparation separated from the send request, so that preparation identities and progress can be retained before an irreversible operation.
67. As a sender, I want Gmail replies to preserve the intended thread and reply headers, so that the provider associates them with the correct conversation.
68. As a sender, I want forward preparation to preserve the chosen content and conversation intent, so that forwarding does not silently inherit an unintended reply or file selection.
69. As an application developer, I want Graph preparation to expose its immutable draft identity, so that later send and inspection operations refer to the same provider artifact.
70. As a sender, I want native Graph reply and forward drafts normalized to my explicit recipients and files, so that inherited provider defaults cannot change the intended submission.
71. As an application developer, I want preparation limited to the new submission draft, so that preparing a reply or forward never modifies the original message.
72. As an application developer, I want lost draft or attachment acknowledgements distinguished from uncertain send acknowledgements, so that recovery does not confuse a possible draft with a possibly accepted email.
73. As an application developer, I want an empty Graph 202 response represented as acceptance evidence without an invented message or delivery result, so that applications do not rely on false provider parity.
74. As an application developer, I want original correlation and provider identities retained for evidence inspection, so that uncertainty can be investigated without another send.
75. As a sender, I want delayed, missing or mismatched sent-copy evidence to leave the outcome unresolved, so that absence is not mistaken for permission to resend.
76. As an application developer, I want unsupported raw-MIME sizes or transformations reported explicitly, so that the SDK does not silently rewrite arbitrary oversized, signed or encrypted messages.

### Change notifications and live mode

77. As an application developer, I want Gmail watch registration and stopping exposed as provider operations, so that my application can manage incoming-mail discovery.
78. As an application developer, I want Graph subscription creation, renewal and removal exposed with expiry, so that my application can schedule registration maintenance.
79. As an application developer, I want authenticated Pub/Sub validation to require a trusted host verifier on the raw request, so that unverified flags or decoded token claims cannot authorize notifications.
80. As an application developer, I want the SDK to check verified principal, audience and trusted registration/account binding, so that a valid transport identity cannot route changes to the wrong mailbox.
81. As an application developer, I want basic Graph handshakes, client-state secrets and subscription/resource identities validated, so that untrusted callbacks do not become usable change signals.
82. As an application developer, I want basic Graph notifications accepted with legitimate identity metadata while rich encrypted mode is rejected explicitly, so that the supported notification mode is unambiguous.
83. As an application developer, I want notification duplication and ordering treated as reconciliation inputs, so that callbacks do not publish messages directly or advance checkpoints themselves.
84. As an application developer, I want missed, removed and reauthorization lifecycle signals surfaced, so that my application can reconcile and restore registrations.
85. As an application developer, I want durable receipt storage, acknowledgement and dispatch to remain under application control, so that provider notifications can follow my application's recovery guarantees.
86. As an application operator, I want requested live shared-mailbox operation to require supported push configuration, so that a delta-only connection is not presented as live.
87. As an application developer, I want one explicit application-permission grant for live shared-mailbox read, send and watch operations, so that the SDK never combines delegated and broader application authority silently.
88. As an existing consumer, I want delta-only access and catch-up reconciliation to remain available, so that applications without live mode keep working and live applications can recover missed notifications.

### Compatibility, documentation and release evidence

89. As an existing consumer, I want configured driver calls, aliases, constructors, resource resolution and raw-client usage preserved, so that upgrading does not unexpectedly break my integration.
90. As a custom-driver maintainer, I want extension stubs, resolver replacement and documented callback/result contracts kept compatible where safe, so that new capabilities do not force an unrelated rewrite.
91. As an application operator, I want ambiguous or unowned legacy OAuth records preserved but excluded from new delegated use until explicitly assigned or reconnected, so that upgrading cannot guess or destroy lost ownership.
92. As an application developer, I want dependency requirements declared and verified for production-only installation, so that behavior does not depend on development-only transitive packages.
93. As a package maintainer, I want behavior tested through real SDK interfaces with provider HTTP faked and isolated persistence, so that regressions in transport, mapping and lifecycle are detected.
94. As a package maintainer, I want supported PHP and Laravel combinations, lowest dependencies and existing consumer contracts checked, so that broader support is demonstrated rather than inferred.
95. As an application developer, I want accurate capability, configuration, upgrade and limitation documentation, so that I can integrate the package without relying on features that do not exist.
96. As a package maintainer, I want live account, notification and large-file acceptance recorded separately from fixture success, so that unsupported or unverified provider combinations are never published as proven support.

## Implementation Decisions

### Package and host responsibilities

- The SDK owns provider exchange/refresh, request handling, content parsing and protocol encoding, bounded reads/changes, submission primitives, evidence inspection, registration and semantic notification validation.
- The host owns user/Team authorization, selected connection ownership, storage adapters, durable outbox/receipt state, scheduling/jobs, local projections, retention, sanitization, file access policy and browser events. No host-specific models or workflow engine enter the package.
- Core operations work without package database tables. The optional Eloquent integration remains available; existing database integration and migrations remain compatible by default where required, while SDK-only usage can explicitly disable them.
- Extend existing drivers, resources, resolvers, clients and DTO mapping at their current seams. Add optional capability interfaces for genuinely new behavior; do not add mandatory methods to existing custom-driver contracts or build a parallel manager hierarchy.

### Explicit grants and safe lifecycle

- New connection-bound runtime selection receives an explicit authorization identity, reconnect/disconnect generation and mailbox target. One grant may authorize multiple mailboxes; different host contexts may hold separate grants for the same external account. Driver name or mailbox address alone cannot select a delegated grant.
- Include provider identity, tenant/issuer and OAuth client registration where relevant. Isolate runtime instances and caches by the selected authorization context; do not mutate global driver configuration per request or select the first active token.
- Inject a narrow token-provider seam into the existing clients. Credential storage supports loading and guarded replacement without becoming a general credential-management framework.
- Coordinate new delegated refresh with one shared atomic lock per grant across processes and mailbox targets, using Laravel's existing cache-lock contract and a host-configured shared store. A per-process array store or mailbox-only lock is insufficient. Failed, unavailable or contended coordination returns a retryable failure without a refresh exchange; existing configured authentication modes remain unchanged where promised.
- Reload under the lock and save only when expected grant identity, lifecycle generation and refresh revision still match. In callbacks and ordinary refresh, preserve an omitted refresh token only for the same explicitly selected grant and verified provider subject, tenant/issuer and OAuth client identity. A different identity never inherits the old token and must report missing renewable authorization when none is returned. Atomically store rotations and reject stale commits after reconnect or disconnect.
- Normal refresh does not advance the reconnect/disconnect generation. Distinguish transient failures from lost authorization; a rejected grant never falls back to broader configured authority.
- Host guards run before provider phases and credential commits. OAuth initiation and callback state are authorized by the host, bound to the current context, expiring and single-use. An arbitrary user reference or enabled route is not ownership proof.
- Validate success and error callback return destinations as local application paths or explicitly trusted absolute URLs. Reject scheme-relative destinations and paths that browser normalization turns external, including backslashes and control characters. Preserve safe callback/result shapes and document the security correction.
- Preserve legacy records with ambiguous ownership. Add explicit binding/migration handling without deletion, automatic reassignment or merging across contexts. Document any security correction that must fail previously unsafe behavior despite general compatibility guarantees.

### Transport and bounded progress

- First reproduce and correct opaque Graph continuation-query handling through the real Guzzle request path. Preserve returned token bytes rather than replacing their query with an empty request option.
- Validate checkpoint provider, identity, mailbox/scope and format version. Constrain authenticated destinations and redirect behavior to trusted provider resources. Accept preauthenticated upload destinations only through their explicit provider flow and never attach the Graph bearer token to them.
- Preserve meaningful response status, headers and identity evidence. Do not collapse all empty responses into an indistinguishable result.
- Separate replay-safe reads from irreversible or otherwise ambiguous writes. Retain useful existing read retries; new submission primitives never automatically repeat a possibly accepted send or release the host's enclosing job.
- Expose bounded work with explicit unfinished state. Enforce response/content limits through the actual HTTP/decoding path, not merely the number of scheduled requests.
- Treat private credentials, upload URLs and recovery state as secrets. Public/debug representations and logs must redact them; durable private recovery storage belongs to the host.

### Synchronization and complete reads

- The host selects mailbox/folder scope, import cutoffs and retention policy. New paged interfaces return upserts, removal evidence, continuation, completed checkpoint or reset requirement without claiming identical provider semantics.
- A continuation represents unfinished work. The host retains returned work durably before promoting its continuation and promotes a completed checkpoint only after the round completes. Missing/cyclic progress, caps, unfinished content and interruptions cannot signal completion.
- Capture a safe Gmail baseline before initial listing. Preserve the original scope/cutoff during expired-checkpoint recovery. Reproduce both the page-ceiling completion defect and concurrent-arrival risk before accepting the correction.
- Graph delta is per folder; an explicit mailbox-wide scope needs folder enumeration and separate progress. Do not infer reliable created-versus-updated classification from etags. Folder removal does not authorize deletion of retained local history.
- Complete conversations are complete within their declared scope. Gmail selection by activity/cutoff preserves older thread context; Graph reports the mailbox/folders covered. Gmail whole-thread reads are not natively paginated: enforce actual response limits and return explicit incomplete/unsupported work when a response or hydration cannot finish within bounds.
- Received address lists/groups, quoted and encoded names, headers, multipart bodies and externalized text use proper parsing. Distinguish body parts from named/inline files. Malformed or truncated bytes must not become a valid empty body/file.
- Wire decoding, content completeness and bounded file I/O belong to the SDK. Application file allowlists, local private storage, authorization and presentation remain host policy.

### Prepared messages, files and submission evidence

- The primary outbound input is a structured prepared message with explicit sender, To/Cc/Bcc, body/headers, reply/forward context and caller correlation. The host chooses content and recipient policy; the SDK serializes provider protocol without exposing third-party message objects as its public contract.
- Files have stable caller identity, name, media type, byte length, inline/Content-ID metadata where applicable, and reopenable or seekable bytes. Host limits remain configurable; 10 MiB per file and 20 MiB total are existing-consumer parity targets, not universal package defaults.
- Bind each prepared file to the exact immutable bytes across every open and resume, using host-guaranteed immutable content or a verified content fingerprint strategy. Identity, path, name and length alone cannot establish this binding. If binding fails or cannot be established, stop before any additional upload or send. Storage and checksum ownership remain with the host; no new digest type or storage framework is required.
- Use ZBateson Mail MIME Parser for inbound parsing, Symfony Mime for typed outbound encoding and an explicit Egulias validator dependency. Candidate constraints reviewed during design are respectively `^4.0.8`, `^7.4.22 || ^8.1.10`, and `^4.0.4`. Recheck maintained versions, advisories, runtime requirements and Composer resolution before installation; the dependency graph and its additional helpers are accepted design costs, not installed changes.
- Encoding rejects header injection and preserves intended explicit Bcc delivery, accounting for serializers that normally remove Bcc headers. Do not inherit historical Bcc. MIME iteration alone does not prove bounded Gmail JSON/base64url submission; verify the actual memory/byte behavior and use private owned temporary spooling where necessary.
- Private editor drafts remain local. Provider submission preparation begins only after an explicit host submission request and exposes its identities/progress before irreversible send. Temporary provider drafts may be visible externally and may remain after a failed attempt; no automatic background draft mirroring is introduced.
- Gmail serialization preserves caller correlation and the correct reply/forward headers and thread context. Return validated acknowledgement identity evidence without fabricating a fully fetched message.
- Graph preparation uses an immutable draft identity, native reply/forward context where appropriate, explicit caller recipient/content fields and the selected file manifest. Replace inherited recipients and unselected files only on the new draft; never modify the original message. Verify the prepared intent before send, allowing documented harmless protocol normalization rather than promising byte-identical round trips.
- Respect Graph's 4 MB write-request limit. Use the size-appropriate attachment operation, including Outlook upload sessions for large files, bounded sequential byte ranges and provider acknowledgement evidence. Do not assume OneDrive session-recovery or expiry behavior applies to Outlook.
- Keep draft creation, attachment preparation, irreversible send and evidence inspection observable as separate phases. A lost draft acknowledgement means preparation uncertainty, not evidence of a send; an ambiguous attachment acknowledgement requires inspection before another attachment is added. A lost send acknowledgement means possible acceptance and never authorizes automatic resubmission.
- Graph's empty 202 response establishes acceptance evidence without a message ID or delivery proof. Preserve the original preparation/correlation identity for inspection. Delayed, absent or mismatched sent-copy evidence remains inconclusive.
- Raw MIME is a limited provider capability. Arbitrary oversized MIME parsing/rebuilding, signed/encrypted MIME rewriting and silent replacement of files by external links are outside this release.

### Change notifications and account capabilities

- Expose Gmail watch/stop and Graph subscription creation, renewal and removal with provider identity, expiry and explicit capability/setup results. The host owns endpoints, authentication configuration, durable receipt acknowledgement, scheduling, dispatch and reconciliation.
- Authenticated Pub/Sub delivery requires a configured trusted host verifier invoked on the raw request for signature, issuer, expiry, audience and service-account identity. The SDK checks verified claims, trusted registration/subscription mapping and Gmail account binding. Missing/rejected verification fails closed; a boolean assertion or unverified JWT is insufficient. Topic binding comes from trusted configuration, not an assumed notification field.
- Support basic Graph notifications with `includeResourceData=false`. Validate handshakes, secret client state, subscription, tenant/resource and lifecycle binding. Legitimate identity metadata may still appear in a basic payload. Rich encrypted notifications and certificate/decryption management are unsupported capabilities.
- Notifications trigger reconciliation from the last committed checkpoint. Duplicate/out-of-order signals do not publish provider content directly, and their history IDs do not promote synchronization progress. Surface reauthorization, missed and removed-subscription conditions; retain registration renewal and recovery scans even for live mode.
- Personal Gmail uses delegated access; Workspace supports delegated access and existing service-account delegation. Microsoft own-mailbox accounts use their explicitly supported delegated/application mode; Outlook.com/Hotmail uses delegated access with suitable app registration.
- Requested live Microsoft shared-mailbox operation requires supported push setup and one explicitly selected application-permission grant for read/send/watch. Delegated shared access remains available for delta-only consumers. No implicit authority elevation or mixed delegated/application grant mode is added.
- Validate actual scope, consent and mailbox permissions instead of treating token acquisition as proof of every capability. Preserve delta-only use for consumers that do not request live mode.

### Compatibility and delivery order

- Preserve configured drivers, legacy resource resolution, the Google Workspace alias, constructors, raw-client access, custom creators/resolvers, stubs and callback/result fields where safe. Introduce explicit entry points for grant-bound behavior.
- Implement transport corrections first; then grant runtime and optional storage; content/file parsing and encoding; bounded synchronization; provider-specific submission; notifications; and cross-cutting compatibility/documentation/release gates. Content work can proceed alongside token/storage work after transport stabilizes. Gmail and Graph submission can proceed independently once shared contracts are stable.
- Keep one writer for shared contracts. Each unit includes its own behavior tests; the final compatibility gate does not defer testing until the end.
- Document current behavior honestly, including the absence of implemented transparent delegated refresh until that runtime is complete and tested. Upgrade guidance identifies security exceptions, ambiguous grant handling and changed prerequisites.

## Testing Decisions

The test boundaries were explicitly confirmed in design decision Q13. This specification reuses them; it introduces no new testing seam requiring another interview.

### Primary seam

- Exercise the same public Mailbox provider interfaces used by consumers: configured or explicitly selected runtime, target mailbox, resources and optional capabilities. Use the real authentication, transport, parsing, synchronization and submission implementation behind that interface.
- Fake external provider HTTP using existing Guzzle MockHandler/request-history patterns. Assert observable requests, returned results, retained state, errors and evidence. Do not replace the client method whose transport or mapping behavior is being verified.
- Substitute host-owned credential storage/lifecycle and trusted-verifier ports only at the agreed application seam. Verify that the SDK invokes them and fails closed correctly. Host cryptographic verification and live delivery remain separate integration gates.
- Test optional Eloquent persistence with isolated SQLite and real persistence semantics. SDK-only cases must prove the core works without querying or migrating package tables; a test base that automatically migrates them is insufficient evidence.

### What makes a good test

- A test protects external behavior or a meaningful failure mode and survives internal refactoring. Prefer a small number of complete consumer scenarios over tests of every private helper, class name or implementation step.
- Reproduce confirmed defects before fixing them. Treat statically traced defects and plausible races as findings to reproduce, not previously executed test results.
- Assert both the intended outcome and important forbidden effects: no cross-connection credential use, stale overwrite, false completion, lost content, duplicate send, duplicated file, unexpected job release or credential disclosure.
- Deterministic controlled HTTP responses and state changes during I/O should exercise refresh, reconnect, source-change and interruption races. Do not weaken assertions or inflate timing limits to manufacture a passing result.
- No browser test or application UI implementation is needed for these package contracts. Tests must not install AI Clerk or Ngest as production package dependencies.

### Coverage and prior art

- Extend existing driver-resolution, token-manager, OAuth callback and optional model-persistence tests to cover real delegated requests, explicit grant selection, callback ownership/state, token omission/rotation, concurrent refresh, cache isolation and stale-generation commits.
- For both providers, test success and error callback returns with `//evil.example`, `/\evil.example`, control-character variants, an untrusted absolute URL, a valid local path and an allowed configured host. Reproduce the existing leading-slash bypass before implementing its correction.
- Cover omitted refresh tokens for the same verified grant identity and negative account/subject, tenant and OAuth-client changes, in callbacks and ordinary refresh. The current callbacks write null on omission; unsafe cross-identity preservation is a risk the new behavior must prevent, not an existing preservation behavior.
- Exercise one grant used by multiple mailboxes, lock contention and failed/unavailable coordination: no refresh exchange occurs without the shared atomic lock, and guarded commits still reject stale revisions/generations. In-memory test doubles do not prove cross-process lock semantics; verify the host's shared-store deployment prerequisite separately.
- Extend existing shared transport and provider-client tests with real Guzzle histories for exact continuation URLs, trusted destinations, redacted errors, safe read retries, write uncertainty and preservation of empty-response status/headers. Include preauthenticated uploads without bearer authorization.
- Extend existing Gmail/Graph query and delta tests for page caps, repeated/missing tokens, empty pages, interruption/replay, scoped resets, baseline-before-scan races, per-folder Graph progress and completed-versus-incomplete results. Mocking only the high-level client cannot detect query stripping.
- Extend existing DTO, message/body and attachment tests for quoted/grouped/encoded addresses, externalized text, multipart bodies, body/file classification, malformed base64, declared versus actual lengths, inline metadata, peak-memory limits and owned temporary-resource cleanup.
- Add outbound contract scenarios for explicit recipients and Bcc, header safety, Gmail reply/forward correlation, Graph immutable preparation identity, native defaults replaced by caller intent, file uploads, ambiguous phase acknowledgements, delayed sent copies and one irreversible send attempt. Follow the existing AI Clerk send/reconciliation cases as consumer prior art without copying its application models or publication rules into the SDK.
- After an acknowledged upload prefix, mutate the source without changing its length and resume. Verify the immutable-byte binding rejects the change before any further upload or send; retaining the same file identity, path, name and length is insufficient.
- Add registration and notification scenarios for missing/rejected verifiers, wrong principal/audience/account, trusted subscription/topic binding, Graph handshake/client-state/resource checks, legitimate basic identity metadata, rejected rich mode, duplicates/order, expiry/renewal and missed/removed lifecycle recovery.
- Preserve existing custom-driver, alias, constructor, resource-resolution and raw-client fixtures. Add an AI Clerk-shaped host storage/lifecycle adapter contract. Run actual Ngest compatibility in an isolated consumer environment before release; package fixtures alone do not prove the consumer integration.
- Check the approved dependency requirements through production-only installation and lowest/latest resolution. Retain current PHP 8.2/8.3/8.4 coverage and exercise supported Laravel 12/13 combinations explicitly rather than assuming a latest-dependency lane covers both.

### Gates and safety

- Inspect the database target and destructive test setup before execution. Existing package tests use Testbench with SQLite memory or worker-specific SQLite files and RefreshDatabase. Never run destructive operations against an active application database.
- Run affected checks first, then relevant package Pest, PHPStan, Pint and strict Composer gates. More than ten Pest tests run in parallel with at most eight workers. Reuse valid results until a relevant change invalidates them.
- Maintain live acceptance rows for every advertised account/authentication mode, designated senders/recipients, ordinary and 10 MiB files, native reply/forward correlation, notification registration/delivery/renewal and uncertain recovery. Test the host's existing 20 MiB aggregate-file scenario without making it a universal package default.
- Missing accounts, consent, scoped application permissions, trusted Pub/Sub/public callbacks or authorized recipients leave concrete blocked rows. Shared/delegated Graph large-file behavior and native normalization remain explicit live gates. Do not silently lower limits or count fixtures as provider acceptance.
- Before claiming supported parity or publishing a release, collect immutable source identity, terminal package CI, dependency/advisory results, actual consumer compatibility and the declared live-account evidence. This specification itself runs none of those gates and authorizes no provider setup or publication.

## Out of Scope

- Implementing this specification, installing its dependencies, committing/pushing changes or publishing package releases as part of this specification-writing task.
- Migrating AI Clerk's Gmail connector, adding its Outlook UI or changing its database records. Those are subsequent consumer tasks against the proven package contract.
- Changes to Ngest's active checkout, production credentials or application data; consumer compatibility uses an isolated environment.
- A package-owned durable outbox, receipt database, scheduler, queue orchestration framework, browser realtime layer, or Team/membership/collaboration model.
- Choosing application recipient defaults, editor behavior, reply-all policy, import cutoffs, local archive/read/retention semantics, private-file authorization, sanitization or display rules.
- Background mirroring of private editor drafts, automatic resend after uncertainty, automatic deletion of ambiguous provider artifacts, or treating acceptance as delivery.
- Rich encrypted Graph notifications, certificate/decryption management, and combining delegated and application grants for one live shared-mailbox connection in this release.
- Arbitrary oversized raw-MIME rewriting, S/MIME signing/decryption or signed/encrypted MIME reconstruction, and replacing files with external links to evade provider limits.
- New IMAP/SMTP or unrelated providers, Zendesk/Twilio changes, Workflows and unrelated package cleanup.
- Unrequested infrastructure or CI-runner migration, provider account setup, public callback deployment, sandbox messages or production changes.

## Further Notes

- This specification synthesizes all 15 confirmed design decisions. The underlying plan was investigated and reviewed by three subagents; its findings were resolved before final shared-understanding confirmation. The spec preserves that scope without restarting the interview.
- Planning artifacts are the [confirmed plan](README.md), [domain glossary](../../../GLOSSARY.md), [SDK responsibilities ADR](../../adr/0001-provider-sdk-with-optional-persistence.md), [authorization identity ADR](../../adr/0002-select-authorization-explicitly.md) and [submission ADR](../../adr/0003-explicit-preparation-and-submission-evidence.md).
- Source investigation used released Mailbox v0.1.3 at `13a95cc55cc25f2bfcf536104bfc5133865a08a5`, with an identical tree to the originally inspected local branch. Those findings describe the reviewed baseline, not completed upgrade behavior.
- This is a local Markdown deliverable beside the plan, following the requested local planning workflow. The ready-for-agent designation is local metadata; no external issue or tracker label has been created or applied.
- The project uses the configured [local Markdown tracker](../../agents/issue-tracker.md) and [triage vocabulary](../../agents/triage-labels.md). Publishing a spec or ticket means writing its local file; no setup rerun or remote issue creation is required.
- Candidate dependency versions were reviewed on 2026-10-29. Their Composer resolution, current maintenance/advisory status and actual consumer compatibility must be checked again during authorized implementation. In particular, the selected Mime lower bounds reflect the [component-specific security correction](https://symfony.com/blog/about-the-security-entries-in-symfony-5-4-55-6-4-49-7-4-22-and-8-1-10); this spec does not remediate installed consumers.
- Provider distinctions come from the [Gmail synchronization guide](https://developers.google.com/workspace/gmail/api/guides/sync), [Gmail thread guidance](https://developers.google.com/workspace/gmail/api/guides/threads), [Graph sendMail contract](https://learn.microsoft.com/en-us/graph/api/user-sendmail?view=graph-rest-1.0), [Graph delta scope](https://learn.microsoft.com/en-us/graph/delta-query-messages), [Graph immutable IDs](https://learn.microsoft.com/en-us/graph/outlook-immutable-id), [Graph large-file guidance](https://learn.microsoft.com/en-us/graph/outlook-large-attachments), [Outlook notification permissions](https://learn.microsoft.com/en-us/graph/outlook-change-notifications-overview) and [authenticated Pub/Sub requirements](https://docs.cloud.google.com/pubsub/docs/authenticate-push-subscriptions). They do not establish configured accounts or successful live tests.
- Microsoft documents a known issue for large attachments in shared/delegated mailboxes. The planned account coverage is a target, not a claim that this combination is already proven. Preserve an explicit blocked result until designated validation succeeds.
- API method names, internal helper placement, release version, implementation tickets and consumer rollout are subsequent details. The behavior and compatibility contracts above are authoritative; no class layout or method spelling is preselected by this spec.

## Comments

- 2026-10-29: Ian authorized local implementation through `matt:implement-spec` with fresh ticket subagents and worktrees on the integration branch `feat/mailbox-provider-sdk`, based on `13a95cc55cc25f2bfcf536104bfc5133865a08a5`. The approved Q13 testing boundaries apply. Tickets close only after their required checks pass; the spec stays open until all required acceptance gates pass. Live-account tickets 26 and 27 remain `needs-info` pending designated access and explicit live-operation authority. No push, release, provider setup or consumer migration is authorized by this implementation run.

- Final independent review of integration `2e072897` is repaired and locally verified at runtime `bf48f73c903c0947465b880f5dc4cb9c7cdc00d6`. [Ticket 25](tickets/25-verify-consumer-upgrades-and-package-compatibility.md) and the [compatibility receipt](compatibility.md#final-review-repair) record the public red-to-green evidence, complete current suite, affected actual-lowest coverage, isolated consumer and serial TLS results. The specification remains open because the designated account, consent, callback, shared-lock and authorized live-operation gates in tickets 26/27 remain unsatisfied.

- 2026-10-29: The requested deep local self-review is complete at runtime `43f97df1b6ae5f03ca5b0b0fb6b65c3641d2a2c7`. Sixteen independent analysis packets account for all 96 stories and 202 criteria; eight reproduced defects were repaired and non-author reviewed. The [deep-review receipt](compatibility.md#deep-local-self-review) records the complete 1,479-test current suite, 748 affected PHP 8.2 tests, isolated Ngest, serial TLS/allocation checks, final static gates and the inherited storage-concurrency limitation. Live tickets 26/27 and this specification remain open; no publication or active-consumer migration occurred.

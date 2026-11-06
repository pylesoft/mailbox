# Mailbox provider SDK upgrade

This plan prepares `pylesoft/mailbox` for Gmail and Microsoft email integrations while preserving its existing consumers. It covers package changes and package verification. AI Clerk's connector migration and Outlook UI remain separate implementation work.

The [implementation specification](spec.md) records the confirmed behavior as user stories, implementation decisions, testing decisions and explicit exclusions.

**Status:** design confirmed by Ian on 2026-10-29 after all 15 decisions and independent plan review. Ian subsequently authorized local implementation through `matt:implement-spec` with subagents on `feat/mailbox-provider-sdk`. Publication, provider setup, live operations and consumer migration remain separate actions; open acceptance gates do not establish supported parity.

## Related consumer plan

The [AI Clerk integration plan](C:/Users/hello/.codex/worktrees/inbox-collaboration/ai-clerk/docs/plans/mailbox-integration/README.md) covers adding Microsoft email support, then migrating Gmail through the SDK while retaining AI Clerk's own application state. It records the host's authorization, UI, demo, migration and live-acceptance decisions separately; this package's SDK scope and ticket dependencies remain unchanged. The host plan does not authorize implementation or consumer migration execution.

## Reviewed baseline

- Released package: `v0.1.3`, commit `13a95cc55cc25f2bfcf536104bfc5133865a08a5`.
- Inspected local source began at `69c2690878afedf6f688b7133814ccf66bf1798f`; its tree is identical to the release tree (`2b8e18565e95fbc3627639115164f4d24e50ea70`).
- AI Clerk consumer reference: `a9e233cf935dea91b0636e23d09a0ec8d29475ce`.
- Ngest's Laravel consumer locks Mailbox `v0.1.3` and uses configured drivers, persisted connections, `forMailbox()`, raw-client paths, and connection probes.
- Findings are based on source tracing and official provider documentation. The identified defects still need focused reproductions during implementation. Existing release CI is evidence for that release, not for this proposed upgrade.

## Accepted decisions

| Decision | Agreed direction |
| --- | --- |
| Q1: account coverage | Personal Gmail, Google Workspace, Microsoft 365 work/school, Outlook.com/Hotmail, and authorized Microsoft shared mailboxes. Preserve service-account and application-permission modes. |
| Q2: persistence | Core provider behavior works without package tables; existing Eloquent integration remains available. |
| Q3: compatibility | Preserve existing consumers, configuration, stored identities and data. New behavior is explicit; security corrections may require documented exceptions. |
| Q4: provider drafts | Graph submission may create provider-side drafts after an explicit submission request. Local editor drafts are never mirrored automatically. Failed preparation may leave an externally visible draft. |
| Q5: responsibilities | SDK owns provider exchange/refresh and bounded provider primitives. Host owns authorization, storage adapters, durable sends/receipts, scheduling/jobs, projections and browser events. |
| Q6: live mode | A requested live shared-mailbox mode requires supported push configuration. Delta-only access remains usable by other consumers; all modes retain catch-up reconciliation. |
| Q7: identity | Each connection selects its grant explicitly; one grant may cover multiple mailboxes, and separate host contexts may authorize the same external account independently. |
| Q8: composition | Caller supplies the composed message/MIME, explicit recipients and reply/forward context. SDK owns provider submission/threading correctness, not editor behavior or recipient defaults. |
| Q9: synchronization policy | Host selects mailbox/folder scope, cutoffs and local retention. SDK returns bounded work and complete content within the requested scope, with distinct continuation/completion states. |
| Q10: legacy grants | Preserve ambiguous/unowned records, but require explicit assignment or reconnect before the new delegated runtime uses them. Never infer lost ownership. |
| Q11: files | Include the Graph upload-session path needed for the host's current 10 MiB-per-file cases. Host limits remain configurable. Shared/delegated large-file claims require live validation. |
| Q12: prepared-message format | Primary input is a structured prepared message with explicit fields and separate file sources. Raw MIME is a limited provider capability; arbitrary oversized/signed MIME rewriting is outside this plan. |
| Q13: verification | Test real provider-client/mapping behavior with HTTP fakes, optional SQLite persistence, legacy/custom-driver and host-adapter compatibility. Live-account gates remain separate from fixtures. |
| Q14: live shared-mailbox grant | Use one explicitly selected application-permission grant for read/send/watch in requested live mode. Delegated shared access remains available for delta-only consumers. No mixed-grant mode in this release. |
| Q15: dependencies | Use ZBateson Mail MIME Parser for inbound parsing, Symfony Mime for typed outbound serialization, and explicit Egulias validation. Keep all three behind SDK interfaces; resolve and verify supported versions before installation. |

See the [glossary](../../../GLOSSARY.md), [SDK responsibilities ADR](../../adr/0001-provider-sdk-with-optional-persistence.md), [authorization identity ADR](../../adr/0002-select-authorization-explicitly.md), and [submission ADR](../../adr/0003-explicit-preparation-and-submission-evidence.md).

## Current implementation and required corrections

| Area | Current evidence | Required outcome |
| --- | --- | --- |
| Delegated authentication | `GmailDriver` creates a service-account-only token manager; `MsGraphDriver` creates a client-credentials manager. OAuth callbacks persist user grants but no runtime reads/refreshes them. | A selected grant powers actual requests, refresh and revocation handling through the same provider interface. |
| Connection resolution | `MailboxManager::forMailbox()` selects by driver name, not connection credentials. Named driver instances are cached. | New explicit connection resolution cannot leak cached credentials across connections or mutate global configuration per request. Legacy configured driver resolution remains supported. |
| OAuth ownership | Callback lookup is global by provider and subject/email, and can reassign `mailbox_connection_id`. Missing refresh tokens replace stored ones with null. | Connection-scoped grant selection, guarded writes, omission preservation only for the same selected grant and verified subject/tenant/client identity, safe rotation, and an explicit legacy-grant upgrade policy. |
| OAuth return destinations | Both user OAuth services accept any leading-slash `return_to` before checking allowed hosts, including `//attacker.example`. This is a statically traced defect requiring implementation reproduction. | Success and error callbacks accept local application paths or explicitly trusted absolute URLs, rejecting scheme-relative and browser-normalized external destinations. |
| Optional persistence | The provider unconditionally loads package migrations. | SDK-only operation performs no package database queries or migrations; the legacy integration remains available by default where required for compatibility. |
| Graph continuation transport | `PerformsApiRequests::get()` always passes a `query` option. Installed Guzzle replaces the query already present in next/delta URLs, including with an empty query. | Preserve opaque provider continuation queries exactly, and test the real HTTP client path. This is statically traced and needs a regression reproduction. |
| Continuation trust | Absolute URLs are accepted by the shared transport and receive provider authorization headers. | Only trusted provider origins and the selected mailbox/scope may receive credentials. Checkpoints are opaque trusted state, not arbitrary caller URLs. |
| Gmail initial completion | A query can stop at its page ceiling and return only a collection; initial delta then reports completion and reads a newer history ID. | Expose incomplete work and a resumable continuation. Capture a safe initial baseline before the scan; reproduce concurrent-arrival and page-limit cases. |
| Sync granularity | Graph and Gmail delta helpers aggregate pages; Graph-created versus updated classification is heuristic. | New bounded pages expose upserts, removals, continuation, completed checkpoint, and reset requirement without claiming false provider parity. |
| Complete content | Gmail inline body extraction omits externalized body parts, which the attachment collector may classify as files. There is no conversation-read interface. | Complete body and conversation acquisition, with explicit unfinished work and body/file distinction. Preserve older context of selected conversations. |
| Address parsing | Gmail DTO address lists split on commas. | Correct received-address parsing preserves quoted names, comments, and explicit recipient values. Composition policy stays with the host. |
| Submission | Public resource contracts have no outbound operations. Shared transport can retry timeouts and 5xx or release an enclosing job. | Optional outbound capability with operation-specific replay rules, explicit evidence, and no automatic resend or job release after possible acceptance. |
| Notifications | Delta helpers and local PHP events exist; provider registration/renewal and notification lifecycle handling do not. | Provider watch/subscription primitives and validated notifications, with host-owned endpoints, durable receipt handling, scheduling and reconciliation. |

Relevant source: `src/MailboxManager.php`, `src/MailboxServiceProvider.php`, `src/Drivers/Concerns/PerformsApiRequests.php`, both provider token managers/clients/delta helpers, `src/Application/Services/OAuth/`, and `src/Domain/DTOs/MessageDto.php`.

## Implementation sequence

These are bounded planning units, not implementation tickets or final PHP signatures. Every unit includes tests for its changed behavior; the final gate does not defer those tests until the end.

| Unit | Scope and main existing files | Depends on | Completion evidence |
| --- | --- | --- | --- |
| W1: transport correctness | `PerformsApiRequests`, both clients and continuation callers: exact opaque URLs, trusted authenticated destinations, response evidence and operation-specific replay rules | Baseline inventory | Real Guzzle histories reproduce/fix token stripping and ambiguous write replay; read retries remain supported |
| W2: grant-scoped runtime | Existing drivers/token managers, `MailboxManager`, resolvers and OAuth services: explicit grant/target selection and guarded refresh | W1 | Both delegated modes make authenticated requests; omission preservation requires matching verified grant/subject/tenant/client identity; one shared atomic lock per grant spans processes/targets, with retryable failure and no refresh exchange on failed/unavailable/contended coordination; identity/cache/revision/generation guards preserve isolation; legacy configured modes still work |
| W3: optional storage | Service provider, models, migrations and OAuth controllers: host storage and optional Eloquent integration with explicit legacy binding | W2 | SDK-only tests use no package tables; existing Eloquent/configured Ngest paths work; additive upgrades preserve ambiguous records and refuse guessed ownership; success/error callback returns reject external-path bypasses |
| W4: content and files | DTO mapping, Gmail message/body/file resources, Graph file resources and internal parser/serializer adapters | W1; dependency resolution | RFC address groups/quoted names and encoded headers work; externalized text remains body content; wire errors are explicit; file reads honor byte/memory budgets and bind exact immutable bytes across opens/resumes |
| W5: bounded reads and changes | Provider queries/delta collectors, conversation reads and new optional paged capabilities | W2, W4 | Interrupted/capped scans return resumable work; initial baselines cannot skip arrivals; completed checkpoints mean completed durable rounds; full scoped conversation context is available |
| W6: prepared Gmail submission | New optional outbound capability, Gmail client and internal MIME encoding | W1, W2, W4 | Correct sender/recipients/headers/files/thread and forward behavior; one irreversible request; real acknowledgement or explicit uncertainty; evidence lookup never resends |
| W7: Graph preparation and submission | Graph client and native draft/reply/forward/file operations | W1, W2, W4 | Immutable draft identity and each upload phase are observable; 10 MiB cases use upload sessions; same-length source mutation after an acknowledged prefix stops further upload/send; send/evidence handling preserves uncertainty and does not claim delivery |
| W8: notification primitives | Gmail watch/stop and Graph registration/renewal/removal/validation capabilities | W2, W5 | Registration expiry and source identity are explicit; malformed/replayed/lifecycle cases do not advance sync; required live mode reports missing prerequisites |
| W9: compatibility and release | Tests, stubs, extension/config/authentication docs, Composer/CI matrices, migration guide and live validation ledger | W1-W8 | Existing package gates and consumer contract checks pass; supported account/file/notification claims have their declared live evidence or remain blocked |

W4 can proceed alongside W2/W3 once W1 is settled. W6 and W7 can proceed independently after their shared message and token contracts stabilize. Keep one writer for shared contracts/transport. No source-level generic workflow engine or second manager hierarchy is needed.

## Invariants

- Mailbox introduces no Team, membership, private-note, assignment, archive/snooze, Workflow or browser-realtime model.
- Private editor drafts stay in the host. Provider preparation starts only through an explicit submission operation.
- An accepted response is not a delivery receipt. An uncertain submission is never automatically repeated.
- Same account, different host connection: grants, caches and refresh writes stay independent. There is no silent fallback to broader app-only credentials.
- Omitted refresh tokens never cross selected-grant or verified subject/tenant/OAuth-client identities. New delegated refresh requires one shared atomic lock per grant across processes and mailbox targets, followed by guarded replacement.
- OAuth success and error returns stay on local application paths or explicitly trusted absolute URLs, including after browser normalization.
- Normal token refresh does not invalidate jobs; reconnect/disconnect does invalidate work that captured the older authorization generation.
- Persist page work before promoting its continuation; complete the round before promoting the completed checkpoint. A notification history ID is not a committed checkpoint.
- Folder removal does not itself authorize deletion of retained local conversation history.
- File wire completeness/decoding belongs in the SDK. Host type/size allowlists, private storage, file authorization and presentation remain host policy.
- Prepared files retain the exact immutable bytes across every open/resume. A matching identity, path, name or length alone is insufficient; unestablished binding stops additional upload/send.
- Unsupported capabilities and incomplete responses are explicit. A missing body, truncated scan, or absent Graph message ID is not invented as success.

## Design tree and confirmation

- Q1-Q3 settled account scope, optional storage, and compatibility.
- Those decisions unlocked Q4-Q8: provider drafts, responsibility split, live mode, grant selection and caller composition.
- Q9-Q13 settled scoped/bounded synchronization, legacy binding, large files, structured prepared messages and validation boundaries.
- Q14 resolved the delegated/application-permission split for live shared mailboxes.
- Q15 approved the parser/serializer/validator dependency families and their footprint.
- All product/architecture questions raised in the interview are answered. Independent plan review found three clarifications, all resolved: a trusted host verifier for Pub/Sub authentication, basic Graph notification mode, and enforced limits for unpaged whole-thread responses.
- Ian confirmed the shared understanding on 2026-10-29 with "Confirm plan only". The design interview is complete; no implementation, installation, publication or consumer migration was authorized.

Exact method names, internal helper placement, release version and routine implementation choices are not additional product decisions. Public signatures must satisfy the behavior and compatibility checks in this plan; no package release or consumer migration has been authorized.

### Plan review receipt

On 2026-10-29, three read-only subagents investigated the released implementation and reviewed this plan across authentication/storage/compatibility, outbound submission/dependencies, and synchronization/content/notifications. Each independently verified its affected clarifications; no actionable plan finding remains in those scopes. This is review of a design, not verification of future implementation or provider behavior.

This plan, its implementation specification, the glossary and three ADRs are local planning deliverables. Their relative Markdown links and trailing whitespace were checked. Application/package code, Composer files, tests, active databases and provider configuration remain unchanged. No commit, push, release or consumer migration is included.

### Subsequent self-review

Five later independent analysis reports identified callback return safety, identity-scoped refresh-token preservation, immutable file-byte binding, shared per-grant refresh coordination and incomplete local wayfinding rules. A separate writer repaired these planning documents and the local tracker contract; each original finder then independently verified the relevant repair. No accepted finding remains unresolved in the reviewed scope. The 96 stories, seven top-level specification sections and 15 agreed decisions are preserved. The earlier three-agent receipt records its original review only; no implementation or live-provider verification is claimed.

## Dependencies and parsing

The host supplies already chosen message fields and file sources. The SDK needs correct inbound parsing and outbound protocol encoding; it must not export library-specific message objects as its public contract.

| Direct requirement | Planned role | Candidate constraint checked on 2026-10-29 |
| --- | --- | --- |
| `zbateson/mail-mime-parser` | Received mailbox lists/groups, encoded headers, MIME structure and streamed content | `^4.0.8`, PHP >=8.1, BSD-2-Clause |
| `symfony/mime` | Typed headers, encoded subjects/names, MIME bodies and streamed attachment serialization | `^7.4.22 || ^8.1.10`, MIT; 7.4 covers PHP 8.2, while 8.1 needs PHP >=8.4.1 |
| `egulias/email-validator` | Required runtime validation for the selected Symfony Address interface | `^4.0.4`, PHP >=8.1, MIT |

These are planning constraints, not an installed or solver-verified dependency update. Recheck maintained versions, Composer advisories, lowest/latest combinations and consumer constraints before modifying Composer files. The reviewed Mailbox lock obtains Mime/Egulias through development Laravel dependencies; Illuminate-only production requirements do not guarantee them. Do not rely on a development-only transitive dependency.

The parser introduces PHP-DI/Invoker, MB Wrapper, Stream Decorators and an iconv polyfill in the inspected dependency graph. It requires no IMAP or mailparse extension. Its raw-header serializer does not replace Symfony's typed encoding/folding; Symfony's address helpers do not replace a complete RFC mailbox-list parser. Keep these libraries internal rather than adding host-specific parsing or copying another application's parser.

The Symfony candidate lower bounds follow its [2026-10-29 correction concerning a Mime header-injection fix](https://symfony.com/blog/about-the-security-entries-in-symfony-5-4-55-6-4-49-7-4-22-and-8-1-10). This is a dependency-selection requirement for the proposed upgrade, not a claim that this plan has patched an installed consumer.

Add explicit tests for intended Bcc delivery: Symfony's normal MIME serialization removes Bcc headers. AI Clerk currently disallows Bcc, but the generic SDK's explicit-recipient contract cannot silently discard it. Header names/values, subjects, addresses and filenames must reject injection and serialize safely.

Iterable MIME output alone does not prove bounded Gmail JSON/base64url submission. Verify peak memory and byte limits through the actual serialization/HTTP path; use private temporary spooling where necessary and owned cleanup. Oversized raw MIME and signed/encrypted MIME rewriting are not promised.

## Small public interfaces

Names and PHP signatures remain implementation details to validate against examples. The plan requires these behaviors, without adding a generic credential CRUD or workflow framework:

| Interface | Caller supplies | Package returns or guarantees |
| --- | --- | --- |
| Explicit runtime selection | A selected grant/connection identity, expected reconnect generation, and mailbox target | An isolated provider runtime; no global-config mutation, first-token heuristic, or implicit privilege fallback |
| Credential storage adapter | Load and guarded-replacement operations, host ownership/lifecycle guard | Provider refresh semantics, omission/rotation handling and race-safe persistence using the selected grant |
| Bounded reads and changes | Explicit scope, prior committed checkpoint or continuation, request/byte/time budgets | Upserts/removal evidence, content completeness, unfinished work, completed checkpoint or reset requirement |
| Submission preparation | Structured prepared message, correlation identity, file metadata and reopenable/seekable bytes | Provider preparation identity and bounded file progress; no send before explicit submission |
| Irreversible submission | A verified preparation identity | Definite rejection, acceptance evidence, or uncertainty; no automatic replay or enclosing-job release |
| Evidence inspection | Original provider/caller identity and preparation/submission evidence | Observed provider state; absence or mismatch remains inconclusive rather than triggering another send |
| Change registration/validation | Target, selected grant, configured notification identity and registration state | Watch/subscription ID, expiry, capability/prerequisite errors, and validated normalized change/lifecycle signals |

New capabilities must not add mandatory methods to existing custom-driver/resource interfaces. Preserve existing driver constructors, resolver swaps and `google-workspace` alias. Prefer adding the smallest real capability at existing seams to building a second manager hierarchy.

### Token and callback lifecycle

- Bind grants to the host connection and provider identity, including tenant/issuer and OAuth client registration where applicable. Normal refresh has its own guarded revision; it does not advance reconnect/disconnect generation.
- New delegated refresh uses one shared atomic lock per grant across processes and mailbox targets, through Laravel's existing cache-lock contract and a host-configured shared store. A per-process array store or mailbox-only lock is insufficient. Failed, unavailable or contended coordination returns a retryable failure without a refresh exchange. Retain existing configured-mode behavior where promised; no new lock framework is needed.
- Reload under the lock and conditionally replace credentials only if the selected identity and expected generation/revision still match. In callbacks and ordinary refresh, preserve an omitted refresh token only for the same explicitly selected grant and verified provider subject, tenant/issuer and OAuth client identity. Another identity cannot inherit the old token and must report missing renewable authorization when none is returned. Atomically retain rotations.
- A host lifecycle guard runs before provider phases and before credential commits. Enabling generic OAuth routes must not imply host authorization: initiation, callback state, current actor and final binding require explicit host validation.
- OAuth state is single-use, expires, and is bound to the initiating context. A caller-supplied `user_reference` is not authorization. Existing callback/result shapes remain compatible where safe; document any mandatory security configuration change.
- Success and error callback `return_to` values must be local application paths or explicitly trusted absolute URLs. Reject scheme-relative values and browser-normalized external paths, including backslashes and control characters. Reproduce the existing leading-slash bypass in both providers before implementation; document the security exception to compatibility.
- Core token, read, submission and notification operations must work without Eloquent queries. Existing database-backed integrations remain supported through the optional adapter.

### Read and checkpoint semantics

- Graph delta is per folder. A mailbox-wide scope requires explicit folder enumeration/selection and separate progress; there is no universal mailbox delta token.
- Distinguish folder removals from verified message deletion and host retention. New changes use upserts rather than inferring a strong created/updated distinction from Graph etags.
- Capture a safe Gmail initial baseline before scanning. A configured cap, byte budget, repeated token, missing content, or interruption cannot promote a completed checkpoint.
- Validate checkpoint provider, selected identity, target/scope and format version before use. Preserve opaque token bytes; validate authenticated request origins and resource scope separately.
- Conversation completeness is scoped. Gmail selection by activity/cutoff must retain older thread context; Graph must declare which mailbox/folders were covered. Hydrate externalized bodies separately from named/inline files, reporting incomplete or unsupported content explicitly.
- Gmail `threads.get` has no pagination. Request/time budgets govern scheduling between requests, and response/content byte limits must be enforced through the actual transport. An oversized single response or unfinished hydration returns explicit incomplete/unsupported work and cannot advance completion; page size or request count alone does not bound a thread's payload.
- Keep correctness guards and observable limits within the package; host jobs retain progress only after the returned work is durable. Host retention, sanitization, private storage and presentation remain external.

### Submission and file semantics

The host supplies explicit sender, recipients, body/headers, reply/forward intent, correlation identity and file manifest. Each file has a stable caller identity, name, media type, byte length, inline/Content-ID metadata where applicable, and a reopenable/seekable byte source. The SDK performs protocol serialization; it does not select recipients or inherit historical Bcc.

Bind each prepared file to the exact immutable bytes across every open and resume, through host-guaranteed immutable content or a verified content fingerprint strategy. Identity, path, name and length alone cannot establish this binding. If binding fails or cannot be established, stop before any additional upload or send. The host retains storage and checksum ownership; no new digest type or storage framework is required.

Graph's 4 MB write-request limit requires an attachment-aware path. Files below 3 MB use attachment POSTs; files from 3 MB use Outlook upload sessions within the provider's documented maximum. Upload bounded sequential ranges to the returned preauthenticated URL without a Graph bearer header. That upload URL is private recovery material, never a public/debug field or unredacted log value.

Do not copy OneDrive upload-status or expiry assumptions into Outlook. Persist acknowledged progress and attachment identities through the host; uncertain final chunks or attachment POSTs require evidence before repeating attachment work. The SDK needs a complete/incomplete result for each preparation step rather than one hidden multi-request `send()` transaction.

| Uncertain phase | Established fact | Required behavior |
| --- | --- | --- |
| Create submission draft | A draft may exist; this workflow has not requested send | Return preparation uncertainty and available correlation evidence. Do not automatically create another draft or proceed to send. |
| Attach file / final upload chunk | The attachment may exist | Inspect the original draft/file evidence before repeating work. No duplicate attachment is assumed safe. |
| Send prepared draft | The provider may have accepted submission | Keep the original immutable/caller identity, inspect it, and never automatically resubmit. |
| Delayed sent-copy lookup / 404 | The requested evidence is unavailable at this moment | Leave uncertainty unresolved; absence is not proof that the send failed. |

Gmail acknowledgement may contain message/thread IDs. Graph send returns an empty `202`; return status/correlation evidence without fabricating a Message DTO or delivery result. Native reply/forward preparation must replace inherited recipients and retain only the caller-selected files on the newly created submission draft; it must never change the original message. Verify the prepared manifest before send. Document harmless protocol normalization instead of claiming byte-identical provider round trips; differences that change intended recipients, content or files remain explicit errors or unresolved verification results.

The host's current file validation target is 10 MiB per file and 20 MiB total. These are consumer parity cases, not new universal SDK defaults. Microsoft currently documents a known issue for large files in shared/delegated mailboxes; those cases remain unverified until authorized live validation. Do not silently lower limits or substitute external file links.

### Change notifications and live mode

The SDK owns Gmail watch/stop, Graph subscription registration/renewal/removal, provider response validation, expiry and notification/lifecycle parsing. The host owns public endpoints, transport authentication configuration, trusted connection/source selection, durable receipts before acknowledgement, dispatch, renewal scheduling and browser updates.

For authenticated Gmail Pub/Sub delivery, require a configured trusted host verifier adapter that verifies the raw request's signature, issuer, expiry, audience and configured service-account identity. The SDK invokes that adapter and then checks verified claims, subscription/registration binding and Gmail account mapping. Missing or rejected verification fails closed; a caller-provided `authenticated` flag or unverified decoded JWT is insufficient. Topic binding comes from trusted registration/configuration, not an assumed topic field on every notification. This keeps cryptographic verification explicit without pretending that the approved MIME dependencies provide a JWT verifier.

The first Graph notification capability uses basic notifications with `includeResourceData=false`. Validate handshakes, secret `clientState`, subscription, tenant/resource identity and lifecycle binding before emitting usable signals. Basic notifications may still carry `resourceData` identity metadata; that property alone does not imply rich mode. Rich encrypted notifications, certificate management and resource-data decryption are unsupported in this release and must return a clear capability error if requested.

Notifications are hints to synchronize from the last committed checkpoint. Duplicates/out-of-order delivery must not publish data twice or advance progress from notification history IDs. Renew registrations before expiry and reconcile missed/removed/lifecycle events. Even required live mode needs recovery scans; it does not promise that provider notifications are never lost.

| Mailbox/account | Selected authorization | Live-mode requirement |
| --- | --- | --- |
| Personal Gmail | Delegated user OAuth | Configured Gmail watch and trusted Pub/Sub delivery |
| Google Workspace | Delegated user OAuth or existing service-account delegation | Configured Gmail watch and trusted Pub/Sub delivery |
| Microsoft 365 own mailbox | Delegated user OAuth or supported application permissions | Supported Graph subscription for the selected grant/target |
| Outlook.com/Hotmail | Delegated user OAuth | Supported Graph own-mailbox subscription and appropriate app registration |
| Microsoft shared mailbox, required live mode | One explicit application-permission grant for read/send/watch | Administrator-approved scoped access and a supported Graph subscription |
| Microsoft shared mailbox, delta-only consumer | Explicit supported delegated or application grant | No push claim; expose actual capability and continue bounded delta reconciliation |

No implicit elevation from a rejected delegated grant to an application grant is allowed. A mixed-grant read/send/watch mode is outside the first release. Provider scope strings, Exchange mailbox permissions and tenant consent are verified prerequisites, not inferred from a successful token exchange alone.

## Planned verification

Use existing Pest/Testbench and Guzzle MockHandler/history tests. Add focused regressions before each corresponding repair, then broaden to shared contracts and required repository gates. New package behavior must be exercised through the real transport/mapping code; a facade fake alone cannot prove token refresh, retry safety, or continuation correctness.

- Authorization: delegated requests for both providers, token omission/rotation, concurrent refresh, reconnect/disconnect during I/O, explicit grant selection and cache isolation.
- Callback returns: both providers' success/error paths reject `//evil.example`, `/\evil.example`, control-character variants and untrusted absolute URLs, while accepting valid local paths and allowed configured hosts. Reproduce the existing leading-slash bypass first.
- Refresh identity: same verified grant/subject/tenant/client preserves an omitted token; account/subject, tenant and client changes cannot inherit it, in callbacks or ordinary refresh. Existing callbacks currently write null on omission; this is not a claim of existing unsafe preservation.
- Refresh coordination: one grant across multiple mailboxes shares its atomic lock; failed/unavailable/contended coordination issues no refresh exchange and returns a retryable failure. Retain guarded revision/generation replacement. In-memory doubles do not prove distributed semantics; separately verify the host's shared-store deployment prerequisite.
- Compatibility: configured modes/aliases, custom driver contracts/stubs, existing OAuth callback result shape, Ngest's empty-config connections and raw/query usage.
- Sync/content: exact opaque URLs, invalid/cyclic destinations, interrupted pages/replay, safe initial baseline, capped listings, expired checkpoints, externalized text, malformed/truncated content, and full selected-conversation context.
- Submission: one send attempt after uncertain transport/5xx/malformed acknowledgement, no implicit surrounding-job release, provider IDs/headers, explicit To/Cc/Bcc, files, and reconciliation without resending.
- File binding: after an acknowledged upload prefix, mutate the source without changing its length and resume with the same identity/path/name. Detect the changed bytes and stop before further upload/send.
- Notifications: missing/rejected cryptographic verifier, wrong signature/audience/principal, trusted registration/topic mapping, duplicate/out-of-order envelopes, wrong source/identity, expiry/renewal, missed/removed registrations, stale generations, basic Graph payloads including identity metadata, rejected rich-mode requests, and live-mode prerequisite errors.

Before database-backed tests, identify Testbench's SQLite memory/worker-file targets and `RefreshDatabase` setup. More than ten tests run in parallel with at most eight workers. No destructive operation against an application database is authorized.

### Compatibility and CI gates

- Test both SDK-only and Eloquent-backed usage, without requiring AI Clerk or Ngest as production dependencies of the package.
- Preserve configured `driver()` calls, `forMailbox()` legacy behavior, `google-workspace`, raw-client usage, custom creators/resolvers, existing constructors, stubs and callback/result fields where safe. New connection-bound entrypoints must be explicit.
- Any security correction that cannot preserve old behavior needs an upgrade note and a deliberate failure message. Unowned legacy OAuth state cannot silently power the new runtime.
- Run actual Ngest compatibility in an isolated consumer environment before release, without modifying its active checkout or credentials. Package fixtures cover its known call contracts but do not replace the consumer check. AI Clerk-shaped adapter cases exercise storage/lifecycle/private-operation contracts; migrating AI Clerk remains outside this package plan.
- Preserve the current PHP 8.2/8.3/8.4 and lowest-dependency coverage. Add explicit supported Laravel 12/13 combinations rather than assuming latest resolution exercised both majors; Laravel/PHP combinations must satisfy their own requirements.
- Run repository-required Pest, PHPStan, Pint and strict Composer checks after affected tests pass. Parallel tests remain capped at eight workers; no benchmark or broad test repetition without a relevant reason.
- Update README claims and `docs/authentication/`, configuration, messages/attachments, events, testing and custom-driver documentation. Add bounded-sync, submission/evidence, notifications, and upgrade guidance only as implemented behavior becomes verified. Remove claims of transparent user-token refresh until the runtime path is actually implemented and tested.

### Live validation and release handoff

Maintain a matrix for every account/authentication mode listed above, with designated test senders/recipients, ordinary and 10 MiB files, own/shared native reply/forward correlation, watch/subscription delivery/renewal, and interrupted/uncertain recovery. Account-specific provider prerequisites and known limitations must be visible beside each result.

Missing accounts, consent, scoped application access, Pub/Sub/public callbacks or recipient authorization are concrete blocked rows. Fixture success never closes those rows. Shared/delegated large files and provider normalization of native reply/forward drafts are currently unvalidated. Do not claim supported parity or publish a release asserting those capabilities until their live gates pass; a code review may still record the blocked evidence honestly.

Before any future release, collect immutable package source identity, terminal package CI, dependency resolution/advisory results, the isolated consumer compatibility result and the live matrix. Publishing the package, changing provider setup and implementing consumer migrations require separate explicit authorization.

## Provider references

- [Gmail synchronization](https://developers.google.com/workspace/gmail/api/guides/sync)
- [Gmail push notifications](https://developers.google.com/workspace/gmail/api/guides/push)
- [Gmail submission](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages/send)
- [Gmail threads](https://developers.google.com/workspace/gmail/api/guides/threads)
- [Gmail whole-thread read parameters](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.threads/get)
- [Authenticated Pub/Sub delivery](https://docs.cloud.google.com/pubsub/docs/authenticate-push-subscriptions)
- [Graph delta synchronization](https://learn.microsoft.com/en-us/graph/delta-query-messages)
- [Graph sendMail](https://learn.microsoft.com/en-us/graph/api/user-sendmail?view=graph-rest-1.0)
- [Graph immutable message IDs](https://learn.microsoft.com/en-us/graph/outlook-immutable-id)
- [Graph Outlook notifications and shared-mailbox permissions](https://learn.microsoft.com/en-us/graph/outlook-change-notifications-overview)
- [Graph notification lifecycle](https://learn.microsoft.com/en-us/graph/change-notifications-lifecycle-events)
- [Graph webhook validation and basic notifications](https://learn.microsoft.com/en-us/graph/change-notifications-delivery-webhooks)
- [Graph write-request limits](https://learn.microsoft.com/en-us/graph/use-the-api)
- [Graph large attachments and shared-mailbox limitation](https://learn.microsoft.com/en-us/graph/outlook-large-attachments)
- [Graph attachment upload sessions](https://learn.microsoft.com/en-us/graph/api/attachment-createuploadsession?view=graph-rest-1.0)
- [Graph native reply preparation](https://learn.microsoft.com/en-us/graph/api/message-createreply?view=graph-rest-1.0)
- [Graph native forward preparation](https://learn.microsoft.com/en-us/graph/api/message-createforward?view=graph-rest-1.0)
- [ZBateson parser release and requirements](https://github.com/zbateson/mail-mime-parser/tree/4.0.8)
- [Symfony Mime maintained 7.4 requirements](https://github.com/symfony/mime/blob/v7.4.22/composer.json)
- [Egulias requirements](https://github.com/egulias/EmailValidator/blob/4.0.4/composer.json)

Official references inform the plan; they do not establish live accounts, consent, delivery, callback configuration, or production readiness. No live validation is claimed.

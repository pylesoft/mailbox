# Live Acceptance Ledger

Required live acceptance remains **open**. M6 has limited authorized shared-mailbox evidence recorded below; the other profiles still lack designated accounts and setup. Local HTTP fixtures and local TLS uploads do not count as live evidence. Tickets [26](tickets/26-validate-gmail-against-designated-sandbox-accounts.md) and [27](tickets/27-validate-microsoft-against-designated-sandbox-accounts.md), and the parent [specification](spec.md), remain open.

Use the immutable candidate and local check identities in the [compatibility receipt](compatibility.md). Before any live execution, record the exact candidate, designated sender/recipient, account/grant/target, scoped permissions, authorized operations and cleanup boundary. A later relevant source or setup change invalidates the affected rows. No account identity, credential or message content is invented here.

## Account and Permission Prerequisites

| Profile | Account and selected authority | Concrete missing prerequisites | Live owner |
| --- | --- | --- | --- |
| G1 | Personal Gmail, delegated | Designated account, OAuth client/consent, permitted mail scopes and authorized sender/recipients | 26 |
| G2 | Workspace, delegated | Designated Workspace account, client/consent/domain policy, permitted mail scopes and authorized sender/recipients | 26 |
| G3 | Workspace, service-account delegation | Designated domain/mailbox, scoped domain delegation, service account and authorized sender/recipients | 26 |
| M1 | Microsoft 365 own mailbox, delegated | Designated tenant/account, app registration/consent, mail scopes and authorized sender/recipients | 27 |
| M2 | Microsoft 365 own mailbox, application | Designated tenant/mailbox, administrator consent, effective scoped read/write/send permission and authorized recipients | 27 |
| M3 | Outlook.com/Hotmail, delegated | Designated personal account, suitable app registration/consent, verified identity behavior and authorized sender/recipients | 27 |
| M4 | Microsoft shared mailbox, delegated, delta-only discovery | Designated user/shared target, delegated mail scopes plus Exchange mailbox/send permissions and authorized recipients | 27 |
| M5 | Microsoft shared mailbox, application, delta-only discovery | Designated tenant/shared target, administrator-approved scoped read/write/send authority and authorized recipients | 27 |
| M6 | Microsoft shared mailbox, required live mode | Existing approved scoped application and two designated targets; complete remaining controls, stable callbacks, renewal, file-send limits and controlled recovery | 27 |

M6 uses the two approved AI Clerk shared mailboxes, with sending restricted to the dedicated TEST conversation and Ian's authorized recipient. Other profiles still require designated accounts and recipients. Token acquisition alone cannot establish effective permissions. M4 is not promoted to required live mode or combined with a broader application grant.

### Limited M6 evidence — November 2, 2026

AI Clerk `3b0accd` installs SDK `bb6955b`. Authorized file-free native reply and forward operations received one acceptance each, matched sent copies, and independent recipient observation. Two inbound test replies retained their accents and appeared in the open host conversation without a reload. Earlier exact-ID and received-file reconciliation passed for its recorded snapshot; new arrivals require a fresh check.

Two subscriptions were removed and recreated through the normal host lifecycle after the temporary tunnel hostname rotated. Both replacements passed provider inspection without changing mailbox permissions, approval, import generation or cutoff. This does not establish actual renewal, fixture-specific callback correlation, production latency, or a stable callback deployment.

A later controlled `reauthorizationRequired` event renewed the same Orders registration and returned the host binding to ready after inspection. Two identical controlled `missed` deliveries each retained and processed a receipt, then recovered to ready without replacing the registration. These close the designated controlled renewal and duplicate/missed lifecycle delivery cases, while provider-originated message correlation, interrupted preparation and production latency remain open. The temporary tunnel outage and its explicit registration recovery are retained in the private operational evidence; a stable callback deployment is still required.

An additional direct SDK native reply through the approved orders application grant uploaded two synthetic files of exactly 10,485,760 bytes each. Both provider readbacks matched their SHA-256 checksums before submission. One send returned 202; SDK inspection then observed the sent copy with the same verified files. The recipient independently received one TEST reply with both exact-size attachments, no Cc/Bcc recipients and intact accents. This qualifies the 10 MiB individual and 20 MiB aggregate native-reply cases for this designated setup. This SDK fixture does not qualify inline files.

These observations remain partial M6 evidence, not full account-profile acceptance. Ordinary HTML send combinations, effective denied-target controls and interrupted preparation remain open. The earlier raw draft-only boundary upload does not qualify SDK sending. See the [host live-acceptance ticket](https://github.com/pylesoft/ai-clerk/blob/3b0accdb7c2c1ba3a3e5d23a9fed160bb47c7650/docs/plans/mailbox-integration/tickets/18-validate-microsoft-through-designated-live-accounts.md) for the required case scope.

A separate native TEST forward qualified the exact 2,999,999-byte attachment POST and 3,000,000-byte upload-session boundary using the host's 4,194,304-byte request budget. Both TXT files passed raw byte/checksum verification before one send; sent-copy inspection and independent recipient observation passed. Inline-image and ordinary HTML submission remain open.

Two controlled lost-acknowledgment cases hid the actual 202 response after one send: a direct SDK text reply and a host reply with two exact 10 MiB files. Both retained an uncertain outcome, then resolved through sent-copy inspection without resending. Each arrived once at the authorized recipient. The local host follow-up also qualified its explicitly enabled Orders application source through the normal composer/worker with a small TXT and PNG; new sources still default to file sending disabled. Its maximum-size recovery published one original operation and preserved newer private words and files. These host changes remain local and unpublished; the SDK candidate remains `bb6955b`. This proves controlled lost-response recovery, not every interrupted preparation or production failure mode.

A subsequent real inbound TEST reply matched four provider-originated callback receipts by exact immutable message ID. Each receipt processed once; one message published in the original host conversation, and the open browser updated without reloading. Accents and the newer private draft survived. The roughly ten-minute backfill-time publication delay leaves production latency open.

The installed candidate then blocked a native HTML/inline-image forward before any send: Graph added an HTML document wrapper, a table body and an image trace attribute. Its 69-byte PNG had already passed raw checksum verification, and the acknowledged unsent draft is preserved. A local, uninstalled comparison fix handles those observed transformations while retaining content and resource identity checks. Normalization is limited to valid UTF-8 HTML up to 256 KiB and uses PHP DOM when installed; exact-body comparison still works without DOM. Unsupported forms remain fail-closed. This prepared repair does not count as a passed live HTML/inline case until installed and reverified.

After explicit approval, AI Clerk installed SDK `5ad0459`, including the reviewed HTML comparison fix. The same retained preparation passed verification and sent exactly once, then matched its immutable sent copy. Independent recipient observation found one HTML forward with the table, intact accents and the original 69-byte inline PNG under the chosen Content-ID. The full SDK suite passes 1,723 tests and 8,618 assertions with nine opt-in wire cases skipped; PHPStan and Pint pass. This qualifies the designated native HTML/inline case. Other HTML transformations and account profiles remain separate cases; the host composer still submits its existing text body and file selections.

## Reads, Submission and Files

`Blocked G1` means that case is blocked by the exact G1 prerequisites above and the absent authorization for that live operation. Each cell is a separate outstanding acceptance case. Reply/forward cases must inspect chosen recipients/content/files, unchanged originals and conversation correlation; provider normalization must be recorded rather than assumed byte-identical.

Gmail replies use the selected thread and RFC reply headers; forwards send the chosen message without reusing the source thread or reply headers. Graph uses native `createReply` / `createForward` preparation on a new draft. The matrix checks each provider's declared behavior, not identical provider APIs.

| Profile | Authorization/token renewal and identity isolation | Initial/delta and complete scoped content | Ordinary text/HTML send | Reply/context | Forward/context | Small/inline files | One 10 MiB file | 20 MiB aggregate files |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| G1 | Blocked G1 | Blocked G1 | Blocked G1 | Blocked G1 | Blocked G1 | Blocked G1 | Blocked G1 | Blocked G1 |
| G2 | Blocked G2 | Blocked G2 | Blocked G2 | Blocked G2 | Blocked G2 | Blocked G2 | Blocked G2 | Blocked G2 |
| G3 | Blocked G3 | Blocked G3 | Blocked G3 | Blocked G3 | Blocked G3 | Blocked G3 | Blocked G3 | Blocked G3 |
| M1 | Blocked M1 | Blocked M1 | Blocked M1 | Blocked M1 | Blocked M1 | Blocked M1 | Blocked M1 | Blocked M1 |
| M2 | Blocked M2 | Blocked M2 | Blocked M2 | Blocked M2 | Blocked M2 | Blocked M2 | Blocked M2 | Blocked M2 |
| M3 | Blocked M3 | Blocked M3 | Blocked M3 | Blocked M3 | Blocked M3 | Blocked M3 | Blocked M3 | Blocked M3 |
| M4 | Blocked M4 | Blocked M4 | Blocked M4 | Blocked M4 | Blocked M4 | Blocked M4 | Blocked M4 + L | Blocked M4 + L |
| M5 | Blocked M5 | Blocked M5 | Blocked M5 | Blocked M5 | Blocked M5 | Blocked M5 | Blocked M5 + L | Blocked M5 + L |
| M6 | Partial: scoped shared reads/submission | Partial: recorded snapshot only | Designated native HTML forward passed | Partial: native TEST with verified files | Partial: native TEST with boundary and inline files | Small-file/session boundary and designated inline PNG passed | Passed: designated native reply + L | Passed: designated native reply + L |

**L:** Microsoft documents a known [large-attachment issue for shared/delegated mailboxes](https://learn.microsoft.com/en-us/graph/outlook-large-attachments). Upload-session fixture/TLS success does not resolve it. Record actual provider/account results; do not lower the file target or replace files with links to manufacture parity. The 10 MiB and 20 MiB cases are host acceptance targets, not universal package defaults.

## Notification and Recovery Cases

**P:** Gmail also needs the designated topic/subscription, raw-request cryptographic verifier, trusted principal/audience/account binding, reachable public callback and durable receipt/reconciliation wiring. **Q:** Microsoft also needs a reachable basic-notification/lifecycle callback, retained subscription/client-state/tenant/resource binding and durable receipt/reconciliation wiring. **R:** The host needs controlled recovery scenarios and a separately verified shared cross-process refresh-lock store for delegated refresh.

| Profile | Watch/subscription registration and validated delivery | Renewal and stop/removal | Duplicate/older/missed notifications and catch-up | Interrupted preparation, uncertain send and delayed evidence | Reconnect/disconnect and deployed coordination |
| --- | --- | --- | --- | --- | --- |
| G1 | Blocked G1 + P | Blocked G1 + P | Blocked G1 + P + R | Blocked G1 + R | Blocked G1 + R |
| G2 | Blocked G2 + P | Blocked G2 + P | Blocked G2 + P + R | Blocked G2 + R | Blocked G2 + R |
| G3 | Blocked G3 + P | Blocked G3 + P | Blocked G3 + P + R | Blocked G3 + R | Blocked G3 + R |
| M1 | Blocked M1 + Q | Blocked M1 + Q | Blocked M1 + Q + R | Blocked M1 + R | Blocked M1 + R |
| M2 | Blocked M2 + Q | Blocked M2 + Q | Blocked M2 + Q + R | Blocked M2 + R | Blocked M2 + R |
| M3 | Blocked M3 + Q | Blocked M3 + Q | Blocked M3 + Q + R | Blocked M3 + R | Blocked M3 + R |
| M4 | Not claimed in delta-only mode | Not claimed in delta-only mode | Blocked M4 + R: bounded delta catch-up | Blocked M4 + R | Blocked M4 + R |
| M5 | Not claimed in delta-only mode | Not claimed in delta-only mode | Blocked M5 + R: bounded delta catch-up | Blocked M5 + R | Blocked M5 + R |
| M6 | Registration, lifecycle delivery and one real inbound immutable-ID correlation passed | Removal/replacement and designated renewal passed | Controlled duplicate/missed lifecycle receipts passed; full catch-up open | Controlled lost-ack recovery passed without resend; interrupted preparation open | Open + R |

Controlled recovery must inspect original evidence without automatically repeating a possibly accepted send or duplicating an ambiguous attachment. An empty Graph 202 establishes acceptance, not delivery. Missing sent-copy evidence stays inconclusive. Observe actual recipient delivery separately if authorized; it cannot be inferred from acceptance.

Encrypted rich Graph notifications, mixed delegated/application grants for one required live shared connection, arbitrary oversized raw-MIME rewriting and signed/encrypted MIME reconstruction are unsupported in this release. They are not pending fixture cases. No setup change, live send, callback deployment, package publication or consumer migration is authorized by this ledger.

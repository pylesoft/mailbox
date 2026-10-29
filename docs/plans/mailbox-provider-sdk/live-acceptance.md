# Live Acceptance Ledger

Every required live case below is **blocked**. No designated accounts, recipient authorization, consent, public callback setup or live-operation authority has been supplied for this implementation run. Local HTTP fixtures and local TLS uploads do not count as live evidence. Tickets [26](tickets/26-validate-gmail-against-designated-sandbox-accounts.md) and [27](tickets/27-validate-microsoft-against-designated-sandbox-accounts.md), and the parent [specification](spec.md), remain open.

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
| M6 | Microsoft shared mailbox, required live mode | Designated tenant/shared target; one explicit application grant with administrator-approved scoped read/write/send/watch authority; supported push setup; authorized recipients | 27 |

No profile currently has a designated sender or recipient. Token acquisition alone cannot establish the effective permissions in this table. M4 is not promoted to required live mode or combined with a broader application grant.

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
| M6 | Blocked M6 | Blocked M6 | Blocked M6 | Blocked M6 | Blocked M6 | Blocked M6 | Blocked M6 + L | Blocked M6 + L |

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
| M6 | Blocked M6 + Q | Blocked M6 + Q | Blocked M6 + Q + R | Blocked M6 + R | Blocked M6 + R |

Controlled recovery must inspect original evidence without automatically repeating a possibly accepted send or duplicating an ambiguous attachment. An empty Graph 202 establishes acceptance, not delivery. Missing sent-copy evidence stays inconclusive. Observe actual recipient delivery separately if authorized; it cannot be inferred from acceptance.

Encrypted rich Graph notifications, mixed delegated/application grants for one required live shared connection, arbitrary oversized raw-MIME rewriting and signed/encrypted MIME reconstruction are unsupported in this release. They are not pending fixture cases. No setup change, live send, callback deployment, package publication or consumer migration is authorized by this ledger.

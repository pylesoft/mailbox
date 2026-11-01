---
status: accepted
---

# Keep provider behavior separate from application orchestration

Mailbox will own provider authentication and refresh, requests, bounded synchronization and submission primitives, and notification registration and validation. Applications will own authorization, durable operation and receipt state, scheduling, jobs, local projections, and browser updates. This shares provider behavior without imposing AI Clerk's models or application lifecycle on other consumers.

The core must work without Mailbox database tables. Host-supplied storage adapters and the existing optional Eloquent integration serve the same provider interfaces; existing Ngest integrations, configuration, data, and authentication modes remain supported. Mandatory package persistence or a package-owned workflow engine would duplicate application state and couple otherwise independent consumers.

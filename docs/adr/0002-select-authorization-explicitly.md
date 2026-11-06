---
status: accepted
---

# Select authorization by connection rather than mailbox address

Each connection will select its authorization grant explicitly. One grant may authorize several mailboxes, and independent host contexts may hold separate grants for the same external account. Provider subject or mailbox address alone cannot select or overwrite a grant: that would merge ownership when two applications or Teams connect the same account.

Normal token refresh preserves the connection's identity. Reconnect and disconnect invalidate older work separately from token refresh or rotation. Applications supply their own ownership and lifecycle checks; Mailbox will expose a guarded credential interface without importing Team or membership models.

---
status: accepted
---

# Expose preparation separately from irreversible submission

Mailbox will accept a structured message whose content, recipients, and reply/forward intent have already been chosen by the application. The SDK serializes the provider protocol and handles separate file streams. Large Graph attachments require a draft and upload sessions, so one opaque MIME request cannot represent every supported submission safely.

Graph preparation may create externally visible drafts only after the host explicitly starts a submission. Editor drafts remain local. Preparation handles and file progress must be observable to the host before the irreversible send; lost preparation acknowledgements and lost send acknowledgements have different consequences. The package returns evidence without owning a durable outbox, hiding ambiguous replay, or claiming that acceptance proves delivery.

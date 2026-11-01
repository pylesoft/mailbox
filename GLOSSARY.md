# Mailbox

Mailbox describes access to external email accounts and their messages. Applications use that access within their own ownership and collaboration rules.

## Language

**Connection**:
A configured relationship through which an application accesses an email provider. A connection selects the authorization used for its mailboxes.
_Avoid_: Connector, when referring to this package's connection concept

**Mailbox**:
An email store belonging to a person or organization, including a shared email store. A mailbox is distinct from the authorization used to access it.
_Avoid_: Inbox, when referring to the entire mailbox

**Authorization grant**:
Authority to access provider resources under a particular identity and permission set. Separate grants may authorize access to the same mailbox.
_Avoid_: Account, credential, when the authorization itself is meant

**Shared mailbox**:
A mailbox accessed by authorized people or applications on behalf of an organization. Its mailbox identity differs from the identity authorized to access it.

**Conversation**:
A provider-associated group of related email messages. Its available contents depend on the provider and the mailbox scope being read.
_Avoid_: Message, when referring to the group

**Submission**:
An explicit request to send a prepared email through a provider. Preparing or editing an email alone is not a submission.

**Prepared message**:
Email content, recipients, and files already chosen by the application for submission. It carries no rule for choosing recipients or editing content.

**Submission preparation**:
The provider work required before requesting delivery of a prepared message, such as creating a draft or uploading files. Preparation alone does not establish that a send was requested.

**Submission evidence**:
Provider observations tied to a particular preparation or send attempt. Missing or ambiguous evidence does not authorize another send attempt.

**Acceptance**:
The provider's acknowledgement that it accepted a submission for processing. Acceptance does not establish delivery to a recipient.
_Avoid_: Delivered, when only acceptance is established

**Delivery**:
The arrival of an email at its recipient's mail system. Provider acceptance alone is insufficient evidence of delivery.

**Change notification**:
A provider's indication that mailbox data may have changed. A notification is a reason to reconcile mailbox state, not a complete replacement for that state.

**Synchronization checkpoint**:
A position from which mailbox changes can be reconciled after earlier work has been retained. An unfinished continuation and a completed synchronization checkpoint represent different positions.

**Continuation**:
A position within unfinished mailbox-reading or synchronization work. It does not establish that a synchronization round is complete.

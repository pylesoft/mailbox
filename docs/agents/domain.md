# Domain docs

This repository has one Mailbox domain context.

## Before exploring

Read the root [GLOSSARY.md](../../GLOSSARY.md) and the [ADRs](../adr/) relevant to the requested work. Use the glossary vocabulary and flag a conflict with an accepted ADR before proposing a different design.

For the current provider SDK upgrade, also read its [plan](../plans/mailbox-provider-sdk/README.md) and [specification](../plans/mailbox-provider-sdk/spec.md).

If a domain document is absent, continue without inventing its contents or creating a placeholder. The domain-modeling workflow records terms and decisions when they are actually resolved.

## Layout

- Root `GLOSSARY.md`: domain terms only, without implementation details.
- `docs/adr/`: sequentially numbered architectural decisions for this context.
- `docs/plans/<feature>/`: feature plans, specs and local tickets.

Keep package Connection and Mailbox concepts distinct from a host application's Connector, Inbox, Team or collaboration models. The SDK shares provider behavior; the host retains its authorization and durable orchestration.

Do not create a context map or duplicate context-specific glossaries for this single-package repository. Change this layout only when a real multi-context design is agreed.

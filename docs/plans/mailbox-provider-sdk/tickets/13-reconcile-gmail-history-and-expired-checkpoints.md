# 13: Reconcile Gmail history and expired checkpoints

**What to build:** Resume incremental changes and scoped recovery without losing durable progress or retained history.

**Blocked by:** [12: Resume Gmail initial synchronization without gaps](12-resume-gmail-initial-synchronization-without-gaps.md)

**Status:** ready-for-agent

**Resolution:** open

**Spec:** [Specification](../spec.md)

**Stories:** 38, 39, 40, 44, 45, 46, 47, 48, 88.

Reconcile Gmail changes from a committed checkpoint and recover expired history safely, preserving the host's original import scope and local history.

## Acceptance criteria

- [ ] Read bounded history pages from the last host-committed checkpoint and expose upserts/removal evidence without unreliable created-versus-updated guesses.
- [ ] Keep intermediate continuation separate from round completion; retain work before advancing and permit safe replay after interruption.
- [ ] Handle duplicate records, empty pages, repeated/missing history continuation and newly arriving changes without false completion.
- [ ] Return a scoped reset requirement for expired/invalid history and restart with the original scope/cutoff and a safe baseline.
- [ ] Do not interpret a reset or removal signal as permission to erase the host's retained conversation history.
- [ ] Validate checkpoint identity/generation/scope on every resumed operation and stop superseded work.
- [ ] Preserve delta-only usage, separate host scheduling from SDK provider work, and document recovery and checkpoint promotion.

## Verification

- Exercise real public Gmail synchronization paths with paginated history, replay/interruption, history expiry and events arriving during recovery.
- Assert reset scope/cutoff preservation, unchanged local-retention responsibility and the absence of checkpoint promotion before retained work completes.

## Comments

- 2026-10-29: Claimed by `mailbox_ticket_13` on clean `feat/mailbox-sdk-13`, based on integration `9161f2ddc0fb1c4ff358265415394d6ef2c4467f`, with no upstream. Scope is bounded Gmail history and scoped expiry recovery using ticket 12's existing progress/budget contracts. Q13's approved public SDK/real-Guzzle HTTP-fake seam and host lifecycle adapter apply; SDK tests forbid database access. No provider, host persistence/job/retention, watch, publication or consumer operations are included.


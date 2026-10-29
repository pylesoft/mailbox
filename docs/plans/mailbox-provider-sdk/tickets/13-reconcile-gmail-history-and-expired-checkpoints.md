# 13: Reconcile Gmail history and expired checkpoints

**What to build:** Resume incremental changes and scoped recovery without losing durable progress or retained history.

**Blocked by:** [12: Resume Gmail initial synchronization without gaps](12-resume-gmail-initial-synchronization-without-gaps.md)

**Status:** ready-for-agent

**Resolution:** closed

**Spec:** [Specification](../spec.md)

**Stories:** 38, 39, 40, 44, 45, 46, 47, 48, 88.

Reconcile Gmail changes from a committed checkpoint and recover expired history safely, preserving the host's original import scope and local history.

## Acceptance criteria

- [x] Read bounded history pages from the last host-committed checkpoint and expose upserts/removal evidence without unreliable created-versus-updated guesses.
- [x] Keep intermediate continuation separate from round completion; retain work before advancing and permit safe replay after interruption.
- [x] Handle duplicate records, empty pages, repeated/missing history continuation and newly arriving changes without false completion.
- [x] Return a scoped reset requirement for expired/invalid history and restart with the original scope/cutoff and a safe baseline.
- [x] Do not interpret a reset or removal signal as permission to erase the host's retained conversation history.
- [x] Validate checkpoint identity/generation/scope on every resumed operation and stop superseded work.
- [x] Preserve delta-only usage, separate host scheduling from SDK provider work, and document recovery and checkpoint promotion.

## Verification

- Exercise real public Gmail synchronization paths with paginated history, replay/interruption, history expiry and events arriving during recovery.
- Assert reset scope/cutoff preservation, unchanged local-retention responsibility and the absence of checkpoint promotion before retained work completes.

## Comments

- 2026-10-29: Claimed by `mailbox_ticket_13` on clean `feat/mailbox-sdk-13`, based on integration `9161f2ddc0fb1c4ff358265415394d6ef2c4467f`, with no upstream. Scope is bounded Gmail history and scoped expiry recovery using ticket 12's existing progress/budget contracts. Q13's approved public SDK/real-Guzzle HTTP-fake seam and host lifecycle adapter apply; SDK tests forbid database access. No provider, host persistence/job/retention, watch, publication or consumer operations are included.
- 2026-10-29: Implemented in `4f33e2d4cc07ce1b201154b00f214899c7e5aa07`, formatted source `febaf90d4302ad7430acda8860d9176ff0f9b7a1`; integration `00beee6f4eff5a0ab01b9399d946b1a63e5164ef` is merged through `edb836e2082f221a8be51f5f0eb6c9ca058bfd6c`. The additive capability is `SupportsIncrementalSync::incrementalSync(SyncScope, SyncBudget, SyncCheckpoint, ?SyncContinuation = null): ChangePage` on explicit-grant Gmail resources. It reuses ticket 12's DTOs and actual-byte `getBounded` transport; existing eager and custom-driver contracts are unchanged.
- 2026-10-29: History uses the committed baseline throughout a round and returns a completed checkpoint only with a valid terminal mailbox history ID. Missing/invalid positions, present invalid tokens, repeated/cyclic tokens and budget limits retain the previous page position. History IDs remain strings, including values above integer precision. Gmail limits history records rather than expanded IDs: an overflowing page returns `provider_item_limit` without trimming evidence or advancing past it, and can be replayed with a larger budget. Specific changes and generic IDs are deduplicated; label removal produces reconciliation candidates, while only `messagesDeleted` produces deletion evidence.
- 2026-10-29: Unfiltered history candidates prevent label exits from disappearing behind provider label filtering. The host rechecks content/scope before updating its projection; no message-age heuristic discards activity on retained older conversations. A history 404 returns `resetRequired` / `history_expired`, with no checkpoint or local deletion instruction. Recovery reuses the committed checkpoint's exact folder/cutoff through the existing initial scan, captures a new pre-scan baseline and reconciles arrivals during recovery. `docs/delta-sync.md` and `docs/upgrade-guide.md` document host evidence retention, checkpoint promotion, scope rechecks and reset ordering.
- 2026-10-29: Final new SDK gate passed 28 tests / 155 assertions, including a post-Pint run: `php vendor/bin/pest tests/Sdk/GmailIncrementalSyncTest.php --parallel --processes=2 --compact`. Public SDK tests exercise real Guzzle HTTP fakes with database access forbidden. Red/green tracers reproduced absent capability, false intermediate completion, expanded-ID overflow, unhandled expiry, invalid tokens/positions and cyclic progress before their fixes. Final affected gate passed 81 tests / 259 assertions: `pest --parallel --processes=2 --compact --filter='GmailInitialSyncTest|GmailDeltaSyncTest|GmailMailboxResourceDirectTest|ExplicitGrantReadTest|ArchitectureTest'` using the explicit PHP 8.4 binary and process-local `OPENSSL_CONF=C:/Program Files/Git/usr/ssl/openssl.cnf`. Feature/architecture cases use only Testbench's isolated SQLite memory/worker databases. No Workbench build, destructive MySQL command or full-suite rerun was performed.
- 2026-10-29: Changed-source PHPStan level 8 passed with `--debug --no-progress` (single process) for the new capability, history reader and Gmail mailbox resource. Pint passed for all four changed PHP files and then `--dirty --format agent`; `git diff --check` passed. Resource checks ran serially with two Pest workers after the user requested lower concurrency. Parent owns combined integration verification; live providers, real consumer execution and watch registration remain separate tickets. No dependencies, providers, remotes, host persistence/jobs or active consumers were changed.


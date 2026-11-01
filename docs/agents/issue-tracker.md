# Issue tracker: Local Markdown

Specs, tickets and planning discussions live as Markdown in this repository. Publishing to the issue tracker means writing the appropriate local file. It does not mean creating GitHub issues, posting comments, pushing commits or opening a pull request.

## Storage

- One feature per existing or new `docs/plans/<feature-slug>/` directory.
- Keep the design/plan in `README.md` and the specification in `spec.md`.
- Create one implementation ticket per `tickets/<NN>-<slug>.md`, numbered from `01`. Do not combine tickets into one file or create an `issues/` or `.scratch/` alternative.
- The current feature is `docs/plans/mailbox-provider-sdk/`; its existing spec and plan are canonical. Reuse them rather than creating another copy.
- Create tickets only when requested. Configuration and a ready-for-agent status alone do not authorize implementation.

## Metadata

- Use `**Status:** <role>` near the top of each spec/ticket. The role vocabulary is in [triage-labels.md](triage-labels.md).
- Use `**Resolution:** open` or `**Resolution:** closed` for completion; a missing resolution means open. Triage readiness and completion are distinct.
- Each implementation ticket links to its parent spec with `**Spec:** [Specification](../spec.md)`.
- Record dependencies as `**Blocked by:**` followed by relative links to the blocking ticket files, or `None`.
- Append discussion beneath `## Comments`. Preserve existing acceptance criteria, decisions and evidence.

## Tracker operations

- **Publish:** create or update the local spec/ticket in its feature directory. Apply ready-for-agent by setting the local Status field when the work is fully specified.
- **Fetch:** read the referenced local file. Resolve a bare ticket number within the current feature; ask if it matches multiple features.
- **Triage:** change the local Status value and record the reason in Comments. Do not create remote labels.
- **Claim:** record an explicit local claim in Comments before authorized implementation; readiness alone is not a claim.
- **Find available work:** include only open, unclaimed tickets whose blockers are closed and whose Status is ready-for-agent, in dependency order.
- **Resolve:** after authorized work and required checks are complete, set Resolution to closed and append completion evidence. Do not close work just because a branch, commit or draft PR exists. A spec closes only after its required tickets and acceptance gates are satisfied.
- **Blocked work:** retain the open resolution and record the concrete blocker. Do not count mocked provider checks as live acceptance.

## Wayfinding operations

When wayfinding is requested, keep its map at `docs/plans/<effort>/map.md` and each child decision ticket in a separate file under that effort's `tickets/` directory. Use relative links for parent/child and blocking relationships; do not use `.scratch/`.

Each child records `**Type:** research`, `prototype`, `grilling` or `task`, a `**Parent:**` link to the map, `**Blocked by:**` links or `None`, and `**Work state:** open`, `claimed` or `resolved`. Missing Work state means open. The five canonical Status roles remain implementation triage metadata; wayfinding operations never change those roles or require ready-for-agent.

- **Find the decision frontier:** include only open, unclaimed children whose blockers all have Work state resolved and Resolution closed. A closed Resolution alone does not resolve an open decision.
- **Claim:** set Work state to claimed, keep Resolution open, and record who claimed it in the existing Comments section before investigating.
- **Resolve:** record the answer and evidence, update the map's decisions, and set both Work state to resolved and Resolution to closed. Blocking checks use this same completed pair; inconsistent fields remain blocking until reconciled.

A decision ticket's resolution is an answered question, not permission to implement its conclusion. General implementation-work discovery still uses the triage and resolution rules above.

## Publication boundary

The configured tracker is local even though the repository has a GitHub remote. Remote issues, comments, PRs, package releases, provider setup and deployment need separate authorization. Do not change Git remotes or global plugin settings as part of local tracker operations.

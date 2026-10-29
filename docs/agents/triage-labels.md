# Triage labels

These canonical roles map to local Status values, not remote GitHub labels.

| Canonical role | Local Status | Meaning |
| --- | --- | --- |
| needs-triage | needs-triage | Maintainer needs to evaluate the work |
| needs-info | needs-info | Waiting for information |
| ready-for-agent | ready-for-agent | Fully specified and suitable for an agent |
| ready-for-human | ready-for-human | Requires human implementation |
| wontfix | wontfix | Will not be actioned |

Use the matching value when a skill names a triage role. Readiness does not authorize implementation, and completion is recorded separately as Resolution in the [local tracker](issue-tracker.md).

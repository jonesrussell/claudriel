# GitHub Workflow Specification

## Repository

`jonesrussell/claudriel` — Claudriel, AI personal operations system

## Versioning Model

Pre-1.0 project. No formal versioning yet. Development is issue-driven with features merged directly to `main`.

## Milestone List

| # | Title | Status | Description |
|---|-------|--------|-------------|
| 2 | v0.2 — Daily Use | CLOSED | Daily loop proven (21 closed issues) |
| 3 | v0.3 — Claudia ↔ MyClaudia Integration | CLOSED | Integration architecture (8 closed issues) |
| 5 | v0.4 — Data Quality | OPEN | Dedup, categorization, person tiering (#67, #68, #69) |
| 6 | v0.5 — Smart Briefs | OPEN | Priority sorting, grouped sections, actionable items. Depends on v0.4. |
| 7 | v0.6 — Multi-Source | OPEN | Beyond Gmail: calendar, Slack, GitHub. Depends on v0.5. |

## Dependency Chain

```
v0.4 Data Quality → v0.5 Smart Briefs → v0.6 Multi-Source
```

You can't build smart briefs until the data is clean, and you can't scale ingestion until the brief logic is smart.

## The 5 Workflow Rules

1. **All work begins with an issue** — ask for issue number before writing code; create one if missing
2. **Every issue belongs to a milestone** — unassigned issues are incomplete triage (currently no milestones)
3. **Milestones define the roadmap** — check active milestone before proposing work; don't invent new ones without discussion
4. **PRs must reference issues** — title format `feat(#N): description`, body with `Closes #N`
5. **Claude reads the drift report** — flag `bin/check-milestones` warnings before beginning work

## Branch Strategy

Feature branches off `main`. PR to `main`. Direct push only for trivial fixes.

## Keeping This Spec Updated

When milestones are created or closed, update the **Milestone List** table above.
When issue #9 is resolved, update the Issue History table.

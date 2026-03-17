---
name: triage-issues
description: Use when triaging GitHub issues, reviewing backlog health, checking for stale or incomplete issues, or when session starts and issue status should be checked. Also triggers on "triage issues", "issue health", "backlog review", "check issues".
---

# Triage Issues

Two-tier GitHub issue triage for Claudriel.

**Tier 1** runs automatically at session start (warnings only, no actions).
**Tier 2** runs on manual request (full analysis + per-action approval).

## Tier 1: Session Start

Run these checks using `gh` CLI. Output warnings only, take no actions.

### Commands

```bash
gh issue list --state open --json number,title,body,updatedAt,milestone --limit 200
gh api repos/{owner}/{repo}/milestones --jq '.[] | {title, number, open_issues}'
```

### Checks

1. **Missing milestones:** issues with no milestone assigned
2. **Empty descriptions:** issues with body empty or under 20 characters
3. **Stale issues:** open issues with `updatedAt` older than 14 days
4. **Stale milestones:** open milestones with zero open issues

### Output Format

```
=== Issue Triage ===
⚠ 2 issues missing milestones: #42, #45
⚠ 1 issue has no description: #45
⚠ 3 stale issues (14+ days): #12, #18, #31
⚠ Stale milestones (no open issues): v1.6 Voice Input, v1.7 Speech Output
✓ 8 issues fully triaged
================
```

Only show warning lines that have findings. When everything passes:

```
=== Issue Triage ===
✓ All 10 issues fully triaged
================
```

**Tier 1 rules:**
- No actions, no offers to act, no follow-up questions
- Keep output under 10 lines
- Do not run duplicate detection (too slow for session start)

## Tier 2: Full Triage

Triggered by manual request. Runs all Tier 1 checks plus deeper analysis, then offers actions.

### Report Sections

Present these sections using structured output format:

**📋 Milestone Health**
Each milestone with: open issue count, % stale (14+ days), oldest issue age.

**⚠️ Quality Gaps**
Issues failing quality bar, grouped by failure type.

**🔍 Potential Duplicates**
Issue pairs with significant title keyword overlap. Strip common words (the, a, an, for, to, in, of, is, it, and, or, with, on, at, by, as). Flag pairs sharing 50%+ of remaining significant words. Skip pairs where both titles have fewer than 3 significant words (too short for meaningful comparison).

**🎯 Action Queue**
Proposed actions based on findings above.

### Action Queue: Per-Action Approval

**Each action is presented and approved individually.** Do not batch actions for group approval. Do not ask "Ready to proceed with all of these?"

Flow for each action:
1. State the action and rationale in one line
2. Wait for response: "yes" (execute), "skip" (next action), "stop" (end queue)
3. Execute if approved, report result
4. Move to next action

Example:
```
1/5: Assign #45 to milestone v1.5 OAuth? (currently unassigned)
> yes
Done. #45 assigned to v1.5.

2/5: Close milestone v1.6 Voice Input? (0 open issues)
> skip

3/5: Comment on #31 requesting status update? (no activity 22 days)
> stop
Action queue ended. 1 action taken, 1 skipped, 3 remaining.
```

### Action Thresholds

Different actions have different staleness thresholds:

| Action | Threshold |
|--------|-----------|
| Flag as stale (report only) | 14+ days no activity |
| Offer to comment requesting update | 14+ days no activity |
| Offer to close as stale | 30+ days no activity |
| Offer to assign milestone | Any issue without milestone |
| Offer to close empty milestone | 0 open issues |

Do not offer to close issues that are only 14 days stale. Comment first.

### What This Skill Does NOT Do

- Create issues
- Modify issue bodies (comments only)
- Manage labels
- Interact with PRs
- Take any action without individual approval
- Run Tier 2 analysis at session start

---

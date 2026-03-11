# Base Structure (All Archetypes)

This file defines the shared skeleton that ALL archetypes include. Each archetype file adds its own unique folders, commands, and templates on top of this base.

---

## Base Directory Structure

Every archetype includes these directories and files at all depth levels:

```
claudriel/
├── CLAUDE.md
├── .claude/
│   ├── commands/
│   │   ├── morning-brief.md
│   │   ├── meeting-prep.md
│   │   ├── capture-meeting.md
│   │   ├── what-am-i-missing.md
│   │   ├── weekly-review.md
│   │   ├── new-person.md
│   │   ├── follow-up-draft.md
│   │   ├── draft-reply.md
│   │   └── summarize-doc.md
│   ├── skills/
│   ├── hooks/
│   └── rules/
├── context/
│   ├── me.md
│   ├── commitments.md
│   ├── waiting.md
│   ├── patterns.md
│   └── learnings.md
└── people/
    └── _template.md
```

## Business Depth Variants

Structure scales with `business_depth` from onboarding:

### Full Business Depth
- All archetype-specific folders with deep per-entity structure
- All business commands added: `/pipeline-review`, `/financial-snapshot`, `/client-health`
- Full template set: meeting-prep, meeting-capture, milestone-plan, weekly-review, plus archetype-specific templates
- Common business folders: `pipeline/` (active, prospecting, completed), `accountability/` (commitments, overdue), `finances/` (overview + archetype extras), `templates/`, `insights/patterns.md`

### Starter Business Depth
- Archetype-specific folders with simplified `_template/` structure
- One business command: `/pipeline-review`
- `pipeline/active.md`, `finances/overview.md`, `templates/meeting-capture.md`

### Minimal Business Depth
- Archetype-specific folders with minimal templates only
- No additional business commands
- Context and people directories only

## Common Templates

### people/_template.md

```markdown
# [Person Name]

## About
| Field | Value |
|-------|-------|
| Role | |
| Organization | |
| Met | [Date] |
| Relationship | [How you know them] |

## Context
[What matters about this person]

## Communication
**Preferred channel:** [Email/Slack/Phone]
**Style notes:** [How to communicate with them]

## History
| Date | Context | Notes |
|------|---------|-------|
| | | |

---
*Created: [Date]*
```

### Pipeline Template (shared across archetypes)

`pipeline/active.md`:

```markdown
# Active Pipeline

## Stages
1. **Prospect** — Initial interest
2. **Discovery** — Had conversation
3. **Proposal** — Proposal sent
4. **Negotiation** — Discussing terms
5. **Verbal** — Awaiting paperwork

## Active Opportunities

| Prospect | Stage | Est. Value | Next Action | Due |
|----------|-------|------------|-------------|-----|
| | | | | |

## Stalled (2+ weeks no activity)
- [Prospect] — last action [date]
```

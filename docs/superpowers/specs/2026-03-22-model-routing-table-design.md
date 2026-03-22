# Model Routing Table Design

**Date:** 2026-03-22
**Issue:** #449 (scoped: config + documentation only, no runtime wiring)

## Goal

Create a static model routing table that maps each Claudriel skill to a model tier (haiku/sonnet/opus). This is the config and documentation deliverable. Runtime model switching is deferred until the eval framework (#445/#446) can validate quality.

## Model Tiers

| Tier | Model ID | Use Case | Cost |
|------|----------|----------|------|
| haiku | claude-haiku-4-5-20251001 | Fast CRUD, simple lookups | Lowest |
| sonnet | claude-sonnet-4-6 | Balanced orchestration, default | Medium |
| opus | claude-opus-4-6 | Deep reasoning, multi-step analysis | Highest |

## Routing Table

Located at `config/model-routing.json`. Schema:

```json
{
  "default": "sonnet",
  "tiers": {
    "haiku": "claude-haiku-4-5-20251001",
    "sonnet": "claude-sonnet-4-6",
    "opus": "claude-opus-4-6"
  },
  "skills": {
    "skill-name": "tier"
  }
}
```

### Assignments

**Haiku** (entity CRUD, simple lookups, low reasoning):
- commitment, new-person, new-workspace, project, schedule-entry, triage-entry, judgment-rule
- databases, fix-duplicates, file-document, diagnose
- Rationale: These follow rigid templates (GraphQL mutations with field mapping). Intent parsing is straightforward.

**Opus** (deep analysis, multi-source synthesis, strategic reasoning):
- deep-context, weekly-review, meeting-prep, what-am-i-missing, meditate
- capture-meeting, ingest-sources, memory-audit
- Rationale: These synthesize across many memories, require nuanced judgment, and produce strategic insights.

**Sonnet** (everything else, balanced orchestration):
- morning-brief, inbox-check, research, brain, brain-monitor, client-health
- financial-snapshot, growth-check, pipeline-review, map-connections
- draft-reply, follow-up-draft, summarize-doc, triage-issues
- memory-health, schedule-analyst
- Rationale: Default tier. These need good reasoning but not maximum depth.

### Background skills (no model field, always active):
- agent-dispatcher, capability-suggester, commitment-detector, connector-discovery
- hire-agent, judgment-awareness, memory-manager, onboarding
- pattern-recognizer, relationship-tracker, risk-surfacer, structure-generator, vault-awareness
- Rationale: These are observation/detection skills that run within the main conversation, not dispatched independently.

## Skill Frontmatter Change

Add optional `model` field to skill SKILL.md frontmatter:

```yaml
---
name: commitment
description: "..."
effort-level: low
model: haiku
---
```

When absent, defaults to `sonnet`. Background skills (no SKILL.md in a directory) don't get the field.

## What This Does NOT Include

- Runtime model switching in the agent subprocess (requires code changes to `main.py` and `SubprocessChatClient`)
- Quality validation via eval framework (#445/#446)
- Fallback chains (#450)
- Cost/performance benchmarks (#450)

These are deferred until the eval framework exists to validate that model downgrades don't degrade quality.

## Testing

- Validate JSON schema of `config/model-routing.json` (well-formed, all skills mapped)
- Verify every skill directory has a `model` field in frontmatter
- No runtime tests (config-only change)

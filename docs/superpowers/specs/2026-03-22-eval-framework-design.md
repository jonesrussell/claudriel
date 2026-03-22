# Eval Framework Design

**Date:** 2026-03-22
**Issues:** #445 (LLM-judge evals), #446 (CI integration)
**Depends on:** #444 (deterministic eval framework, closed)

## Goal

Build an eval runner that executes the existing YAML eval files against Claude, scores outputs with an LLM judge, and integrate into CI to catch skill regressions on every PR.

## Architecture

```
.claude/skills/*/evals/*.yaml  →  eval_runner.py  →  eval_judge.py  →  eval_report.py
                                       ↓                   ↓                  ↓
                                  Send input to       Score output        JSON report
                                  subject model       with judge model    + markdown summary
```

The runner does **simulated invocations**: it sends the skill's SKILL.md content as a system prompt plus the eval input to Claude, gets a response, then judges whether the response satisfies the assertions. It does NOT invoke the full chat agent subprocess or hit the PHP backend.

## Components

### 1. `agent/eval_runner.py` — Orchestrator

Reads YAML eval files, dispatches to appropriate evaluation mode.

**Two modes:**
- `--deterministic`: Schema validation only. Checks that eval YAML files are well-formed, all referenced fields exist in entity definitions, assertion types are valid. No API calls. Fast, free.
- `--llm-judge`: Full evaluation. Sends input to subject model, judges output with judge model. Costs API tokens.

**CLI interface:**
```bash
# Run all evals deterministically (CI hard gate)
python agent/eval_runner.py --deterministic

# Run all evals with LLM judge
python agent/eval_runner.py --llm-judge

# Run specific skill
python agent/eval_runner.py --llm-judge --skill commitment

# Run specific eval type
python agent/eval_runner.py --llm-judge --type trajectory
```

**Output:** JSON to stdout, optionally written to file with `--output path`.

### 2. `agent/eval_judge.py` — LLM Judge

Scores a skill response against eval assertions using Claude as judge.

**Input:** skill name, eval test case (from YAML), actual Claude response text
**Output:** per-assertion score (0-5), overall test score, pass/fail

**Judge prompt pattern:**
```
You are evaluating a Claudriel skill response.

Skill: {skill_name}
User input: {test_input}
Skill response: {actual_response}

Evaluate against these criteria:
{assertions_as_rubric}

For each criterion, score 0-5:
0 = completely wrong
3 = partially correct
5 = perfect

Return JSON: {"scores": [{"assertion": "...", "score": N, "reason": "..."}], "overall": N}
```

**Models:** Judge uses `claude-haiku-4-5-20251001` (cheap). Subject uses the model from the eval YAML `subject_model` field (defaults to `claude-sonnet-4-6`).

### 3. `agent/eval_report.py` — Report Generator

Takes JSON eval results, produces:
- Machine-readable JSON (for CI comparison)
- Markdown summary (for PR comments)
- Per-skill breakdown with pass/fail counts and average scores

**Report JSON schema:**
```json
{
  "timestamp": "2026-03-22T...",
  "mode": "llm-judge",
  "skills": {
    "commitment": {
      "tests_run": 25,
      "tests_passed": 23,
      "average_score": 4.2,
      "failures": [{"test": "...", "reason": "..."}]
    }
  },
  "totals": {
    "tests_run": 150,
    "tests_passed": 142,
    "pass_rate": 0.947
  }
}
```

### 4. `.github/workflows/skill-evals.yml` — CI Workflow

**Triggers:**
- PRs modifying `.claude/skills/**`
- Nightly cron (full suite)

**PR flow:**
1. Run `--deterministic` (hard gate, blocks merge on failure)
2. Run `--llm-judge --skill <changed-skills>` (only skills touched by PR)
3. Compare scores to baseline in `docs/reports/eval-baseline.json`
4. Post summary comment on PR
5. Block merge if any skill regresses >15% from baseline

**Nightly flow:**
1. Run `--llm-judge` (all skills, 3 runs for stability)
2. Store results as GitHub Actions artifacts
3. Update baseline if scores improved

**Secrets:** Uses `ANTHROPIC_API_KEY` (already configured for CI per CLAUDE.md).

### 5. `docs/reports/eval-baseline.json` — Golden Baseline

Committed to repo. Updated when skills intentionally change. Format matches report JSON schema.

## Eval YAML Assertion Types

The existing eval files use these assertion types (from #444):

| Type | Mode | What it checks |
|------|------|----------------|
| `field_extraction` | LLM-judge | Correct field parsed from input |
| `direction_detected` | LLM-judge | outbound/inbound correctly identified |
| `confirmation_shown` | LLM-judge | Skill asks for confirmation |
| `graphql_operation` | Deterministic | Correct GraphQL mutation/query used |
| `table_presented` | LLM-judge | Results shown in table format |
| `filter_applied` | LLM-judge | Correct filter on list operation |
| `resolve_first` | LLM-judge | Skill resolves entity before mutating |
| `disambiguation` | LLM-judge | Ambiguous input triggers options |
| `error_surfaced` | LLM-judge | Error communicated to user |
| `before_after_shown` | LLM-judge | Update shows before/after diff |
| `asks_for_field` | LLM-judge | Missing required field prompts user |
| `no_conjunction_split` | LLM-judge | "and" in titles not split into multiple ops |
| `echo_back_required` | LLM-judge | Destructive ops echo entity name |
| `offers_alternative` | LLM-judge | Failed op suggests alternative |

Deterministic mode validates: YAML schema, assertion type names, field references against entity definitions, test name uniqueness.

## Dependencies

- Python 3.12+ (CI uses Python, agent already uses it)
- `anthropic` SDK (already installed for agent)
- `pyyaml` for eval file parsing
- No additional infrastructure

## What This Does NOT Include

- Actual skill invocation through the chat agent (simulated only)
- End-to-end tests hitting the PHP backend
- Automatic baseline updates (manual for now)
- Eval coverage for non-CRUD skills (only CRUD skills have eval files from #444)

## Testing

- `agent/tests/test_eval_runner.py` — Unit tests for YAML parsing, deterministic validation
- `agent/tests/test_eval_judge.py` — Unit tests for judge prompt construction, score parsing
- `agent/tests/test_eval_report.py` — Unit tests for report generation
- Integration test: run deterministic mode against existing eval files, verify no errors

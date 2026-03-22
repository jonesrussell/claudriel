# Deterministic Eval Framework — Design Spec

**Date:** 2026-03-21
**Issue:** #444
**Milestone:** Agent Reliability & Architecture v1
**Phase:** 1 of 3 (deterministic foundation)
**Status:** Draft

---

## 1. Problem Statement

Claudriel has 6 entity CRUD skills (new-workspace, new-person, commitment, schedule-entry, triage-entry, judgment-rule) with ~95 eval test cases across them. These evals use three incompatible YAML schemas:

| Schema | Skills (directory names) | Root key | Test fields |
|--------|--------------------------|----------|-------------|
| A | new-workspace, new-person, schedule-entry, triage-entry | `prompts[]` | `prompt`, `expectations[]`, `context` (string) |
| B | commitment | `tests[]` | `name`, `input`, `expected[]` (free-text) |
| C | judgment-rule | `tests[]` | `name`, `input`, `existing_rules[]`, `expect{}` (structured) |

**Note:** The `skill` field in eval files uses the directory name (e.g., `new-workspace`, not `workspace`). Schema B (commitment) has no `context` field in existing tests; update/delete tests will need `context.existing_entities` added during migration.

There is no automated validation. No CI gating. No coverage reporting. Eval files can drift from skill behavior silently.

## 2. Goals

1. Define a unified eval YAML schema that all 6 skills use.
2. Build a schema validator that catches structural errors without calling any LLM.
3. Produce machine-readable JSON output for CI gating.
4. Run fast enough for every PR (sub-second on ~95 test cases).
5. Be forward-compatible with promptfoo (Phase 1b) and LLM-judge evals (#445).

## 3. Non-Goals

- LLM execution or token-consuming evals (deferred to #445).
- promptfoo integration (deferred to Phase 1b).
- GraphQL schema drift detection (deferred to #447).
- Model routing (deferred to #449).
- Modifying skill behavior or skill `.md` files.

## 4. Unified Eval YAML Schema

### 4.1 File Location

Each skill's evals live at `.claude/skills/<skill-name>/evals/basic.yaml`. Additional eval files (e.g., `regression.yaml`, `edge-cases.yaml`) follow the same schema.

### 4.2 Schema Definition

```yaml
# Required top-level fields
schema_version: "1.0"        # string, must be "1.0"
skill: "<skill-name>"        # string, must match parent directory name
entity_type: "<type>"         # string, the GraphQL entity type

# Required: at least one test
tests:
  - name: "<kebab-case-id>"             # string, unique within file
    operation: create|list|update|delete # enum, required
    input: "<user prompt>"              # string, required
    context:                            # object, optional
      existing_entities:                # array of objects, optional
        - uuid: "<uuid>"
          fields:                       # key-value pairs matching entity fields
            name: "Acme Corp"
            status: "active"
      notes: "<human-readable context>" # string, optional
    assertions:                         # array, at least one required
      - type: "<assertion-type>"        # string, from registry
        # ... type-specific fields
    tags: []                            # array of strings, optional
```

### 4.3 Assertion Type Registry

Each assertion type has a name, required sub-fields, optional sub-fields, and operation compatibility.

| Type | Required Fields | Optional Fields | Valid Operations |
|------|----------------|-----------------|------------------|
| `field_extraction` | `field` | `must_not_equal`, `should_match`, `must_not_contain` (array) | create, update |
| `graphql_operation` | `operation` | `mutation` (bool, default true) | all |
| `confirmation_shown` | (none) | (none) | create, update, delete |
| `no_file_operations` | (none) | (none) | all |
| `resolve_first` | (none) | (none) | update, delete |
| `error_surfaced` | (none) | `contains` | all |
| `offers_alternative` | `alternative` | (none) | update, delete |
| `disambiguation` | (none) | (none) | update, delete |
| `echo_back_required` | `field` | (none) | delete |
| `secondary_intent_queued` | (none) | `intent` | all |
| `asks_for_field` | `field` | (none) | create, update |
| `direction_detected` | `direction` | (none) | create |
| `no_conjunction_split` | (none) | (none) | all |
| `filter_applied` | `field`, `value` | (none) | list |
| `table_presented` | `columns` | (none) | list |
| `before_after_shown` | (none) | (none) | update |

### 4.4 Structural Validation Rules

These rules are checked by the validator without calling any LLM:

1. **Required top-level fields:** `schema_version`, `skill`, `entity_type`, `tests[]` must be present.
2. **Schema version:** Must be `"1.0"`.
3. **Skill directory match:** `skill` field must match the parent directory name.
4. **Test name uniqueness:** No duplicate `name` values within a file.
5. **Test name format:** Must be kebab-case (`[a-z0-9]+(-[a-z0-9]+)*`).
6. **Required test fields:** `name`, `operation`, `input`, `assertions[]`.
7. **Operation enum:** Must be one of `create`, `list`, `update`, `delete`.
8. **At least one assertion:** Each test must have at least one assertion.
9. **Known assertion type:** Each assertion's `type` must exist in the registry.
10. **Required assertion sub-fields:** Per the registry table above.
11. **Operation compatibility:** Assertion types must be valid for the test's operation (e.g., `resolve_first` only on update/delete).
12. **Context requirement:** Tests with operation `update` or `delete` should have `context.existing_entities` with at least one entry (warning, not error, since error-handling tests may omit it).
13. **Entity field consistency:** `existing_entities[].fields` keys should be consistent within a skill's eval files.
14. **Tag vocabulary:** Tags must be lowercase kebab-case. Unknown tags produce a warning (not error) to allow organic growth.

### 4.5 Cross-File Rules (Coverage)

These rules run across all eval files for all 6 skills:

1. **Operation coverage:** Each skill must have at least one test for each of: `create`, `list`, `update`, `delete`.
2. **Error handling coverage:** Each skill must have at least one test tagged `error-handling` or with an `error_surfaced` assertion.
3. **Edge case coverage:** Each skill must have at least one test tagged `edge-case` or `regression`.
4. **Skill completeness:** All 6 entity skills must have at least one eval file.

## 5. Schema Validator Architecture

### 5.1 Language and Runtime

PHP 8.4. No new dependencies beyond `symfony/yaml` (already present via Waaseyaa).

### 5.2 Directory Structure

```
bin/eval-validate                          # CLI entry point (shebang script)
src/Eval/
├── EvalSchemaValidator.php                # Orchestrator: glob files, run rules, collect results
├── Schema/
│   ├── EvalFileSchema.php                 # Top-level file structure validation
│   ├── TestCaseSchema.php                 # Per-test field validation
│   └── AssertionRegistry.php              # Known types, required fields, operation compat
├── Rules/
│   ├── OperationCoverageRule.php          # Cross-file: all 4 operations covered per skill
│   ├── ResolveFirstRule.php               # update/delete tests must have existing_entities
│   ├── AssertionCompatibilityRule.php     # Assertion type valid for operation
│   ├── UniqueNameRule.php                 # Test names unique within file
│   └── TagConsistencyRule.php             # Tags use known vocabulary
├── Report/
│   ├── ValidationResult.php              # Value object: file, test, severity, message
│   └── JsonReporter.php                  # Renders results as JSON
└── Command/
    └── EvalValidateCommand.php           # Waaseyaa CLI command
```

### 5.3 Non-Entity Eval File Handling

The validator globs `.claude/skills/*/evals/*.yaml`, which may discover eval files for non-entity skills (e.g., diagnose, capture-meeting, meditate, morning-brief). These files lack `schema_version`, `entity_type`, and the entity CRUD structure.

**Handling rule:** Files without `schema_version: "1.0"` are skipped silently. They are not counted in the summary, not validated, and do not produce errors or warnings. This allows non-entity skills to have their own eval formats without conflicting with the entity CRUD validator. A future schema version or separate validator can handle them.

### 5.4 Class Responsibilities

**`EvalSchemaValidator`** — the orchestrator.
- Accepts a skill filter (optional) and strict flag.
- Globs `.claude/skills/*/evals/*.yaml`.
- Parses each file via `symfony/yaml`.
- Skips files without `schema_version: "1.0"` (see 5.3).
- Runs `EvalFileSchema` and `TestCaseSchema` validation on each.
- Runs cross-file rules (`OperationCoverageRule`, etc.) across all parsed files.
- Collects `ValidationResult[]` and passes to `JsonReporter`.

**`AssertionRegistry`** — static registry of assertion types.
- Maps each type name to: required fields, optional fields, compatible operations.
- Single source of truth. Adding a new assertion type means adding one entry here.

**`ValidationResult`** — value object.
- Fields: `file` (string), `test` (string, nullable), `severity` (error/warning), `rule` (string), `message` (string).

**`JsonReporter`** — output formatter.
- Renders `ValidationResult[]` as JSON to stdout or file.
- Includes summary: total files, total tests, errors, warnings, pass/fail.

### 5.5 CLI Interface

```bash
# Validate all skills
php bin/eval-validate

# Validate specific skill
php bin/eval-validate --skill workspace

# Output to file
php bin/eval-validate --output docs/reports/eval-validation.json

# Strict mode (warnings become errors)
php bin/eval-validate --strict

# Quiet mode (Symfony Console built-in, suppresses output)
php bin/eval-validate -q
```

### 5.6 Exit Codes

| Code | Meaning | CI Behavior |
|------|---------|-------------|
| `0` | All valid, no warnings | PR passes |
| `0` | All valid, warnings present (non-strict) | PR passes, warnings logged |
| `1` | Validation errors found | PR blocked |
| `1` | Warnings present in strict mode | PR blocked |
| `2` | Runtime error (file not found, YAML parse failure) | PR blocked |

## 6. JSON Output Format

### 6.1 Passing Validation

```json
{
  "schema_version": "1.0",
  "timestamp": "2026-03-21T14:30:00Z",
  "status": "pass",
  "summary": {
    "files_scanned": 6,
    "tests_scanned": 95,
    "errors": 0,
    "warnings": 2,
    "skills_covered": ["new-workspace", "new-person", "commitment", "schedule-entry", "triage-entry", "judgment-rule"],
    "operation_coverage": {
      "new-workspace": ["create", "list", "update", "delete"],
      "new-person": ["create", "list", "update", "delete"],
      "commitment": ["create", "list", "update", "delete"],
      "schedule-entry": ["create", "list", "update", "delete"],
      "triage-entry": ["create", "list", "update", "delete"],
      "judgment-rule": ["create", "list", "update", "delete"]
    }
  },
  "results": [
    {
      "file": ".claude/skills/new-workspace/evals/basic.yaml",
      "severity": "warning",
      "rule": "TagConsistencyRule",
      "test": null,
      "message": "Unknown tag 'experimental' used in 2 tests"
    }
  ]
}
```

### 6.2 Failing Validation

```json
{
  "schema_version": "1.0",
  "timestamp": "2026-03-21T14:30:00Z",
  "status": "fail",
  "summary": {
    "files_scanned": 6,
    "tests_scanned": 93,
    "errors": 3,
    "warnings": 1,
    "skills_covered": ["new-workspace", "new-person", "commitment", "schedule-entry", "triage-entry", "judgment-rule"],
    "operation_coverage": {
      "new-workspace": ["create", "list", "update", "delete"],
      "new-person": ["create", "list", "update"],
      "commitment": ["create", "list", "update", "delete"],
      "schedule-entry": ["create", "list", "update", "delete"],
      "triage-entry": ["create", "list", "update", "delete"],
      "judgment-rule": ["create", "list", "update", "delete"]
    }
  },
  "results": [
    {
      "file": ".claude/skills/new-person/evals/basic.yaml",
      "severity": "error",
      "rule": "OperationCoverageRule",
      "test": null,
      "message": "Missing operation coverage: delete"
    },
    {
      "file": ".claude/skills/commitment/evals/basic.yaml",
      "severity": "error",
      "rule": "AssertionCompatibilityRule",
      "test": "create-basic",
      "message": "Assertion type 'resolve_first' is not valid for operation 'create' (valid for: update, delete)"
    },
    {
      "file": ".claude/skills/commitment/evals/basic.yaml",
      "severity": "error",
      "rule": "TestCaseSchema",
      "test": "update-missing-input",
      "message": "Required field 'input' is missing"
    },
    {
      "file": ".claude/skills/new-workspace/evals/basic.yaml",
      "severity": "warning",
      "rule": "ResolveFirstRule",
      "test": "delete-no-context",
      "message": "Test with operation 'delete' has no existing_entities in context"
    }
  ]
}
```

## 7. CI Integration Plan

### 7.1 GitHub Actions Workflow

A new workflow file `.github/workflows/eval-validate.yml`:

```yaml
name: Eval Schema Validation
on:
  pull_request:
    paths:
      - '.claude/skills/*/evals/**'
      - 'src/Eval/**'
      - 'bin/eval-validate'

jobs:
  validate-evals:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - run: composer install --no-dev --prefer-dist
      - run: php bin/eval-validate --strict --output eval-report.json
      - name: Upload validation report
        if: always()
        uses: actions/upload-artifact@v4
        with:
          name: eval-validation-report
          path: eval-report.json
      - name: Comment on PR (failures only)
        if: failure()
        uses: actions/github-script@v7
        with:
          script: |
            const fs = require('fs');
            const report = JSON.parse(fs.readFileSync('eval-report.json', 'utf8'));
            const errors = report.results.filter(r => r.severity === 'error');
            const body = `## Eval Validation Failed\n\n${errors.length} error(s) found:\n\n${errors.map(e => `- **${e.rule}** in \`${e.file}\`${e.test ? ` (test: ${e.test})` : ''}: ${e.message}`).join('\n')}\n\nRun \`php bin/eval-validate\` locally to see full details.`;
            github.rest.issues.createComment({ owner: context.repo.owner, repo: context.repo.repo, issue_number: context.issue.number, body });
```

### 7.2 Trigger Conditions

- **On every PR** that touches `.claude/skills/*/evals/**`, `src/Eval/**`, or `bin/eval-validate`.
- **Not triggered** by skill `.md` file changes (that's for end-to-end evals in Phase 1b).
- **Strict mode** in CI (warnings are errors).

### 7.3 Gating Behavior

- Validation errors block merge (required status check).
- Validation report uploaded as artifact on every run.
- PR comment with error details on failure.

## 8. Migration Plan

### 8.1 Scope

Convert all 6 existing `evals/basic.yaml` files from their current schemas to the unified schema.

### 8.2 Migration per Schema Type

**Schema A (new-workspace, new-person, schedule-entry, triage-entry):**
- `prompts[]` becomes `tests[]`
- `prompt` becomes `input`
- Add `name` field (derive from prompt content, kebab-case)
- Add `operation` field (derive from test section comment: CREATE/LIST/UPDATE/DELETE)
- Convert free-text `expectations[]` to typed `assertions[]`
- Convert string `context` to structured `context.existing_entities[]` + `context.notes`
- Add `schema_version`, `skill`, `entity_type` top-level fields
- `skill` field uses directory name (e.g., `new-workspace`, `new-person`)

**Schema B (commitment):**
- Already uses `tests[]`, `name`, `input`
- Convert `expected[]` (free-text) to typed `assertions[]`
- Existing tests have no `context` field; update/delete tests need `context.existing_entities` added with appropriate entity data
- Add `operation` field (derive from test name prefix or section)
- Add `schema_version`, `skill`, `entity_type` top-level fields

**Schema C (judgment-rule):**
- Already uses `tests[]`, `name`, `input`
- Rename test names from underscores to kebab-case (e.g., `create_strips_filler_from_now_on` becomes `create-strips-filler-from-now-on`)
- `existing_rules[]` becomes `context.existing_entities[]` (each rule becomes `{uuid, fields: {rule_text, status}}`)
- `expect{}` maps to typed `assertions[]`:
  - `expect.operation` becomes the test's `operation` field
  - `expect.rule_text` becomes `{type: field_extraction, field: rule_text, should_match: "..."}`
  - `expect.rule_text_must_not_contain` becomes `{type: field_extraction, field: rule_text, must_not_contain: [...]}`
  - `expect.confirms_before_api` becomes `{type: confirmation_shown}`
  - `expect.resolved_uuid` becomes `{type: resolve_first}` + `{type: graphql_operation, ...}`
  - `expect.surfaces_conflict` becomes `{type: error_surfaced, contains: "conflict"}`
  - `expect.does_not_delete` becomes `{type: graphql_operation, operation: updateJudgmentRule, mutation: true}`
  - `expect.fetches_list_before_parsing` becomes `{type: resolve_first}`
- Add `schema_version`, `skill`, `entity_type` top-level fields

### 8.3 Migration Order

1. **judgment-rule** (closest to target schema, smallest delta)
2. **commitment** (Schema B, moderate delta)
3. **new-workspace** (Schema A, reference implementation for the remaining 3)
4. **new-person** (Schema A, copy pattern from new-workspace)
5. **schedule-entry** (Schema A)
6. **triage-entry** (Schema A)

### 8.4 Validation Gate

After migrating each skill's eval file, run `php bin/eval-validate --skill <name>` to confirm it passes. All 6 must pass before the migration PR merges.

## 9. Forward Compatibility with promptfoo

The unified schema is designed to map cleanly to promptfoo's `promptfooconfig.yaml`:

| Unified Schema | promptfoo Equivalent |
|---------------|---------------------|
| `tests[].input` | `tests[].vars.input` |
| `tests[].assertions[type=field_extraction]` | `assert[type=javascript]` with custom function |
| `tests[].assertions[type=graphql_operation]` | `assert[type=contains]` on tool call output |
| `tests[].assertions[type=confirmation_shown]` | `assert[type=llm-rubric]` (Phase 1b) |
| `tests[].context` | `tests[].vars.context` |
| `tests[].tags` | `tests[].metadata.tags` |

A future `bin/eval-to-promptfoo` converter can generate `promptfooconfig.yaml` from the unified YAML. This is out of scope for #444 but the schema supports it.

## 10. Resolved Design Decisions

1. **Skill directory names:** The `skill` field uses actual directory names (`new-workspace`, `new-person`), not shortened forms.
2. **Non-entity eval files:** Skipped silently when `schema_version: "1.0"` is absent (Section 5.3).
3. **Schema C field mapping:** `rule_text_must_not_contain` maps to `field_extraction` assertion's `must_not_contain` sub-field (array of substrings).
4. **Commitment context gap:** Schema B tests have no `context` field; migration adds `context.existing_entities` to update/delete tests.

## 11. Acceptance Criteria (from #444)

- [ ] Unified eval YAML schema documented and adopted by all 6 skills
- [ ] Schema validator runs all evals and produces pass/fail coverage report
- [ ] CI workflow gates PRs that touch eval files
- [ ] All 6 skills' eval files migrated to unified schema and passing validation

# LLM-Judge and Trajectory Evals Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a promptfoo-based eval runner with a custom Anthropic API provider, LLM-judge rubrics, trajectory evals, and multi-turn evals for all 6 entity CRUD skills.

**Architecture:** A custom promptfoo provider (`evals/providers/claudriel-skill-provider.js`) calls the Anthropic Messages API with skill SKILL.md as system prompt and GraphQL operations as tool schemas. promptfoo orchestrates test execution. Haiku judges outputs against rubrics. Trajectory evals test full CRUD lifecycles. Multi-turn evals test conversation context preservation.

**Tech Stack:** Node.js (promptfoo, @anthropic-ai/sdk), YAML rubrics, JSON tool schemas

**Spec:** `docs/superpowers/specs/2026-03-22-llm-judge-trajectory-evals-design.md`

**Issue:** #445

---

## File Map

| Action | Path | Responsibility |
|--------|------|---------------|
| Create | `evals/package.json` | Node project for promptfoo + Anthropic SDK |
| Create | `evals/promptfooconfig.yaml` | Global promptfoo configuration |
| Create | `evals/providers/claudriel-skill-provider.js` | Custom Anthropic API provider with tool support |
| Create | `evals/rubrics/_base.yaml` | Shared 5 criteria (all CRUD skills inherit) |
| Create | `evals/rubrics/commitment.yaml` | Base + direction-detection |
| Create | `evals/rubrics/judgment-rule.yaml` | Base + filler-stripping |
| Create | `evals/rubrics/triage-entry.yaml` | Base + dismiss-vs-delete |
| Create | `evals/rubrics/new-workspace.yaml` | Base only |
| Create | `evals/rubrics/new-person.yaml` | Base only |
| Create | `evals/rubrics/schedule-entry.yaml` | Base only |
| Create | `evals/schemas/commitment.json` | GraphQL tool schemas for commitment |
| Create | `evals/schemas/new-workspace.json` | GraphQL tool schemas for workspace |
| Create | `evals/schemas/new-person.json` | GraphQL tool schemas for person |
| Create | `evals/schemas/schedule-entry.json` | GraphQL tool schemas for schedule-entry |
| Create | `evals/schemas/triage-entry.json` | GraphQL tool schemas for triage-entry |
| Create | `evals/schemas/judgment-rule.json` | GraphQL tool schemas for judgment-rule |
| Create | `evals/mocks/commitment.json` | Default mock tool responses |
| Create | `evals/mocks/new-workspace.json` | Default mock tool responses |
| Create | `evals/mocks/new-person.json` | Default mock tool responses |
| Create | `evals/mocks/schedule-entry.json` | Default mock tool responses |
| Create | `evals/mocks/triage-entry.json` | Default mock tool responses |
| Create | `evals/mocks/judgment-rule.json` | Default mock tool responses |
| Create | `.claude/skills/commitment/evals/trajectory.yaml` | Commitment CRUD lifecycle eval |
| Create | `.claude/skills/commitment/evals/multi-turn.yaml` | Commitment context preservation eval |
| Create | `.claude/skills/new-workspace/evals/trajectory.yaml` | Workspace CRUD lifecycle eval |
| Create | `.claude/skills/new-workspace/evals/multi-turn.yaml` | Workspace context preservation eval |
| Create | `.claude/skills/new-person/evals/trajectory.yaml` | Person CRUD lifecycle eval |
| Create | `.claude/skills/new-person/evals/multi-turn.yaml` | Person context preservation eval |
| Create | `.claude/skills/schedule-entry/evals/trajectory.yaml` | Schedule entry CRUD lifecycle eval |
| Create | `.claude/skills/schedule-entry/evals/multi-turn.yaml` | Schedule entry context preservation eval |
| Create | `.claude/skills/triage-entry/evals/trajectory.yaml` | Triage entry CRUD lifecycle eval |
| Create | `.claude/skills/triage-entry/evals/multi-turn.yaml` | Triage entry context preservation eval |
| Create | `.claude/skills/judgment-rule/evals/trajectory.yaml` | Judgment rule CRUD lifecycle eval |
| Create | `.claude/skills/judgment-rule/evals/multi-turn.yaml` | Judgment rule context preservation eval |
| Create | `src/Eval/Rules/TrajectorySchemaRule.php` | New validator rule for trajectory/multi-turn structure |
| Create | `tests/Unit/Eval/Rules/TrajectorySchemaRuleTest.php` | Tests for trajectory validation |
| Modify | `src/Eval/EvalSchemaValidator.php` | Register TrajectorySchemaRule |
| Create | `evals/scores/baseline.json` | Placeholder for baseline scores |
| Modify | `.gitignore` | Add `evals/node_modules/` |

---

## Task 1: Node.js project setup and promptfoo configuration

**Files:**
- Create: `evals/package.json`
- Create: `evals/promptfooconfig.yaml`
- Create: `evals/scores/baseline.json`
- Modify: `.gitignore`

- [ ] **Step 1: Create evals/package.json**

```json
{
  "name": "claudriel-evals",
  "version": "1.0.0",
  "private": true,
  "description": "LLM-judge and trajectory evals for Claudriel skills",
  "scripts": {
    "eval": "promptfoo eval",
    "eval:view": "promptfoo view",
    "eval:skill": "promptfoo eval --tests"
  },
  "dependencies": {
    "@anthropic-ai/sdk": "^0.39.0",
    "promptfoo": "^0.103.0",
    "yaml": "^2.7.0"
  }
}
```

- [ ] **Step 2: Create evals/promptfooconfig.yaml**

```yaml
defaultTest:
  options:
    provider:
      id: file://providers/claudriel-skill-provider.js
    scoring:
      model: claude-haiku-4-5-20251001

env:
  ANTHROPIC_API_KEY: "{{ANTHROPIC_API_KEY}}"

defaults:
  subject_model: claude-sonnet-4-6
  judge_model: claude-haiku-4-5-20251001
  max_turns: 10
  runs: 3

testMatch:
  - ../.claude/skills/*/evals/trajectory*.yaml
  - ../.claude/skills/*/evals/multi-turn*.yaml
```

- [ ] **Step 3: Create evals/scores/baseline.json**

```json
{
  "version": "1.0",
  "generated_at": null,
  "skills": {}
}
```

- [ ] **Step 4: Add evals/node_modules/ to .gitignore**

Append `evals/node_modules/` to the project `.gitignore`.

- [ ] **Step 5: Install dependencies**

Run: `cd evals && npm install`
Expected: node_modules created, no errors

- [ ] **Step 5b: Verify testMatch paths resolve**

Run: `cd evals && npx promptfoo eval --list 2>&1 | head -10`
Expected: Lists discovered test files from `.claude/skills/*/evals/`. If paths don't resolve, adjust the `../` prefix in `testMatch` to match promptfoo's path resolution (relative to config file location).

- [ ] **Step 6: Commit**

```bash
git add evals/package.json evals/package-lock.json evals/promptfooconfig.yaml evals/scores/baseline.json .gitignore
git commit -m "feat(eval): add promptfoo project setup (#445)"
```

---

## Task 2: Tool schemas for all 6 skills

**Files:**
- Create: `evals/schemas/commitment.json`
- Create: `evals/schemas/new-workspace.json`
- Create: `evals/schemas/new-person.json`
- Create: `evals/schemas/schedule-entry.json`
- Create: `evals/schemas/triage-entry.json`
- Create: `evals/schemas/judgment-rule.json`

Each schema file is a JSON array of Anthropic tool definitions (matching the `tools` parameter of the Messages API). Each skill has 4 tools: create, list, update, delete.

- [ ] **Step 1: Create commitment schema**

`evals/schemas/commitment.json`:
```json
[
  {
    "name": "createCommitment",
    "description": "Create a new commitment",
    "input_schema": {
      "type": "object",
      "properties": {
        "title": { "type": "string", "description": "What is owed" },
        "direction": { "type": "string", "enum": ["outbound", "inbound"] },
        "status": { "type": "string", "enum": ["active", "pending", "completed"] },
        "due_date": { "type": "string", "description": "ISO date" },
        "person_uuid": { "type": "string" }
      },
      "required": ["title"]
    }
  },
  {
    "name": "commitmentList",
    "description": "List commitments with optional filters",
    "input_schema": {
      "type": "object",
      "properties": {
        "status": { "type": "string" },
        "direction": { "type": "string" }
      }
    }
  },
  {
    "name": "updateCommitment",
    "description": "Update an existing commitment",
    "input_schema": {
      "type": "object",
      "properties": {
        "id": { "type": "string", "description": "UUID of the commitment" },
        "title": { "type": "string" },
        "status": { "type": "string" },
        "direction": { "type": "string" },
        "due_date": { "type": "string" }
      },
      "required": ["id"]
    }
  },
  {
    "name": "deleteCommitment",
    "description": "Delete a commitment by UUID",
    "input_schema": {
      "type": "object",
      "properties": {
        "id": { "type": "string", "description": "UUID of the commitment" }
      },
      "required": ["id"]
    }
  }
]
```

- [ ] **Step 2: Create remaining 5 skill schemas**

Follow the same pattern for each skill. Key fields per entity:

| Skill | Create fields | List filters | Label key |
|-------|--------------|-------------|-----------|
| new-workspace | name, description, status, mode | status | name |
| new-person | name, email, tier, source | tier | name |
| schedule-entry | title, starts_at, ends_at, status, location | status | title |
| triage-entry | sender_name, sender_email, summary, status, source | status | sender_name |
| judgment-rule | rule_text, context, status | status | rule_text |

Each file follows the exact same 4-tool structure as commitment. Update field names and descriptions to match the entity.

- [ ] **Step 3: Commit**

```bash
git add evals/schemas/
git commit -m "feat(eval): add GraphQL tool schemas for all 6 skills (#445)"
```

---

## Task 3: Mock tool responses for all 6 skills

**Files:**
- Create: `evals/mocks/commitment.json`
- Create: `evals/mocks/new-workspace.json`
- Create: `evals/mocks/new-person.json`
- Create: `evals/mocks/schedule-entry.json`
- Create: `evals/mocks/triage-entry.json`
- Create: `evals/mocks/judgment-rule.json`

Each mock file maps tool call names to default responses. These are fallback responses used when a trajectory/multi-turn test doesn't provide a per-turn `mock_response`.

- [ ] **Step 1: Create commitment mocks**

`evals/mocks/commitment.json`:
```json
{
  "createCommitment": {
    "uuid": "mock-commit-001",
    "title": "Mock commitment",
    "direction": "outbound",
    "status": "active",
    "created_at": "2026-03-22T00:00:00Z"
  },
  "commitmentList": {
    "total": 1,
    "items": [
      {
        "uuid": "mock-commit-001",
        "title": "Mock commitment",
        "direction": "outbound",
        "status": "active"
      }
    ]
  },
  "updateCommitment": {
    "uuid": "mock-commit-001",
    "title": "Mock commitment",
    "status": "completed"
  },
  "deleteCommitment": {
    "success": true
  }
}
```

- [ ] **Step 2: Create remaining 5 skill mocks**

Follow the same structure for each skill, using entity-appropriate field names and values.

- [ ] **Step 3: Commit**

```bash
git add evals/mocks/
git commit -m "feat(eval): add mock tool responses for all 6 skills (#445)"
```

---

## Task 4: LLM-judge rubrics

**Files:**
- Create: `evals/rubrics/_base.yaml`
- Create: `evals/rubrics/commitment.yaml`
- Create: `evals/rubrics/judgment-rule.yaml`
- Create: `evals/rubrics/triage-entry.yaml`
- Create: `evals/rubrics/new-workspace.yaml`
- Create: `evals/rubrics/new-person.yaml`
- Create: `evals/rubrics/schedule-entry.yaml`

- [ ] **Step 1: Create base rubric**

`evals/rubrics/_base.yaml`:
```yaml
version: "1.0"
skill: _base
description: "Shared evaluation criteria for all entity CRUD skills"

criteria:
  - name: intent-extraction
    weight: 2
    description: "Did the skill correctly extract the operation (create/list/update/delete) and entity fields from the user's natural language input?"
    scoring:
      5: "Perfect extraction. All fields correct, no hallucinated values."
      3: "Correct operation detected but one field wrong or missing."
      1: "Wrong operation or multiple fields incorrect."
      0: "Complete failure to parse intent."

  - name: confirmation-quality
    weight: 1
    description: "Is the confirmation message natural, complete, and showing all relevant fields before the mutation?"
    scoring:
      5: "Clear, natural confirmation with all fields displayed."
      3: "Confirmation present but missing a field or awkwardly phrased."
      1: "Confirmation present but misleading or incomplete."
      0: "No confirmation before mutation."

  - name: resolve-first-correctness
    weight: 2
    description: "For update/delete, did the skill fetch existing entities before parsing, and correctly match the user's reference?"
    scoring:
      5: "Fetched list first, matched correctly, no false splits on conjunctions."
      3: "Fetched list but matching was imprecise."
      1: "Parsed the name from input without fetching existing entities."
      0: "Used the raw input string as the entity identifier."

  - name: error-handling
    weight: 1
    description: "When something goes wrong (not found, invalid input, API error), does the skill surface the error clearly and offer alternatives?"
    scoring:
      5: "Clear error message, offers specific alternative."
      3: "Error surfaced but no alternative offered."
      1: "Error swallowed or vague message."
      0: "Silent failure or retry without explanation."

  - name: tool-call-correctness
    weight: 2
    description: "Did the skill call the correct GraphQL mutation/query with the right arguments?"
    scoring:
      5: "Correct operation, correct arguments, correct field values."
      3: "Correct operation but a field value is wrong or extra fields included."
      1: "Wrong operation called."
      0: "No tool call made when one was required."
```

- [ ] **Step 2: Create skill-specific rubrics**

`evals/rubrics/commitment.yaml`:
```yaml
version: "1.0"
skill: commitment
inherits: _base

criteria:
  - name: direction-detection
    weight: 1
    description: "Did the skill correctly detect outbound vs inbound direction from context clues?"
    scoring:
      5: "Correct direction detected from natural language cues (I owe = outbound, they owe = inbound)."
      3: "Correct direction but only after explicit prompt."
      1: "Wrong direction assigned."
      0: "Direction field omitted entirely."
```

`evals/rubrics/judgment-rule.yaml`:
```yaml
version: "1.0"
skill: judgment-rule
inherits: _base

criteria:
  - name: filler-stripping
    weight: 1
    description: "Did the skill strip filler phrases (from now on, remember that, add a rule to) from the rule text?"
    scoring:
      5: "All filler stripped, rule text is clean and actionable."
      3: "Most filler stripped but one phrase remains."
      1: "Rule text contains multiple filler phrases."
      0: "Full user sentence used as rule text."
```

`evals/rubrics/triage-entry.yaml`:
```yaml
version: "1.0"
skill: triage-entry
inherits: _base

criteria:
  - name: dismiss-vs-delete-distinction
    weight: 1
    description: "Did the skill correctly distinguish 'dismiss' (status change to dismissed) from 'delete' (permanent removal)?"
    scoring:
      5: "Correctly mapped dismiss to updateTriageEntry with status=dismissed."
      3: "Mapped dismiss to update but wrong status value."
      1: "Mapped dismiss to delete operation."
      0: "Confused dismiss and delete entirely."
```

For base-only skills (new-workspace, new-person, schedule-entry):
```yaml
version: "1.0"
skill: new-workspace  # (or new-person, schedule-entry)
inherits: _base

criteria: []
```

- [ ] **Step 3: Commit**

```bash
git add evals/rubrics/
git commit -m "feat(eval): add LLM-judge rubrics for all 6 skills (#445)"
```

---

## Task 5: Custom promptfoo provider

**Files:**
- Create: `evals/providers/claudriel-skill-provider.js`

This is the most complex file. It reads skill SKILL.md, loads tool schemas, calls the Anthropic API, handles multi-turn conversations with mock responses, and returns structured output.

- [ ] **Step 1: Create the provider**

`evals/providers/claudriel-skill-provider.js`:
```javascript
const Anthropic = require("@anthropic-ai/sdk");
const fs = require("fs");
const path = require("path");
const yaml = require("yaml");

const MODEL_ALIASES = {
  sonnet: "claude-sonnet-4-6",
  haiku: "claude-haiku-4-5-20251001",
  opus: "claude-opus-4-6",
};

const MAX_API_CALLS_PER_TURN = 3;

function resolveModel(alias) {
  return MODEL_ALIASES[alias] || alias;
}

function loadSkillContent(skillName, projectRoot) {
  const skillPath = path.join(
    projectRoot,
    ".claude",
    "skills",
    skillName,
    "SKILL.md"
  );
  if (!fs.existsSync(skillPath)) {
    throw new Error(`Skill file not found: ${skillPath}`);
  }
  return fs.readFileSync(skillPath, "utf-8");
}

function loadToolSchemas(skillName, evalsRoot) {
  const schemaPath = path.join(evalsRoot, "schemas", `${skillName}.json`);
  if (!fs.existsSync(schemaPath)) {
    throw new Error(`Tool schema not found: ${schemaPath}`);
  }
  return JSON.parse(fs.readFileSync(schemaPath, "utf-8"));
}

function loadEntityCrudTemplate(projectRoot) {
  const templatePath = path.join(
    projectRoot,
    ".claude",
    "skills",
    "_templates",
    "entity-crud.md"
  );
  if (fs.existsSync(templatePath)) {
    return fs.readFileSync(templatePath, "utf-8");
  }
  return "";
}

function loadDefaultMocks(skillName, evalsRoot) {
  const mockPath = path.join(evalsRoot, "mocks", `${skillName}.json`);
  if (fs.existsSync(mockPath)) {
    return JSON.parse(fs.readFileSync(mockPath, "utf-8"));
  }
  return {};
}

function loadRubric(skillName, evalsRoot) {
  const basePath = path.join(evalsRoot, "rubrics", "_base.yaml");
  const skillPath = path.join(evalsRoot, "rubrics", `${skillName}.yaml`);

  let criteria = [];

  if (fs.existsSync(basePath)) {
    const base = yaml.parse(fs.readFileSync(basePath, "utf-8"));
    criteria = base.criteria || [];
  }

  if (fs.existsSync(skillPath)) {
    const skill = yaml.parse(fs.readFileSync(skillPath, "utf-8"));
    const skillCriteria = skill.criteria || [];
    for (const sc of skillCriteria) {
      const idx = criteria.findIndex((c) => c.name === sc.name);
      if (idx >= 0) {
        criteria[idx] = sc; // override
      } else {
        criteria.push(sc); // append
      }
    }
  }

  return criteria;
}

function getMockResponse(turn, toolName, defaultMocks) {
  if (turn.mock_response && turn.mock_response[toolName]) {
    return turn.mock_response[toolName];
  }
  if (defaultMocks[toolName]) {
    return defaultMocks[toolName];
  }
  return { success: true };
}

async function callApi(prompt, options, context) {
  const evalsRoot = path.resolve(__dirname, "..");
  const projectRoot = path.resolve(evalsRoot, "..");

  // Parse test config from context
  const testConfig = context.vars || {};
  const skillName = testConfig._skill || context.prompt?.skill || "";
  const subjectModel = resolveModel(
    testConfig._subject_model || options.config?.defaults?.subject_model || "sonnet"
  );
  const maxTurns = testConfig._max_turns || options.config?.defaults?.max_turns || 10;

  if (!skillName) {
    return { error: "No skill name provided in test vars._skill" };
  }

  // Load skill content and tools
  const skillContent = loadSkillContent(skillName, projectRoot);
  const entityCrudTemplate = loadEntityCrudTemplate(projectRoot);
  const tools = loadToolSchemas(skillName, evalsRoot);
  const defaultMocks = loadDefaultMocks(skillName, evalsRoot);

  const systemPrompt = [entityCrudTemplate, skillContent]
    .filter(Boolean)
    .join("\n\n---\n\n");

  const client = new Anthropic();
  const messages = [];
  const allToolCalls = [];

  // promptfoo passes eval YAML fields via context.vars.
  // The provider reads skill-specific fields prefixed with _ to avoid conflicts.
  // promptfoo's custom provider interface passes the full test config including
  // `turns`, `rubric`, etc. as vars when using file-based test definitions.
  // If turns aren't in vars, fall back to single-turn mode with the raw prompt.
  const turns = testConfig._turns || testConfig.turns || [{ input: prompt }];

  for (let turnIdx = 0; turnIdx < Math.min(turns.length, maxTurns); turnIdx++) {
    const turn = turns[turnIdx];
    messages.push({ role: "user", content: turn.input });

    let apiCallsThisTurn = 0;
    let continueLoop = true;

    while (continueLoop && apiCallsThisTurn < MAX_API_CALLS_PER_TURN) {
      apiCallsThisTurn++;

      const response = await client.messages.create({
        model: subjectModel,
        max_tokens: 2048,
        system: systemPrompt,
        tools: tools,
        messages: messages,
      });

      // Collect assistant message
      const assistantContent = response.content;
      messages.push({ role: "assistant", content: assistantContent });

      // Check for tool calls
      const toolUses = assistantContent.filter((c) => c.type === "tool_use");

      if (toolUses.length > 0) {
        for (const toolUse of toolUses) {
          allToolCalls.push({
            name: toolUse.name,
            arguments: toolUse.input,
          });

          // Inject mock response
          const mockResult = getMockResponse(turn, toolUse.name, defaultMocks);
          messages.push({
            role: "user",
            content: [
              {
                type: "tool_result",
                tool_use_id: toolUse.id,
                content: JSON.stringify(mockResult),
              },
            ],
          });
        }
        // Continue loop to let model process tool results
        continueLoop = true;
      } else {
        // No tool calls, this turn is done
        continueLoop = false;
      }
    }
  }

  // Extract final text output
  const textBlocks = messages
    .filter((m) => m.role === "assistant")
    .flatMap((m) => (Array.isArray(m.content) ? m.content : []))
    .filter((c) => c.type === "text")
    .map((c) => c.text);

  const output = textBlocks.join("\n\n");

  return {
    output: output,
    tool_calls: allToolCalls,
    metadata: {
      model: subjectModel,
      skill: skillName,
      turns: turns.length,
    },
  };
}

module.exports = { callApi };
```

- [ ] **Step 2: Verify provider loads**

Run: `cd evals && node -e "const p = require('./providers/claudriel-skill-provider.js'); console.log(typeof p.callApi)"`
Expected: `function`

- [ ] **Step 3: Commit**

```bash
git add evals/providers/claudriel-skill-provider.js
git commit -m "feat(eval): add custom promptfoo provider for skill evals (#445)"
```

---

## Task 6: Trajectory eval files for all 6 skills

**Files:**
- Create: `.claude/skills/commitment/evals/trajectory.yaml`
- Create: `.claude/skills/new-workspace/evals/trajectory.yaml`
- Create: `.claude/skills/new-person/evals/trajectory.yaml`
- Create: `.claude/skills/schedule-entry/evals/trajectory.yaml`
- Create: `.claude/skills/triage-entry/evals/trajectory.yaml`
- Create: `.claude/skills/judgment-rule/evals/trajectory.yaml`

Each trajectory eval tests a full create-list-update-delete lifecycle. Use the spec Section 6.2 as the reference format.

- [ ] **Step 1: Create commitment trajectory eval**

Use the exact YAML from the spec Section 6.2 (the `full-crud-lifecycle` test with 4 turns: create, list, update, delete). Copy it to `.claude/skills/commitment/evals/trajectory.yaml`.

- [ ] **Step 2: Create remaining 5 trajectory evals**

Follow the same 4-turn pattern for each skill. Adapt:
- `skill` and `entity_type` fields
- `input` prompts to match the entity (e.g., "create a workspace for Acme Corp", "add Sarah Chen")
- `assertions` to use the correct GraphQL operation names (e.g., `createWorkspace`, `workspaceList`)
- `mock_response` with entity-appropriate field names and values
- `rubric` field pointing to the correct rubric name

Key entity-specific details:

| Skill | Create input | List input | Update input | Delete input |
|-------|-------------|-----------|-------------|-------------|
| new-workspace | "create a workspace for Acme Corp" | "show my workspaces" | "rename Acme Corp to Acme Corporation" | "delete the Acme Corporation workspace" |
| new-person | "add Sarah Chen, VP of Engineering" | "show my contacts" | "change Sarah's email to sarah@new.com" | "remove Sarah Chen" |
| schedule-entry | "schedule a meeting tomorrow at 2pm" | "show my schedule" | "move the meeting to 3pm" | "cancel the meeting" |
| triage-entry | "triage this email from Sarah about the proposal" | "show triage queue" | "dismiss the proposal triage" | "delete the Sarah triage" |
| judgment-rule | "from now on, always CC the team lead" | "show my rules" | "update the CC rule to include managers" | "delete the CC rule" |

- [ ] **Step 3: Commit**

```bash
git add .claude/skills/*/evals/trajectory.yaml
git commit -m "feat(eval): add trajectory evals for all 6 skills (#445)"
```

---

## Task 7: Multi-turn eval files for all 6 skills

**Files:**
- Create: `.claude/skills/commitment/evals/multi-turn.yaml`
- Create: `.claude/skills/new-workspace/evals/multi-turn.yaml`
- Create: `.claude/skills/new-person/evals/multi-turn.yaml`
- Create: `.claude/skills/schedule-entry/evals/multi-turn.yaml`
- Create: `.claude/skills/triage-entry/evals/multi-turn.yaml`
- Create: `.claude/skills/judgment-rule/evals/multi-turn.yaml`

Each multi-turn eval tests context preservation across turns. Use the spec Section 6.3 as the reference format.

- [ ] **Step 1: Create person multi-turn eval (reference implementation)**

Use the spec Section 6.3 `pronoun-resolution` test as the starting point. Add a second test per skill:

```yaml
schema_version: "1.0"
skill: new-person
entity_type: person
eval_type: multi-turn
max_turns: 10
subject_model: sonnet
judge_model: haiku

tests:
  - name: pronoun-resolution
    description: "Create a person, then update using pronouns"
    turns:
      - input: "add Sarah Chen, she's VP of Engineering at Acme"
        operation: create
        assertions:
          - type: graphql_operation
            operation: createPerson
          - type: field_extraction
            field: name
            should_match: "Sarah Chen"
        mock_response:
          createPerson:
            uuid: "test-002"
            name: "Sarah Chen"
      - input: "change her email to sarah@newco.com"
        operation: update
        assertions:
          - type: resolve_first
          - type: graphql_operation
            operation: updatePerson
        mock_response:
          updatePerson:
            uuid: "test-002"
            email: "sarah@newco.com"
    rubric: new-person
    tags: [multi-turn, pronoun-resolution]

  - name: follow-up-delete
    description: "Create a person, then delete referring to them without full name"
    turns:
      - input: "track Dr. Patel, inner circle"
        operation: create
        assertions:
          - type: graphql_operation
            operation: createPerson
        mock_response:
          createPerson:
            uuid: "test-003"
            name: "Dr. Patel"
            tier: "inner_circle"
      - input: "actually, remove that person"
        operation: delete
        assertions:
          - type: resolve_first
          - type: echo_back_required
            field: name
        mock_response:
          deletePerson:
            success: true
    rubric: new-person
    tags: [multi-turn, context-preservation]
```

- [ ] **Step 2: Create remaining 5 multi-turn evals**

Follow the same pattern. Each skill gets 2 tests:
1. **Pronoun/reference resolution**: Create entity, then update/query using pronouns or partial references
2. **Follow-up operation**: Create entity, then perform a different operation without repeating the full identifier

Adapt inputs, operations, and mock responses to match each entity type.

- [ ] **Step 3: Commit**

```bash
git add .claude/skills/*/evals/multi-turn.yaml
git commit -m "feat(eval): add multi-turn evals for all 6 skills (#445)"
```

---

## Task 8: TrajectorySchemaRule for deterministic validator

**Files:**
- Create: `src/Eval/Rules/TrajectorySchemaRule.php`
- Create: `tests/Unit/Eval/Rules/TrajectorySchemaRuleTest.php`
- Modify: `src/Eval/EvalSchemaValidator.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Rules;

use Claudriel\Eval\Rules\TrajectorySchemaRule;
use PHPUnit\Framework\TestCase;

final class TrajectorySchemaRuleTest extends TestCase
{
    public function test_valid_trajectory_produces_no_errors(): void
    {
        $data = [
            'eval_type' => 'trajectory',
            'max_turns' => 10,
            'tests' => [[
                'name' => 'lifecycle',
                'turns' => [
                    ['input' => 'create a thing', 'operation' => 'create', 'assertions' => [['type' => 'confirmation_shown']], 'mock_response' => ['createThing' => ['uuid' => '1']]],
                    ['input' => 'show things', 'operation' => 'list', 'assertions' => [['type' => 'graphql_operation', 'operation' => 'thingList', 'mutation' => false]]],
                ],
                'rubric' => 'test-skill',
            ]],
        ];

        $results = (new TrajectorySchemaRule())->validate($data, 'f.yaml');

        self::assertEmpty($results);
    }

    public function test_missing_turns_produces_error(): void
    {
        $data = [
            'eval_type' => 'trajectory',
            'tests' => [[
                'name' => 'bad-test',
                'rubric' => 'x',
            ]],
        ];

        $results = (new TrajectorySchemaRule())->validate($data, 'f.yaml');

        self::assertNotEmpty($results);
        self::assertStringContainsString('turns', $results[0]->message);
    }

    public function test_turn_missing_operation_produces_error(): void
    {
        $data = [
            'eval_type' => 'trajectory',
            'tests' => [[
                'name' => 'test-1',
                'turns' => [
                    ['input' => 'do something', 'assertions' => [['type' => 'confirmation_shown']]],
                ],
                'rubric' => 'x',
            ]],
        ];

        $results = (new TrajectorySchemaRule())->validate($data, 'f.yaml');

        self::assertNotEmpty($results);
        self::assertStringContainsString('operation', $results[0]->message);
    }

    public function test_missing_rubric_produces_error(): void
    {
        $data = [
            'eval_type' => 'trajectory',
            'tests' => [[
                'name' => 'test-1',
                'turns' => [
                    ['input' => 'x', 'operation' => 'create', 'assertions' => [['type' => 'confirmation_shown']]],
                ],
            ]],
        ];

        $results = (new TrajectorySchemaRule())->validate($data, 'f.yaml');

        self::assertNotEmpty($results);
        self::assertStringContainsString('rubric', $results[0]->message);
    }

    public function test_basic_eval_type_skipped(): void
    {
        $data = [
            'eval_type' => 'basic',
            'tests' => [['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]]],
        ];

        $results = (new TrajectorySchemaRule())->validate($data, 'f.yaml');

        self::assertEmpty($results);
    }

    public function test_no_eval_type_skipped(): void
    {
        $data = [
            'tests' => [['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]]],
        ];

        $results = (new TrajectorySchemaRule())->validate($data, 'f.yaml');

        self::assertEmpty($results);
    }

    public function test_invalid_max_turns_produces_error(): void
    {
        $data = [
            'eval_type' => 'trajectory',
            'max_turns' => -1,
            'tests' => [[
                'name' => 'test-1',
                'turns' => [
                    ['input' => 'x', 'operation' => 'create', 'assertions' => [['type' => 'confirmation_shown']]],
                ],
                'rubric' => 'x',
            ]],
        ];

        $results = (new TrajectorySchemaRule())->validate($data, 'f.yaml');

        self::assertNotEmpty($results);
        self::assertStringContainsString('max_turns', $results[0]->message);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Eval/Rules/TrajectorySchemaRuleTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Write implementation**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Eval\Rules;

use Claudriel\Eval\Report\ValidationResult;

final class TrajectorySchemaRule implements EvalRule
{
    private const TRAJECTORY_TYPES = ['trajectory', 'multi-turn'];
    private const VALID_OPERATIONS = ['create', 'list', 'update', 'delete'];

    /** @return list<ValidationResult> */
    public function validate(array $data, string $file): array
    {
        $evalType = $data['eval_type'] ?? null;

        if ($evalType === null || !in_array($evalType, self::TRAJECTORY_TYPES, true)) {
            return [];
        }

        $results = [];

        if (isset($data['max_turns']) && (!is_int($data['max_turns']) || $data['max_turns'] < 1)) {
            $results[] = ValidationResult::error($file, 'TrajectorySchemaRule', 'max_turns must be a positive integer');
        }

        foreach ($data['tests'] ?? [] as $test) {
            $testName = $test['name'] ?? '(unnamed)';

            if (!isset($test['turns']) || !is_array($test['turns']) || count($test['turns']) === 0) {
                $results[] = ValidationResult::error($file, 'TrajectorySchemaRule', "Test '$testName' must have a non-empty turns array", $testName);
                continue;
            }

            if (!isset($test['rubric'])) {
                $results[] = ValidationResult::error($file, 'TrajectorySchemaRule', "Test '$testName' must have a rubric field", $testName);
            }

            $turnCount = count($test['turns']);
            foreach ($test['turns'] as $i => $turn) {
                if (!isset($turn['input'])) {
                    $results[] = ValidationResult::error($file, 'TrajectorySchemaRule', "Turn #$i in '$testName' must have an input field", $testName);
                }

                if (!isset($turn['operation'])) {
                    $results[] = ValidationResult::error($file, 'TrajectorySchemaRule', "Turn #$i in '$testName' must have an operation field", $testName);
                } elseif (!in_array($turn['operation'], self::VALID_OPERATIONS, true)) {
                    $results[] = ValidationResult::error($file, 'TrajectorySchemaRule', "Turn #$i in '$testName' has invalid operation '{$turn['operation']}'", $testName);
                }

                // mock_response should be present for all turns except the last
                if ($i < $turnCount - 1 && !isset($turn['mock_response'])) {
                    $results[] = ValidationResult::warning($file, 'TrajectorySchemaRule', "Turn #$i in '$testName' has no mock_response (recommended for non-final turns)", $testName);
                }
            }
        }

        return $results;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Eval/Rules/TrajectorySchemaRuleTest.php`
Expected: 7 tests, PASS

- [ ] **Step 5: Make EvalSchemaValidator eval-type-aware**

Read `src/Eval/EvalSchemaValidator.php`. The validator currently runs `TestCaseSchema::validate()` on every test and extracts operations via `array_column($parsed['tests'], 'operation')`. Trajectory/multi-turn tests have `turns[]` instead of top-level `operation`/`input`/`assertions`, so the validator must handle them differently.

Changes to `EvalSchemaValidator::validate()`:

1. **Add `TrajectorySchemaRule`** to `$fileRules` array and add import.

2. **Detect eval_type** early in the per-file loop:
```php
$evalType = $parsed['eval_type'] ?? 'basic';
$isTrajectory = in_array($evalType, ['trajectory', 'multi-turn'], true);
```

3. **Skip `TestCaseSchema` for trajectory files.** Wrap the per-test `TestCaseSchema::validate()` loop in a condition:
```php
if (!$isTrajectory) {
    foreach ($parsed['tests'] as $test) {
        if (is_array($test)) {
            $results = array_merge($results, $this->testCaseSchema->validate($test, $relativePath));
        }
    }
}
```

4. **Extract operations from turns for trajectory files.** Replace the existing `array_column` logic with:
```php
if ($isTrajectory) {
    $ops = [];
    foreach ($parsed['tests'] as $test) {
        foreach ($test['turns'] ?? [] as $turn) {
            if (isset($turn['operation'])) {
                $ops[] = $turn['operation'];
            }
        }
    }
    $ops = array_unique($ops);
} else {
    $ops = array_unique(array_column($parsed['tests'], 'operation'));
}
```

5. **Skip `AssertionCompatibilityRule` and `ResolveFirstRule` for trajectory files** (their per-turn equivalents are handled by `TrajectorySchemaRule`). Change the file rules loop to:
```php
foreach ($this->fileRules as $rule) {
    if ($isTrajectory && ($rule instanceof AssertionCompatibilityRule || $rule instanceof ResolveFirstRule)) {
        continue; // TrajectorySchemaRule handles per-turn validation
    }
    $results = array_merge($results, $rule->validate($parsed, $relativePath));
}
```

- [ ] **Step 6: Update TrajectorySchemaRule to validate per-turn assertion compatibility**

Add assertion compatibility checking within `TrajectorySchemaRule::validate()`. After the existing turn validation, add:

```php
if (isset($turn['operation']) && isset($turn['assertions']) && is_array($turn['assertions'])) {
    foreach ($turn['assertions'] as $assertion) {
        if (isset($assertion['type']) && !AssertionRegistry::isValidForOperation($assertion['type'], $turn['operation'])) {
            $validOps = AssertionRegistry::get($assertion['type'])['operations'] ?? [];
            $results[] = ValidationResult::error(
                $file,
                'TrajectorySchemaRule',
                "Turn #$i assertion type '{$assertion['type']}' not valid for operation '{$turn['operation']}' (valid: " . implode(', ', $validOps) . ")",
                $testName,
            );
        }
    }
}
```

Add import: `use Claudriel\Eval\Schema\AssertionRegistry;`

- [ ] **Step 7: Run full validator test suite**

Run: `vendor/bin/phpunit tests/Unit/Eval/`
Expected: All PASS

- [ ] **Step 8: Run full project test suite**

Run: `vendor/bin/phpunit`
Expected: All PASS

- [ ] **Step 9: Lint**

Run: `vendor/bin/pint --test src/Eval/ tests/Unit/Eval/`
Fix if needed: `vendor/bin/pint src/Eval/ tests/Unit/Eval/`

- [ ] **Step 10: Commit**

```bash
git add src/Eval/Rules/TrajectorySchemaRule.php tests/Unit/Eval/Rules/TrajectorySchemaRuleTest.php src/Eval/EvalSchemaValidator.php
git commit -m "feat(eval): add TrajectorySchemaRule and make validator eval-type-aware (#445)"
```

---

## Task 9: Validate all evals and run full test suite

- [ ] **Step 1: Run deterministic validator on all eval files**

Run: `php bin/eval-validate`
Expected: PASS (all basic, trajectory, and multi-turn files validate)

If trajectory/multi-turn files fail validation, fix the YAML and re-run.

- [ ] **Step 2: Run full PHP test suite**

Run: `vendor/bin/phpunit`
Expected: All PASS

- [ ] **Step 3: Lint PHP**

Run: `vendor/bin/pint --test`
Expected: No formatting issues

- [ ] **Step 4: Verify promptfoo can load the config**

Run: `cd evals && npx promptfoo eval --dry-run 2>&1 | head -20`
Expected: Shows discovered test files, no errors

- [ ] **Step 5: Commit any fixes**

```bash
git add -A
git commit -m "fix(eval): resolve validation issues from full suite run (#445)"
```

---

## Task 10: Score thresholds documentation and baseline

- [ ] **Step 1: Update baseline.json with threshold documentation**

```json
{
  "version": "1.0",
  "generated_at": null,
  "thresholds": {
    "composite_pass": 3.5,
    "regression_warning_percent": 15
  },
  "skills": {}
}
```

The `skills` object will be populated after the first full eval run. Each skill will have per-criterion scores.

- [ ] **Step 2: Commit**

```bash
git add evals/scores/baseline.json
git commit -m "docs(eval): document score thresholds in baseline config (#445)"
```

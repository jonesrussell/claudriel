# Identity & Memory Unification Design

**Date:** 2026-03-09
**Milestone:** v0.5 — Identity & Memory Unification
**Approach:** Vertical slices (7 slices, each a deployable PR)

## Foundational Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Memory ownership | Claudriel PHP owns all memory; Python daemon retired | Single brain, no split state |
| MCP transport | HTTP (Streamable HTTP, not stdio) | Claudriel is already an HTTP service |
| Identity source | Versioned file (CLAUDRIEL.md), served via API | Platform-level, git-tracked, auditable |
| Skill runtime | Split model: PHP for computational, prompts for behavioral | Respects the nature of each skill type |
| Multi-tenancy | Design multi-tenant, implement single-tenant | account_id everywhere, no provisioning UI yet |

---

## 1. Identity Model

**CLAUDRIEL.md** replaces `.claude/rules/claudia-principles.md` as the canonical identity file.

**Location:** `resources/identity/CLAUDRIEL.md` (git-tracked, versioned with the app)

**Contents:**
- Mission statement (what Claudriel is)
- Personality traits, tone, behavioral rules (migrated from `claudia-principles.md`)
- Trust principles (migrated from `trust-north-star.md`)
- Output formatting rules
- What Claudriel never does / always does

**How it's served:**
- `GET /api/identity` returns the raw markdown (for ChatSystemPromptBuilder, dashboard)
- MCP tool `identity.get` returns it to Claude Code sessions
- `ChatSystemPromptBuilder` reads the file directly (same process, no HTTP round-trip)

**What it replaces:**
- `.claude/rules/claudia-principles.md` (personality)
- `.claude/rules/trust-north-star.md` (trust principles)
- The concept of "Claudia" as a separate identity

**What it does NOT replace:**
- `.claude/rules/shell-compatibility.md` (developer tooling, stays in Claude Code config)
- `.claude/rules/data-freshness.md` (developer tooling, stays)
- `CLAUDE.md` (project instructions for Claude Code, stays but references CLAUDRIEL.md)

**Multi-tenant note:** Identity is platform-level, not per-account. All tenants get the same CLAUDRIEL.md.

---

## 2. Memory Model

**Core principle:** Claudriel's existing entities ARE the memory system. No new "memory" abstraction layer.

**Entity-to-memory mapping:**

| Memory concept | Entity | Already exists? |
|---|---|---|
| Events/episodes | McEvent | Yes |
| Obligations | Commitment | Yes |
| People/contacts | Person | Yes |
| Conversations | ChatSession | Yes |
| Messages | ChatMessage | Yes |
| Staleness | DriftDetector | Yes (support class) |
| Capabilities | Skill | Yes |
| Integrations | Integration | Yes |
| Accounts | Account | Yes |

**Schema change:** Add `account_id` (string, indexed) to all entity tables. For now, a single hardcoded account ID is used everywhere. Queries gain `->condition('account_id', $currentAccountId)`.

**Context files** (`context/me.md`, `context/commitments.md`, etc.) become **per-account generated views**, not source-of-truth. The database is canonical. Context files are regenerated on demand or after ingestion, scoped by account_id.

**Context file structure per account:**

```
storage/context/{account_id}/
  me.md              # Account owner profile
  commitments.md     # Generated from active Commitments
  patterns.md        # Generated from recurring event patterns
  people.md          # Generated from Person entities
  brief.md           # Latest brief snapshot
  learnings.md       # Extracted learnings (from memory.remember with type=learning)
```

**Memory read API (MCP tools):**

| MCP tool | Backing query |
|---|---|
| `memory.briefing` | DayBriefAssembler (recent events + pending/drifting commitments) |
| `memory.recall` | Full-text search across McEvent, Commitment, Person (SQL LIKE) |
| `memory.about` | Person lookup by name, return related events + commitments |
| `memory.commitments` | Commitment query with status filter |
| `memory.events` | McEvent query with date/source filter |
| `memory.context` | Return the generated context files for the account |

**Memory write API (MCP tools):**

| MCP tool | Backing operation |
|---|---|
| `memory.remember` | Create McEvent with `source=manual`, type=`memory_note` |
| `memory.update` | Update entity by UUID (Commitment status, Person details, etc.) |
| `memory.delete` | Soft-delete or hard-delete entity by UUID |
| `memory.ingest` | Trigger ingestion pipeline for a raw payload |

**What gets retired:**
- Python claudia-memory daemon (all 33 MCP tools)
- SQLite vector embeddings (SQL LIKE search for now; embeddings are a future enhancement)
- `.claude/rules/memory-availability.md` (rewritten to reference Claudriel MCP)

---

## 3. MCP Server

**Transport:** Streamable HTTP (MCP spec 2025-06-18). Single endpoint, JSON-RPC 2.0.

**Endpoint:** `POST /mcp` and `GET /mcp` registered via ClaudrielServiceProvider routes.

**Architecture:**

```
Claude Code → POST /mcp (JSON-RPC) → McpController → McpRouter → ToolHandler → EntityRepository → DB
                                         ↓
                                   BearerAuthMiddleware → Account lookup → tenant scoping
```

**New classes:**

| Class | Location | Purpose |
|---|---|---|
| `McpController` | `src/Controller/McpController.php` | HTTP entry point, parses JSON-RPC, dispatches to router |
| `McpRouter` | `src/Mcp/McpRouter.php` | Maps `method` + `params.name` to tool handlers |
| `McpToolInterface` | `src/Mcp/McpToolInterface.php` | Contract: `name()`, `description()`, `inputSchema()`, `handle(array $args, Account $account): mixed` |
| `McpSession` | `src/Mcp/McpSession.php` | Session ID management, validates `Mcp-Session-Id` header |

**Request flow:**

1. Client sends `POST /mcp` with `Content-Type: application/json`, `Authorization: Bearer {token}`
2. `BearerAuthMiddleware` resolves token to `Account` entity (or 401)
3. `McpController` parses JSON-RPC envelope
4. For `initialize`: returns server capabilities, sets `Mcp-Session-Id` header
5. For `tools/list`: `McpRouter` collects all registered tools, returns their schemas
6. For `tools/call`: `McpRouter` finds tool by name, calls `handle($args, $account)`, returns result
7. Response is `Content-Type: application/json` (synchronous) for all tools initially

**JSON-RPC examples:**

```json
// tools/list request
{"jsonrpc":"2.0","id":"1","method":"tools/list","params":{}}

// tools/list response
{"jsonrpc":"2.0","id":"1","result":{"tools":[
  {"name":"memory.briefing","description":"Get today's brief","inputSchema":{"type":"object","properties":{}}},
  {"name":"memory.recall","description":"Search memory","inputSchema":{"type":"object","properties":{"query":{"type":"string"}},"required":["query"]}}
]}}

// tools/call request
{"jsonrpc":"2.0","id":"2","method":"tools/call","params":{"name":"memory.briefing","arguments":{}}}

// tools/call response
{"jsonrpc":"2.0","id":"2","result":{"content":[{"type":"text","text":"...brief markdown..."}]}}
```

**Authentication:**
- Bearer token in `Authorization` header
- Token maps to `Account` entity via `AccountRepository::findByToken()`
- Account entity gains a `token` field (hashed, indexed)
- For single-tenant: one pre-generated token, stored in `.env`
- `Origin` header validation for DNS rebinding protection

**`.mcp.json` configuration:**

```json
{
  "mcpServers": {
    "claudriel": {
      "url": "http://localhost:8080/mcp",
      "headers": {
        "Authorization": "Bearer ${CLAUDRIEL_MCP_TOKEN}"
      }
    }
  }
}
```

---

## 4. Skill Model

**Two runtime types, one registry.**

### PHP Skills (server-side, computational)

```php
interface PhpSkillInterface {
    public function name(): string;
    public function category(): string; // ingestion|background|drift|pattern
    public function execute(array $input, Account $account): array;
}
```

**Migration mapping:**

| Current skill | Target | Category |
|---|---|---|
| Commitment detection logic | `src/Skill/Ingestion/CommitmentDetector.php` | ingestion |
| Drift detection | `src/Skill/Drift/DriftDetector.php` (promoted from Support) | drift |
| Brief assembly | `DayBriefAssembler` (no change) | background |
| Pattern detection | `src/Skill/Pattern/PatternDetector.php` | pattern |

### Prompt Skills (behavioral, LLM-native)

Moved from `.claude/skills/` to `resources/skills/`. Exposed via MCP:

| MCP tool | Purpose |
|---|---|
| `skill.list` | Returns all prompt-type skills with name + description |
| `skill.get` | Returns the full markdown content of a named skill |

### Skill Entity Update

The existing `Skill` entity gains fields:

```
runtime: php|prompt
category: ingestion|background|drift|pattern|enricher|workflow
enabled: boolean
config: json (optional per-skill configuration)
account_id: string (tenant scoping)
```

---

## 5. Context Model

**Principle:** Context is per-account, generated from entities, not hand-maintained.

### Context generation

```php
class ContextGenerator {
    public function generate(Account $account, string $type): string;
    public function generateAll(Account $account): void;
}
```

**Triggers for regeneration:**
- After ingestion (EventHandler dispatches a post-ingest event)
- After commitment status change
- On `memory.context` MCP tool call (returns current, regenerates if stale)
- On brief assembly (brief.md is always regenerated fresh)

**Staleness detection:** Each context file stores a generation timestamp in its frontmatter. If the file is older than the latest entity `updated_at` for that type, it's stale and regenerated on next access.

### Context loading into chat sessions

`ChatSystemPromptBuilder` assembles:

```
1. CLAUDRIEL.md (identity, always included)
2. context/{account_id}/me.md (who the user is)
3. context/{account_id}/brief.md (latest brief, truncated to recent)
4. Active prompt skills relevant to the conversation (if any)
```

### Fallback behavior

If context files are missing (new account, first run):
- `me.md`: Returns "No profile configured yet"
- `commitments.md`: Returns "No commitments tracked yet"
- Other files: Omitted from prompt assembly (no error)

---

## 6. Integration Model

**Principle:** Claudriel is the ingestion hub. All external events arrive via HTTP POST.

### Ingest endpoint

```
POST /api/ingest
Authorization: Bearer {token}
Content-Type: application/json

{
  "source": "gmail|calendar|github|slack|rube|claudia",
  "type": "email|event|commit|message|transcript|manual",
  "payload": { ... source-specific data ... }
}
```

**Flow:**

```
External source → POST /api/ingest → BearerAuthMiddleware → Account
  → IngestController → NormalizerRegistry → appropriate Normalizer → Envelope
  → EventHandler → McEvent (saved) + Person (upserted)
  → Pipeline (CommitmentExtraction, etc.)
  → ContextGenerator::generateAll() (regenerate stale context files)
  → BriefSignal (touch file for SSE notification)
```

### Normalizer registry

| Source | Normalizer | Status |
|---|---|---|
| gmail | `GmailMessageNormalizer` | Exists |
| calendar | `CalendarEventNormalizer` | Future |
| github | `GitHubEventNormalizer` | Future |
| slack | `SlackMessageNormalizer` | Future |
| rube | `RubeTranscriptNormalizer` | Future |
| claudia | `ClaudiaForwardNormalizer` | New (this milestone) |
| manual | `ManualEventNormalizer` | New (this milestone) |

New normalizers are built as needed, not all in this milestone. The registry and interface are the deliverable.

### Local Claudia bridge

If a user runs a local Claudia instance, it forwards events:

```
Claudia (local) → POST /api/ingest { source: "claudia", type: "...", payload: {...} }
```

Just another ingestion source. No special protocol.

---

## 7. System Prompt Architecture

**Principle:** Prompts are assembled from composable layers, not monolithic strings.

### Prompt layers (in order)

```
Layer 1: CLAUDRIEL.md              — Identity (always, ~500-800 words)
Layer 2: context/{aid}/me.md       — User profile (always, ~100-200 words)
Layer 3: context/{aid}/brief.md    — Latest brief (always, truncated to today)
Layer 4: Prompt skills             — 0-N skill files, selected by context
Layer 5: Conversation history      — ChatMessage entities for the session
```

### Prompt assembly by context

| Context | Layers included | Assembler |
|---|---|---|
| **Chat (dashboard)** | 1 + 2 + 3 + 4 + 5 | `ChatSystemPromptBuilder` |
| **MCP session (Claude Code)** | 1 + 2 + 3 served on demand via tools | Claude Code assembles its own prompt |
| **Commitment extraction** | Step-specific prompt | `PipelineStepInterface` (unchanged) |

### MCP prompt serving

| Tool | Returns |
|---|---|
| `identity.get` | CLAUDRIEL.md content |
| `memory.context` | All context files for the account |
| `memory.briefing` | Fresh brief |
| `skill.get` | Individual skill content |

---

## 8. Directory Structure

```
claudriel/
├── CLAUDE.md                          # Project instructions for Claude Code
├── composer.json
│
├── resources/
│   ├── identity/
│   │   └── CLAUDRIEL.md               # Canonical identity file
│   ├── skills/                         # Prompt-type skills (behavioral)
│   │   ├── morning-brief.md
│   │   ├── meeting-prep.md
│   │   ├── follow-up-draft.md
│   │   └── ...
│   └── templates/
│       └── dashboard.php
│
├── storage/
│   └── context/                        # Per-account generated context (gitignored)
│       └── {account_id}/
│           ├── me.md
│           ├── commitments.md
│           ├── patterns.md
│           ├── people.md
│           ├── brief.md
│           └── learnings.md
│
├── src/
│   ├── Entity/                         # All gain account_id field
│   │   ├── Account.php
│   │   ├── McEvent.php
│   │   ├── Person.php
│   │   ├── Commitment.php
│   │   ├── ChatSession.php             # gains active_skills (json) field
│   │   ├── ChatMessage.php
│   │   ├── Integration.php
│   │   └── Skill.php                   # gains runtime, category, enabled, config
│   │
│   ├── Mcp/                            # MCP server layer
│   │   ├── McpToolInterface.php
│   │   ├── McpRouter.php
│   │   ├── McpSession.php
│   │   └── Tool/
│   │       ├── MemoryBriefingTool.php
│   │       ├── MemoryRecallTool.php
│   │       ├── MemoryAboutTool.php
│   │       ├── MemoryCommitmentsTool.php
│   │       ├── MemoryEventsTool.php
│   │       ├── MemoryContextTool.php
│   │       ├── MemoryRememberTool.php
│   │       ├── MemoryUpdateTool.php
│   │       ├── MemoryDeleteTool.php
│   │       ├── MemoryIngestTool.php
│   │       ├── IdentityGetTool.php
│   │       ├── SkillListTool.php
│   │       └── SkillGetTool.php
│   │
│   ├── Skill/                          # PHP server-side skills
│   │   ├── PhpSkillInterface.php
│   │   ├── Ingestion/
│   │   │   └── CommitmentDetector.php
│   │   ├── Drift/
│   │   │   └── DriftDetector.php
│   │   └── Pattern/
│   │       └── PatternDetector.php
│   │
│   ├── Context/
│   │   └── ContextGenerator.php
│   │
│   ├── Controller/
│   │   ├── McpController.php
│   │   ├── DashboardController.php
│   │   ├── DayBriefController.php
│   │   ├── ChatController.php
│   │   ├── ChatStreamController.php
│   │   ├── BriefStreamController.php
│   │   ├── IngestController.php
│   │   ├── ContextController.php
│   │   └── CommitmentUpdateController.php
│   │
│   ├── Ingestion/
│   │   ├── Handler/
│   │   │   ├── EventHandler.php
│   │   │   ├── PersonHandler.php
│   │   │   └── CommitmentHandler.php
│   │   ├── Normalizer/
│   │   │   ├── NormalizerRegistry.php
│   │   │   ├── GmailMessageNormalizer.php
│   │   │   ├── ClaudiaForwardNormalizer.php
│   │   │   └── ManualEventNormalizer.php
│   │   └── Pipeline/
│   │       └── CommitmentExtractionStep.php
│   │
│   ├── Domain/
│   │   ├── DayBrief/
│   │   │   ├── DayBriefAssembler.php
│   │   │   └── BriefSessionStore.php
│   │   └── Chat/
│   │       ├── AnthropicChatClient.php
│   │       └── ChatSystemPromptBuilder.php
│   │
│   ├── Support/
│   │   └── BriefSignal.php
│   │
│   ├── Command/
│   │   ├── BriefCommand.php
│   │   ├── CommitmentsCommand.php
│   │   ├── CommitmentUpdateCommand.php
│   │   └── SkillsCommand.php
│   │
│   └── Provider/
│       └── ClaudrielServiceProvider.php
│
├── docs/
│   ├── specs/
│   │   ├── entity.md
│   │   ├── chat.md
│   │   ├── day-brief.md
│   │   ├── ingestion.md
│   │   ├── pipeline.md
│   │   ├── infrastructure.md
│   │   ├── web-cli.md
│   │   ├── workflow.md
│   │   └── mcp.md                      # New
│   └── plans/
│       └── 2026-03-09-identity-memory-unification-design.md
│
├── .claude/
│   └── rules/                          # Slimmed to dev-tooling only
│       ├── shell-compatibility.md
│       └── data-freshness.md
│
└── tests/
    ├── Mcp/
    │   ├── McpControllerTest.php
    │   ├── McpRouterTest.php
    │   └── Tool/
    │       └── ...
    ├── Context/
    │   └── ContextGeneratorTest.php
    └── Skill/
        └── ...
```

---

## 9. Implementation Slices

### Slice 1: MCP Foundation + `memory.briefing`

**Goal:** Prove the full loop: Claude Code → HTTP MCP → Claudriel → DB → response.

**Deliverables:**
- `src/Mcp/McpToolInterface.php`
- `src/Mcp/McpRouter.php`
- `src/Mcp/McpSession.php`
- `src/Mcp/Tool/MemoryBriefingTool.php`
- `src/Controller/McpController.php`
- `Account` entity gains `token` field
- `BearerAuthMiddleware` wired to MCP route
- Routes: `POST /mcp`, `GET /mcp`
- `.mcp.json` configuration for local dev
- Tests for controller, router, tool, auth

**Acceptance:** `tools/call` with `memory.briefing` returns a valid brief via MCP. Claude Code connects and the tool works.

**Dependencies:** None.

---

### Slice 2: Identity

**Goal:** CLAUDRIEL.md is the canonical identity, served via API and MCP.

**Deliverables:**
- `resources/identity/CLAUDRIEL.md` (drafted from claudia-principles.md + trust-north-star.md)
- `src/Mcp/Tool/IdentityGetTool.php`
- `GET /api/identity` route
- `ChatSystemPromptBuilder` reads from `resources/identity/CLAUDRIEL.md`
- `.claude/rules/claudia-principles.md` and `trust-north-star.md` deprecated

**Acceptance:** `identity.get` MCP tool returns CLAUDRIEL.md. Chat system prompt starts with CLAUDRIEL.md.

**Dependencies:** Slice 1.

---

### Slice 3: Memory Read Tools

**Goal:** Claude Code can query Claudriel's memory.

**Deliverables:**
- `MemoryRecallTool`, `MemoryAboutTool`, `MemoryCommitmentsTool`, `MemoryEventsTool`, `MemoryContextTool`
- All entities gain `account_id` field + migration
- All queries scoped by `account_id`
- Tests for each tool

**Acceptance:** All five tools return correct, tenant-scoped results via MCP.

**Dependencies:** Slice 1.

---

### Slice 4: Memory Write Tools

**Goal:** Claude Code can create and modify memory.

**Deliverables:**
- `MemoryRememberTool`, `MemoryUpdateTool`, `MemoryDeleteTool`, `MemoryIngestTool`
- `ManualEventNormalizer`
- Tests for each tool + integration tests

**Acceptance:** Claude Code can store notes, update commitments, delete entities, trigger ingestion.

**Dependencies:** Slice 3.

---

### Slice 5: Context Generation

**Goal:** Context files are generated from entities, not hand-maintained.

**Deliverables:**
- `src/Context/ContextGenerator.php`
- `storage/context/{account_id}/` directory structure
- Context regeneration triggered after ingestion
- `ChatSystemPromptBuilder` reads from generated context
- Fallback behavior for missing context
- Tests for generation + staleness detection

**Acceptance:** After ingesting an event, context files regenerate. Missing files produce graceful fallback.

**Dependencies:** Slice 3, Slice 2.

---

### Slice 6: Skill Migration

**Goal:** Skills classified, moved, and served via MCP.

**Deliverables:**
- `resources/skills/*.md` (prompt skills migrated from `.claude/skills/`)
- `src/Skill/PhpSkillInterface.php`
- `src/Skill/Drift/DriftDetector.php` (promoted from Support)
- `SkillListTool`, `SkillGetTool`
- `Skill` entity gains `runtime`, `category`, `enabled`, `config`
- `ChatSession` gains `active_skills` field
- Skill enrichment in `ChatSystemPromptBuilder`
- Tests for skill tools + prompt assembly

**Acceptance:** `skill.list` and `skill.get` work via MCP. ChatSystemPromptBuilder injects active skills.

**Dependencies:** Slice 1, Slice 5.

---

### Slice 7: Integration Hardening

**Goal:** Ingestion hub is multi-source ready.

**Deliverables:**
- `NormalizerRegistry`
- `ClaudiaForwardNormalizer`
- `IngestController` updated to use registry
- `docs/specs/mcp.md` (new spec)
- All specs updated for new architecture
- `.claude/rules/memory-availability.md` rewritten
- Cleanup deprecated `.claude/skills/` and `context/`

**Acceptance:** Multi-source ingestion works. All specs current. No Python daemon references remain.

**Dependencies:** Slice 4, Slice 6.

---

### Dependency Graph

```
Slice 1 (MCP foundation)
  ├── Slice 2 (Identity)
  │     └── Slice 5 (Context) ──┐
  ├── Slice 3 (Memory read)     │
  │     ├── Slice 4 (Memory write)
  │     └── Slice 5 (Context) ──┤
  │                              └── Slice 6 (Skills)
  └──────────────────────────────────── Slice 7 (Integration hardening)
```

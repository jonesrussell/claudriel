# Agent Subprocess Architecture

Supersedes: `2025-03-09-agent-sidecar-design.md` (archived via PR #191)

## Summary

A Python subprocess that gives Claudriel's chat interface access to Gmail and Google Calendar via Claude's tool-use API. The subprocess communicates with PHP over stdin/stdout using a JSON-lines protocol, and calls back to the PHP backend via internal API endpoints with HMAC authentication.

## Architecture

```
Browser → ChatStreamController (PHP)
           ↓ spawns subprocess
         agent/main.py (Python)
           ↓ Anthropic Messages API (tool-use)
           ↓ tool calls → agent/claudriel_agent/tools/*.py
           ↓ tools call → PHP internal API (HMAC Bearer)
         InternalGoogleController (PHP)
           ↓ OAuthTokenManager → Google APIs
```

## Contract: PHP → Python (stdin)

PHP writes a single JSON object to the subprocess stdin:

```json
{
  "messages": [{"role": "user", "content": "Check my calendar"}],
  "system": "You are Claudriel...",
  "account_id": "acct-uuid-...",
  "tenant_id": "tenant-uuid-...",
  "api_base": "https://claudriel.northcloud.one",
  "api_token": "acct-uuid:1710000000:hmac-signature",
  "model": "claude-sonnet-4-6"
}
```

## Contract: Python → PHP (stdout JSON-lines)

One JSON object per line. **Every line includes `protocol`** (string), the wire-format version emitted by `claudriel_agent.emit.emit()` — currently `1.0`. Hosts may reject unsupported versions; see [Protocol version bump policy](#protocol-version-bump-policy) below.

Event types:

| Event | Fields | Purpose |
|-------|--------|---------|
| *(all)* | `protocol` | Wire-format version (e.g. `1.0`); present on every line |
| `message` | `content` | Streamed text token |
| `tool_call` | `tool`, `args` | Agent invoking a tool |
| `tool_result` | `tool`, `result` | Tool execution result |
| `done` | — | Stream complete |
| `error` | `message` | Error message |
| `progress` | `phase`, `summary`, `level` | Rate-limit / model fallback status for the UI |
| `needs_continuation` | `turns_consumed`, `task_type`, `message` | Agent hit turn budget; session may continue |

Payloads must be strict JSON (no `NaN` / `Infinity`). Implementation: `claudriel_agent.emit.emit()` uses `json.dumps(..., allow_nan=False)`.

**Strict event names (optional):** Set `CLAUDRIEL_EMIT_STRICT=1` in the agent environment to raise on unknown `event` strings (catches typos). If unset, unknown events still emit for backward compatibility. The canonical allowlist is `ALLOWED_EMIT_EVENTS` in `agent/claudriel_agent/emit.py`.

## Source of truth (subprocess tools)

**Python is canonical.** The authoritative set of tools for the stdin/stdout subprocess is whatever `discover_tools()` loads from `agent/claudriel_agent/tools/*.py`. The Tools table below is **derived documentation**: CI asserts parity with discovery (see agent tests). When adding a tool, add the module and update this table in the same change.

**PHP `NativeAgentClient` tools** (`src/Domain/Chat/Tool/*.php`) are a **separate** execution path (in-process tool execution). They are not required to match the full Python subprocess tool list.

## Stderr discipline

- **Success path:** The subprocess must not write to **stderr**. PHP reads JSONL from stdout only; stray stderr breaks log parsing in some environments.
- **Failure path:** Errors are communicated with a single **`error` event on stdout**, then exit code non-zero. Do not duplicate errors on stderr.

## Protocol envelope and ordering

**Terminal event:** Each invocation emits **exactly one** terminal event: `done` or `error`. It must be the **last** JSONL line. No events may follow; there must not be two terminal events.

**Ordering (mechanical):**

- `tool_result` must immediately follow its matching `tool_call` (same `tool` name) in FIFO order when multiple tools run in one turn.
- `message`, `progress`, and `needs_continuation` must not appear **between** a `tool_call` and its `tool_result`.
- Assistant `message` events may appear before `tool_call` in a turn (model returns text + tool_use).
- `progress` may appear before the terminal event (rate limits / model fallback).
- `needs_continuation` may appear before `done` when the turn budget stops after tool results are emitted.

Validation helper: `claudriel_agent.protocol.assert_valid_protocol_stream` (used in tests). It requires a matching `protocol` field on **every** parsed event (same value as `AGENT_PROTOCOL_VERSION` in `emit.py`).

**Allowed I/O:** stdout JSONL (`emit`), stderr empty on success, HTTP only via `PhpApiClient`, Anthropic `messages.create` (and related client calls). Avoid ad-hoc file, socket, or logging writes in the agent path.

## Protocol version bump policy

- **When to bump** — Any change that alters the JSONL wire shape or required fields that downstream consumers rely on: new mandatory keys, renamed events, semantic changes to `tool_result` payload, or a structured tool success/error envelope on the wire. Use a string like `1.0` → `1.1` for additive, backward-compatible changes; use `2.0` (or similar) for breaking changes. Pair wire-format changes with tests and this document.
- **When not to bump** — New tools, tool executor internals, prompts, truncation thresholds, or any change that does not alter the line protocol contract.
- **Unknown versions (hosts)** — If `protocol` is present and not in the host’s supported set, reject with a clear error. If `protocol` is present but empty or whitespace-only, reject (do not treat as legacy). Streams **without** a `protocol` key are **legacy** (pre-version agents). **PHP:** `SubprocessChatClient` inspects the **first** non-empty JSON line: missing `protocol` keeps legacy behavior; present but empty/unsupported terminates with an error.
- **Support horizon** — Claudriel currently deploys the agent image and PHP app together; treat version support as **lockstep** unless you explicitly maintain compatibility with older agent builds.
- **Deferred: tool execution envelope** — Wrapping `tool_result.result` in `{ "ok": true, "result": ... }` / `{ "ok": false, "error": ... }` is a wire-format change and would require a bump (e.g. `1.1`) and host branching. Not required until a consumer needs uniform error surfaces on the stream.

## Tool ergonomics roadmap (post–protocol freeze)

Tracked as follow-up work (separate issues per phase):

1. **Authoring speed** — Scaffold for new tools; shared HTTP/error helpers under `agent/claudriel_agent/tools/`.
2. **Type-safe args** — TypedDict / pydantic (or similar) per tool, validated before `execute`.
3. **Local dev CLI** — Thin wrapper: stdin JSON request → stdout JSONL, for debugging without PHP.
4. **Eval harness** — Scenario fixtures + expected event subsequences or golden stdout in CI.

## Strict Emit System

The emit layer (`agent/claudriel_agent/emit.py`) enforces protocol safety at the Python boundary.

**Allowed event names** are defined as a `frozenset` in `ALLOWED_EMIT_EVENTS`:

```python
ALLOWED_EMIT_EVENTS = frozenset({
    "message", "done", "error", "tool_call",
    "tool_result", "progress", "needs_continuation",
})
```

**Strict mode:** Set `CLAUDRIEL_EMIT_STRICT=1` (also accepts `true`, `yes`) to raise `ValueError` on any event name not in the allowlist. This catches typos during development. When unset or `0`, unknown events still emit for backward compatibility.

**JSON serialization safety:** `emit()` calls `json.dumps(..., allow_nan=False)` so payloads never contain `NaN` or `Infinity`, which are not valid JSON and would break the PHP consumer. Non-serializable payloads raise `ValueError` immediately rather than producing corrupt output.

**Protocol field:** `emit()` sets `protocol` to `AGENT_PROTOCOL_VERSION` after merging kwargs so callers cannot override the wire version.

## Tool Contract

Every tool module in `agent/claudriel_agent/tools/` must export exactly two symbols:

| Export | Type | Purpose |
|--------|------|---------|
| `TOOL_DEF` | `dict` | Anthropic tool definition (must contain a `name` string key) |
| `execute(api, args)` | callable | Synchronous function; receives `PhpApiClient` + tool input dict, returns a result dict |

**No sibling imports.** Tool modules must not import from other tool modules. Each tool is flat and self-contained. Shared behavior belongs in `emit`, `PhpApiClient`, or utility modules outside `tools/`.

**Contract enforcement.** CI runs tests that validate every `*.py` module in `agent/claudriel_agent/tools/` (excluding `__init__.py`) exports a conforming `TOOL_DEF` + `execute` pair and does not import sibling tool modules.

## Tool Discovery

Tools are loaded dynamically at startup by `agent/claudriel_agent/tools_discovery.py`:

1. `discover_tools()` scans `agent/claudriel_agent/tools/*.py` (sorted, skipping `__init__.py`)
2. Each module is imported; modules missing `TOOL_DEF` or a callable `execute` are silently skipped
3. Duplicate tool names (same `TOOL_DEF["name"]`) raise `ValueError`
4. **Optional allowlist:** Set `CLAUDRIEL_AGENT_TOOLS=gmail_list,calendar_list` (comma-separated) to restrict which tools are loaded. Missing configured tools raise `ValueError`
5. `ToolRegistry` wraps discovery with lazy-load semantics (loads once per instance, no process-wide global cache). Call `registry.reset()` in tests to force re-discovery

## Tools

All tools live in `agent/claudriel_agent/tools/` and delegate to the PHP backend via `PhpApiClient`:

| Tool | File | Internal API Endpoint |
|------|------|-----------------------|
| `brief_generate` | `brief_generate.py` | `POST /api/internal/brief/generate` |
| `calendar_create` | `calendar_create.py` | `POST /api/internal/calendar/create` |
| `calendar_list` | `calendar_list.py` | `GET /api/internal/calendar/list` |
| `code_task_create` | `code_task_create.py` | `POST /api/internal/code-tasks/create` |
| `code_task_status` | `code_task_status.py` | `GET /api/internal/code-tasks/{uuid}/status` |
| `commitment_list` | `commitment_list.py` | `GET /api/internal/commitments/list` |
| `commitment_update` | `commitment_update.py` | `POST /api/internal/commitments/{uuid}/update` |
| `event_search` | `event_search.py` | `GET /api/internal/events/search` |
| `github_add_comment` | `github_add_comment.py` | `POST /api/internal/github/comment/{owner}/{repo}/{number}` |
| `github_create_issue` | `github_create_issue.py` | `POST /api/internal/github/issue/{owner}/{repo}` |
| `github_list_issues` | `github_list_issues.py` | `GET /api/internal/github/issues` |
| `github_list_pulls` | `github_list_pulls.py` | `GET /api/internal/github/pulls` |
| `github_notifications` | `github_notifications.py` | `GET /api/internal/github/notifications` |
| `github_read_issue` | `github_read_issue.py` | `GET /api/internal/github/issue/{owner}/{repo}/{number}` |
| `github_read_pull` | `github_read_pull.py` | `GET /api/internal/github/pull/{owner}/{repo}/{number}` |
| `gmail_list` | `gmail_list.py` | `GET /api/internal/gmail/list` |
| `gmail_read` | `gmail_read.py` | `GET /api/internal/gmail/read/{message_id}` |
| `gmail_send` | `gmail_send.py` | `POST /api/internal/gmail/send` |
| `judgment_rule_suggest` | `judgment_rule_suggest.py` | `POST /api/internal/rules/suggest` |
| `person_detail` | `person_detail.py` | `GET /api/internal/persons/{uuid}` |
| `person_search` | `person_search.py` | `GET /api/internal/persons/search` |
| `pipeline_fetch_leads` | `pipeline_fetch_leads.py` | `POST /api/internal/pipeline/fetch-leads` |
| `prospect_list` | `prospect_list.py` | `GET /api/internal/prospects/list` |
| `prospect_update` | `prospect_update.py` | `POST /api/internal/prospects/{uuid}/update` |
| `repo_clone` | `repo_clone.py` | `POST /api/internal/workspaces/{uuid}/clone-repo` |
| `schedule_query` | `schedule_query.py` | `GET /api/internal/schedule/query` |
| `search_global` | `search_global.py` | `GET /api/internal/search/global` |
| `triage_list` | `triage_list.py` | `GET /api/internal/triage/list` |
| `triage_resolve` | `triage_resolve.py` | `POST /api/internal/triage/{uuid}/resolve` |
| `workspace_context` | `workspace_context.py` | `GET /api/internal/workspaces/{uuid}` |
| `workspace_create` | `workspace_create.py` | `POST /api/internal/workspaces/create` |
| `workspace_delete` | `workspace_delete.py` | `POST /api/internal/workspaces/{uuid}/delete` |
| `workspace_list` | `workspace_list.py` | `GET /api/internal/workspaces/list` |

## HMAC Authentication

Internal API endpoints use short-lived HMAC-SHA256 tokens:

- **Generator:** `InternalApiTokenGenerator` (PHP)
- **Format:** `{account_id}:{timestamp}:{signature}`
- **TTL:** 300 seconds
- **Validation:** constant-time comparison via `hash_equals()`
- **Secret:** `AGENT_INTERNAL_SECRET` env var (min 32 bytes, validated at boot)

## HTTP Client (Python)

`agent/util/http.py` provides `PhpApiClient`:

- Uses `api_base` as httpx base URL
- Sets `Authorization: Bearer {api_token}` header
- Sets `X-Account-Id` header
- 30-second timeout

## Key Design Decisions

1. **Python is credential-free.** All Google OAuth tokens are managed by PHP. The Python agent never touches OAuth credentials, scopes, or tokens.
2. **No HTTP server in Python.** The original sidecar design used FastAPI + Uvicorn. The subprocess approach is simpler: stdin/stdout, no port binding, no process management.
3. **Tools call back to PHP.** Rather than giving Python direct Google API access, tools make HTTP requests to the internal API, which handles token refresh and API calls.
4. **The Python agent is an adapter, not a second backend.** It must not own permissions, entity validation, multi-step business workflows, or durable state. Those belong in PHP and internal APIs. Each subprocess run is a clean slate: no global caches or long-lived registries beyond the request.
5. **DRY at the protocol layer, repetition at the tool layer.** Shared behavior belongs in `emit`, `PhpApiClient`, and the Anthropic loop. Individual tools stay flat, explicit, and easy to grep (`TOOL_DEF` + `execute` per file); avoid tool frameworks or shared abstractions that hide HTTP routes.
6. **Contract tests enforce tool shape.** CI runs tests that validate every `agent/claudriel_agent/tools/*.py` module exports a consistent `TOOL_DEF` + synchronous `execute(api, args)` and does not import sibling tool modules.
7. **Protocol validation** — `claudriel_agent.protocol` validates JSONL streams (single terminal `done`/`error`, `tool_call`/`tool_result` pairing). Golden tests and spec parity tests guard subprocess invariants.

## Agent Loop Details

The core loop lives in `agent/claudriel_agent/loop.py` with constants in `agent/claudriel_agent/constants.py`.

### Task Type Classification

`classify_task_type()` inspects the first user message for keyword heuristics to select a turn budget:

| Task Type | Keywords | Default Limit |
|-----------|----------|---------------|
| `quick_lookup` | check, what time, calendar, schedule, who is | 5 |
| `email_compose` | send, email, reply, compose, draft | 15 |
| `brief_generation` | brief, summary, morning, digest | 10 |
| `research` | research, find out, look into, analyze | 40 |
| `onboarding` | — (set via API only) | 30 |
| `general` | (fallback) | 25 |

Turn limits are fetched dynamically from `GET /api/internal/session/limits` at session start. The defaults in `DEFAULT_TURN_LIMITS` apply when the API is unreachable.

### Tool Result Truncation

To control token growth in conversation history, tool results are truncated before appending to messages (full results are still emitted to the frontend via `tool_result` events):

| Threshold | Value | Notes |
|-----------|-------|-------|
| `TOOL_RESULT_MAX_CHARS` | 2000 | General cap for all tool results |
| `GMAIL_BODY_MAX_CHARS` | 500 | `gmail_read` body field gets special handling |

### Rate Limit Handling

On `RateLimitError`, the loop retries with exponential backoff:

- **Max retries:** 3 (per model)
- **Initial backoff:** 5 seconds (doubled each attempt)
- **Max backoff:** 60 seconds
- **Retry-After header:** Honored when present (capped at 60s)
- **Progress events:** Emits `progress` with `phase=rate_limit` during waits so the frontend shows status

### Model Fallback Chains

Two fallback strategies handle different failure modes:

**Degradation (rate limit exhausted):** Steps down to a cheaper model after all retries fail.

```
claude-opus-4-6 → claude-sonnet-4-6 → claude-haiku-4-5-20251001 → (give up)
```

**Escalation (API error, not rate limit):** Steps up to a more capable model.

```
claude-haiku-4-5-20251001 → claude-sonnet-4-6 → claude-opus-4-6 → (give up)
```

Both chains emit `progress` events with `phase=fallback` and `level=warning`.

### Continuation

When the loop approaches the turn limit with pending tool calls (`turns_consumed >= turn_limit - 1`), it emits a `needs_continuation` event instead of silently stopping. The PHP side can send a new request with `"continued": true` to grant a fresh turn budget.

## Evaluation Framework

The agent includes a contract testing and LLM-judge evaluation system for CI. Source files live in `agent/claudriel_agent/eval_*.py`.

### Components

| Module | Purpose |
|--------|---------|
| `eval_schema.py` | YAML schema validation for eval files; assertion type allowlist |
| `eval_contracts.py` | Schema contract validator: checks SKILL.md GraphQL field references against PHP `fieldDefinitions` |
| `eval_judge.py` | LLM judge (Claude Haiku) that scores skill responses on a 0-5 rubric |
| `eval_runner.py` | CLI entry point for running evals in deterministic or LLM-judge mode |

### Eval YAML Format

Eval files live at `.claude/skills/<skill>/evals/*.yaml`. Structure:

```yaml
eval_type: basic          # basic | trajectory | multi-turn
subject_model: claude-sonnet-4-6  # optional, defaults to sonnet
tests:
  - name: "descriptive test name"
    operation: create     # the operation being tested
    input: "user prompt text"
    assertions:
      - type: field_extraction
        field: title
      - type: confirmation_shown
      - type: graphql_operation
        operation: mutation
```

**Eval types:** `basic` tests require `name`, `operation`, `input`. Trajectory / multi-turn tests use `turns` with nested input and only require `name`.

**Assertion types** (validated against `VALID_ASSERTION_TYPES`):

`field_extraction`, `direction_detected`, `confirmation_shown`, `graphql_operation`, `table_presented`, `filter_applied`, `resolve_first`, `disambiguation`, `error_surfaced`, `before_after_shown`, `asks_for_field`, `no_conjunction_split`, `echo_back_required`, `offers_alternative`, `no_file_operations`, `secondary_intent_queued`

### Schema Contract Validation

`eval_contracts.py` cross-references GraphQL field names in SKILL.md files against PHP `fieldDefinitions` in service providers. Fields referenced by skills but missing from the schema are violations. This catches drift between the agent skills and the PHP entity layer.

### Usage

```bash
# Deterministic only (CI-safe, no API calls)
python -m claudriel_agent.eval_runner --deterministic

# LLM-judge evaluation (requires ANTHROPIC_API_KEY)
python -m claudriel_agent.eval_runner --llm-judge
python -m claudriel_agent.eval_runner --llm-judge --skill commitment --type basic

# Schema contract validation
python -m claudriel_agent.eval_contracts
python -m claudriel_agent.eval_contracts --skill commitment --json
```

Pass threshold for LLM-judge: 3.0/5.0. Non-zero exit on any failure.

## Dependencies

- `anthropic>=0.40.0` (Claude tool-use API)
- `httpx>=0.27.0` (HTTP client for internal API calls)

## Execution Modes

| Mode | Config | Command |
|------|--------|---------|
| Docker (production) | `AGENT_DOCKER_IMAGE=claudriel-agent` | `docker run` with stdin pipe |
| Venv (development) | `AGENT_VENV`, `AGENT_PATH` | Direct Python execution |

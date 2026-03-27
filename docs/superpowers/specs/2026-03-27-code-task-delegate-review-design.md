# Code Task: Delegate & Review

**Date**: 2026-03-27
**Status**: Draft

## Problem

Claudriel can manage workspaces and repos, but cannot make code changes. Users want to describe work in chat ("fix the login bug in my-repo") and get a PR back with a summary and diff preview.

## Solution

Add a "delegate and review" flow: Claudriel clones the repo into a workspace, spawns Claude Code CLI to do the work on a branch, creates a GitHub PR, and reports back through chat with a summary and diff preview.

## Architecture

```
Chat message ("fix X in owner/repo")
  → Python agent detects code task intent
  → calls code_task_create tool
  → POST /api/internal/code-tasks/create
  → CodeTask entity saved (status: queued)
  → CLI command dispatched in background: claudriel:code-task-run {uuid}

Background CLI command:
  → CodeTaskRunner::run()
  → git pull on default branch
  → git checkout -b claudriel/{slug}
  → claude --print --output-format stream-json -p "{prompt}"
  → parse output for summary
  → git diff main...HEAD for diff preview
  → git push origin claudriel/{slug}
  → gh pr create --title "..." --body "..."
  → CodeTask entity updated (status: completed, pr_url, summary, diff_preview)

Agent polls or user asks "how's that task?":
  → calls code_task_status tool
  → GET /api/internal/code-tasks/{uuid}/status
  → returns summary + diff preview + PR link in chat
```

## Components

### 1. CodeTask Entity

**Entity type ID**: `code_task`
**Registered in**: `CodeTaskServiceProvider`

| Field | Type | Description |
|---|---|---|
| workspace_uuid | string | Workspace containing the repo |
| repo_uuid | string | Target repo |
| prompt | text_long | User's instruction |
| status | string | queued, running, completed, failed |
| branch_name | string | e.g. claudriel/fix-login-bug |
| pr_url | string (nullable) | GitHub PR URL |
| summary | text_long (nullable) | Claude Code's summary of changes |
| diff_preview | text_long (nullable) | Truncated diff for chat display |
| error | text_long (nullable) | Error message if failed |
| claude_output | text_long (nullable) | Raw JSON output for debugging |
| started_at | string (nullable) | Timestamp |
| completed_at | string (nullable) | Timestamp |

### 2. CodeTaskRunner Service

**Location**: `src/Domain/CodeTask/CodeTaskRunner.php`

Responsibilities:
- Pull latest on default branch
- Create feature branch from default branch
- Invoke Claude Code CLI with the task prompt
- Parse structured JSON output
- Generate diff preview (truncated to ~200 lines)
- Push branch and create PR via `gh` CLI
- Update CodeTask entity with results

**Claude Code invocation**:
```
claude --print \
  --output-format stream-json \
  --allowedTools "Edit,Write,Read,Glob,Grep,Bash" \
  --max-turns 30 \
  -p "{prompt}"
```

**Environment**:
- Working directory: cloned repo path in workspace
- `ANTHROPIC_API_KEY`: from Claudriel config
- Timeout: 10 minutes (configurable)

**Error handling**:
- Non-zero exit: capture stderr, set status to failed
- Timeout: kill process, set status to failed with timeout message
- No changes made: set status to completed with "no changes needed" summary

### 3. CodeTaskServiceProvider

**Location**: `src/Provider/CodeTaskServiceProvider.php`

- Registers `code_task` entity type with field definitions
- Wires `CodeTaskRunner` with database, config, event dispatcher
- Follows `SqlEntityStorage` + `StorageRepositoryAdapter` pattern

### 4. InternalCodeTaskController

**Location**: `src/Controller/InternalCodeTaskController.php`

**Routes** (HMAC Bearer auth, same as other internal API routes):

`POST /api/internal/code-tasks/create`
- Input: `{ repo: "owner/name", prompt: "...", branch_name?: "..." }`
- Creates workspace if repo not already cloned
- Creates CodeTask entity (status: queued)
- Dispatches background CLI command
- Returns: `{ task_uuid, status: "queued" }`

`GET /api/internal/code-tasks/{uuid}/status`
- Returns: `{ status, summary?, diff_preview?, pr_url?, error? }`

### 5. CLI Command: claudriel:code-task-run

**Location**: `src/Command/CodeTaskRunCommand.php`

- Takes `{uuid}` argument
- Loads CodeTask entity, sets status to running
- Calls `CodeTaskRunner::run()`
- Designed to be exec'd in the background from the controller

### 6. Agent Tools

**code_task_create** (`agent/tools/code_task_create.py`)
- Calls `POST /api/internal/code-tasks/create`
- Input schema: `{ repo: string (required), prompt: string (required), branch_name: string (optional) }`
- Returns task UUID and status

**code_task_status** (`agent/tools/code_task_status.py`)
- Calls `GET /api/internal/code-tasks/{uuid}/status`
- Input schema: `{ task_uuid: string (required) }`
- Returns current state with results when completed

### 7. Workspace Setup

When `code_task_create` receives a repo:
1. Look up existing workspaces linked to that repo via WorkspaceRepo junction
2. If found, reuse the existing workspace and its clone (pull latest)
3. If not found, create a workspace named after the repo (e.g. "jonesrussell/my-repo"), clone into `storage/workspaces/{workspace_uuid}/`, and link via WorkspaceRepo junction

This reuses the existing `workspace_create` + `repo_clone` flow from the agent tools. The internal API endpoint orchestrates this before creating the CodeTask.

## Ansible Changes (northcloud-ansible)

### New role: claude-code

**Location**: `roles/claude-code/`

Tasks:
- Install Claude Code CLI: `npm install -g @anthropic-ai/claude-code`
- Verify installation: `claude --version`
- Node.js 22 is already installed via the `node` role (prerequisite)

### New role: github-cli

**Location**: `roles/github-cli/` (or add to `common` role)

Tasks:
- Install `gh` CLI via apt (official GitHub apt repository)
- Authenticate: `gh auth login` with a GitHub token from vault
- Needed for `gh pr create` in CodeTaskRunner

### Vault additions

- `vault_claudriel_github_token`: Personal access token for `gh` CLI (needs `repo` scope for PR creation)
- Add to `inventory/host_vars/razor-crest/vault.yml`

### Playbook integration

Add `claude-code` and `github-cli` roles to `site.yml` webserver play, after `node` role.

## Branch Naming

Auto-generated from the prompt:
- Slugify first ~50 chars of the prompt
- Prefix with `claudriel/`
- Example: "fix the login bug" → `claudriel/fix-the-login-bug`
- User can override via `branch_name` parameter

## Diff Preview

The diff shown in chat is truncated for readability:
- Max 200 lines
- If longer, show first 150 lines + "... and N more lines. See full diff on GitHub."
- Formatted as a code block in the chat message

## Security Considerations

- Claude Code runs as the `deployer` user with access to the cloned repo only
- `--allowedTools` restricts what Claude Code can do (no Agent tool, no web access)
- HMAC Bearer auth on internal API endpoints (existing pattern)
- GitHub token scoped to `repo` only
- Timeout prevents runaway processes

## Future Phases (out of scope)

- **Phase 2 (Workspace UI trigger)**: Button on workspace detail page to create a code task
- **Phase 3 (GitHub issue trigger)**: Label/assign an issue, Claudriel picks it up
- **Phase 4 (Live streaming)**: Stream Claude Code progress events through SSE to chat
- **Phase 5 (In-app diff viewer)**: Render diffs inside Claudriel's UI instead of linking to GitHub
- **Phase 6 (SDK migration)**: Replace CLI spawn with Claude Code Agent SDK for richer control

## Dependencies

- Claude Code CLI installed on server (Ansible)
- `gh` CLI installed and authenticated (Ansible)
- GitHub token with `repo` scope (Ansible vault)
- Existing workspace + repo clone infrastructure
- Existing internal API auth (HMAC Bearer)

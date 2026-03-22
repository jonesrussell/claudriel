# Workspace Agent Tools Design

**Date:** 2026-03-22
**Status:** Draft
**Issue:** Intent-parsing bug in hardcoded workspace creation; missing multi-step workflow support

## Problem

`ChatStreamController` has hardcoded regex patterns for workspace creation/deletion ("local actions"). These bypass the agent subprocess and cause intent-parsing bugs: the full sentence tail gets used as the workspace name (e.g., "and clone jonesrussell/me so we can do some milestone planning").

The chat UI also cannot orchestrate multi-step workflows (create workspace + clone repo + triage issues) because the hardcoded path has no access to `GitRepositoryManager` or GitHub tools.

## Solution

Move workspace lifecycle and repo cloning into the agent subprocess as tool calls. Remove the hardcoded local actions from `ChatStreamController`. The agent (Claude) handles intent parsing naturally and orchestrates multi-step workflows via sequential tool calls.

## Example Flow

User says: "create a workspace and clone jonesrussell/me so we can do some milestone planning"

1. Agent extracts: name = "me", repo = "jonesrussell/me", secondary intent = milestone planning
2. Agent calls `workspace_create(name: "me", description: "jonesrussell/me milestone planning")`
3. Agent calls `repo_clone(workspace_id: "<uuid>", repo: "jonesrussell/me")`
4. Agent calls `github_list_issues(repo: "jonesrussell/me")` (existing tool)
5. Agent presents findings and suggests milestone structure

## New Agent Tools

### workspace_create

```json
{
  "name": "workspace_create",
  "description": "Create a new workspace. Extract a short, descriptive name from the user's request (prefer repo name, project name, or client name). NEVER use the full user sentence as the name.",
  "input_schema": {
    "type": "object",
    "properties": {
      "name": {
        "type": "string",
        "description": "Short workspace name (1-3 words). Prefer repo name or project identifier."
      },
      "description": {
        "type": "string",
        "description": "Optional description of the workspace purpose."
      },
      "mode": {
        "type": "string",
        "enum": ["persistent", "ephemeral"],
        "default": "persistent"
      }
    },
    "required": ["name"]
  }
}
```

**Internal route:** `POST /api/internal/workspaces/create`
**Behavior:** Validates name (non-empty, max 100 chars), creates Workspace entity via `SqlEntityStorage`, returns `{ uuid, name, status, created_at }`.

### workspace_delete

```json
{
  "name": "workspace_delete",
  "description": "Delete a workspace. Always list workspaces first to resolve the correct one. Require the user to confirm by echoing the workspace name before calling this tool.",
  "input_schema": {
    "type": "object",
    "properties": {
      "uuid": {
        "type": "string",
        "description": "UUID of the workspace to delete."
      }
    },
    "required": ["uuid"]
  }
}
```

**Internal route:** `DELETE /api/internal/workspaces/{uuid}`
**Behavior:** Deletes the workspace entity. Returns `{ success: true }` or error.

### repo_clone

```json
{
  "name": "repo_clone",
  "description": "Clone a Git repository into a workspace directory. Only clone public repos or repos the user has access to.",
  "input_schema": {
    "type": "object",
    "properties": {
      "workspace_uuid": {
        "type": "string",
        "description": "UUID of the workspace to clone into."
      },
      "repo": {
        "type": "string",
        "description": "Repository in owner/name format (e.g., 'jonesrussell/me')."
      },
      "branch": {
        "type": "string",
        "description": "Branch to clone. Defaults to the repo's default branch.",
        "default": "main"
      }
    },
    "required": ["workspace_uuid", "repo"]
  }
}
```

**Internal route:** `POST /api/internal/workspaces/{uuid}/clone-repo`
**Behavior:**
- Validates `repo` format: must match `^[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+$`
- Constructs URL: `https://github.com/{repo}.git`
- Calls `GitRepositoryManager::buildWorkspaceRepoPath($uuid)` for the target path
- Calls `GitRepositoryManager::clone()` to that path
- Stores result in `Artifact` entity (consistent with existing `WorkspaceCloneCommand`)
- Returns `{ success: true, local_path, branch }` or error with message

## File Changes

### New Files

| File | Purpose |
|------|---------|
| `agent/tools/workspace_create.py` | Tool definition + executor for workspace creation |
| `agent/tools/workspace_delete.py` | Tool definition + executor for workspace deletion |
| `agent/tools/repo_clone.py` | Tool definition + executor for repo cloning |

### Modified Files

| File | Change |
|------|--------|
| `src/Controller/InternalWorkspaceController.php` | Add `create()`, `delete()`, `cloneRepo()` methods (controller already exists with `list` and `get`) |
| `src/Provider/WorkspaceToolServiceProvider.php` | Register 3 new internal routes (alongside existing workspace tool routes) |
| `src/Controller/ChatStreamController.php` | Remove `extractWorkspaceName()`, `extractWorkspaceDeletionNames()`, and the local action branch in the stream handler |
| `agent/main.py` | Register new tools (if not auto-discovered from tools/ directory) |

## Internal API Routes

All routes use the existing HMAC Bearer auth pattern (`InternalApiTokenGenerator`).

```
POST   /api/internal/workspaces/create       → InternalWorkspaceController::create()
DELETE /api/internal/workspaces/{uuid}        → InternalWorkspaceController::delete()
POST   /api/internal/workspaces/{uuid}/clone-repo → InternalWorkspaceController::cloneRepo()
```

## PHP Controller Sketch

```php
class InternalWorkspaceController
{
    public function __construct(
        private EntityRepositoryInterface $workspaceRepo,
        private GitRepositoryManager $gitManager,
    ) {}

    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        // Validate: name required, max 100 chars
        $mode = $data['mode'] ?? 'persistent';
        $status = $data['status'] ?? 'active';
        $description = $data['description'] ?? '';
        // Create workspace entity with name, description, mode, status
        // Return { uuid, name, status, created_at }
    }

    public function delete(Request $request, string $uuid): JsonResponse
    {
        // Find workspace by UUID
        // Delete entity
        // Return { success: true }
    }

    public function cloneRepo(Request $request, string $uuid): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        // Validate repo format
        // Find workspace by UUID
        // Clone via GitRepositoryManager
        // Return { success: true, local_path, branch }
    }
}
```

## Agent System Prompt Addition

Add to the workspace tools section of the agent system prompt:

```
When creating workspaces, choose a short descriptive name (1-3 words):
- From a repo like "jonesrussell/me" → name: "me"
- From a project description → name: the project name
- Never use the full user sentence as the workspace name
- If unsure what to name it, ask the user

For destructive operations (delete), always:
1. List workspaces first to resolve the target
2. Show the workspace details to the user
3. Ask the user to confirm by echoing the workspace name
4. Only then call workspace_delete
```

## Error Handling

Simple structured errors returned from PHP:

```json
{
  "error": {
    "code": "VALIDATION",
    "message": "Workspace name is required and must be 1-100 characters."
  }
}
```

Error codes: `VALIDATION`, `NOT_FOUND`, `CLONE_FAILED`, `ALREADY_EXISTS`.

The agent surfaces these to the user in natural language.

## Testing

- **Unit tests:** `InternalWorkspaceController` create/delete/clone with mocked dependencies
- **Integration test:** Full tool call round-trip (agent tool → PHP route → entity layer)
- **Trajectory eval:** `evals/` YAML for the multi-step "create workspace and clone repo" flow
- **Manual smoke test:** Open chat UI, type the original failing sentence, verify correct behavior

## Migration

1. Deploy new tools and routes
2. Remove local action code from ChatStreamController
3. Delete garbage workspaces via chat UI (dogfooding)
4. Verify account and integrations are preserved

## Out of Scope (deferred)

- Token rotation, per-tool scopes, RBAC (single user)
- Async job model for clone (repos are small)
- Feature flags, canary rollouts
- Metrics dashboards, alerting
- Idempotency keys
- Audit queue (Kafka/SQS)

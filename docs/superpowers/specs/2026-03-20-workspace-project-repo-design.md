# Workspace, Project, and Repo Entities Design

**Date:** 2026-03-20
**Status:** Draft (Rev 2, addressing spec review)
**Scope:** Minimal entity model for multi-repo workspace management. No task tracking, no issue management, no duplication of GitHub.

## Problem

Claudriel's workspace setup is chatty and repo-centric. Users want to:

1. Say "set up a workspace for waaseyaa/minoo" and have it just work (no questions)
2. Group multiple repos under a project (e.g., "Waaseyaa" contains minoo, waaseyaa-core, etc.)
3. Create workspaces as working contexts containing multiple projects and repos
4. Switch between workspaces during a conversation
5. Add or remove repos and projects from workspaces at will

## Constraints

- GitHub remains the source of truth for issues, PRs, and project boards
- No tasks, epics, milestones, or issue tracking in Claudriel
- Waaseyaa's entity system has no native relationship support; `findBy()` only supports exact-match scalar queries
- JSON arrays in `_data` are unqueryable; many-to-many relationships require junction entities
- Agent runs via Docker per-request (stateless); "active workspace" is session-scoped
- Pre-push hook blocks `curl_exec`; HTTP requests use `file_get_contents` with stream context
- `SqlSchemaHandler::ensureTable()` only creates tables, never alters existing ones; new entity types get tables on first boot after registration

## Existing State

### Project entity (already exists)

`src/Entity/Project.php` and `ProjectServiceProvider` already exist with fields: `name`, `description`, `status`, `metadata`, `settings`, `context`, `account_id`, `tenant_id`. Entity type ID: `project`, keys: `['id' => 'prid', 'uuid' => 'uuid', 'label' => 'name']`.

**No changes needed.** The existing Project entity already has the fields this design requires.

### Workspace entity (already exists)

`src/Entity/Workspace.php` has scalar fields `repo_path`, `repo_url`, `branch`, `project_id` for single-repo association.

**Migration:** These fields are deprecated in favor of junction entities. Existing data will be migrated: for each workspace with `repo_path`/`repo_url` set, create a Repo entity and WorkspaceRepo junction. For each workspace with `project_id` set, create a WorkspaceProject junction. After migration, the scalar fields remain in the entity class (no breaking change) but are no longer written to by new code. A future cleanup can remove them.

## Data Model

### New Entity: Repo

Entity type ID: `repo`. Keys: `['id' => 'rid', 'uuid' => 'uuid', 'label' => 'name']`.

| Field | Type | Notes |
|-------|------|-------|
| rid | integer, readOnly | auto-increment primary key |
| uuid | string, readOnly | auto-generated |
| name | string, required | "minoo" |
| description | string | from GitHub, may be empty |
| remote_url | string, required | SSH URL: `git@github.com:waaseyaa/minoo.git` |
| local_path | string | local clone path, empty if not cloned |
| default_branch | string | "main" |
| github_owner | string | "waaseyaa" |
| github_name | string | "minoo" |
| account_id | string | nullable, owning account |
| tenant_id | string | defaults to `CLAUDRIEL_DEFAULT_TENANT` |

### Existing Entity: Project (no changes)

Uses existing `src/Entity/Project.php` and `ProjectServiceProvider` as-is.

### Existing Entity: Workspace (no field changes)

Junction entities handle relationships. Existing scalar fields (`repo_path`, `repo_url`, `project_id`) deprecated but not removed.

### New Junction Entities

Junction entities are infrastructure for many-to-many relationships. They follow the same `ContentEntityBase` pattern but are lightweight.

**ProjectRepo**

Entity type ID: `project_repo`. Keys: `['id' => 'prrid', 'uuid' => 'uuid', 'label' => 'uuid']`.

| Field | Type | Notes |
|-------|------|-------|
| prrid | integer, readOnly | auto-increment primary key |
| uuid | string, readOnly | auto-generated |
| project_uuid | string, required | FK to Project |
| repo_uuid | string, required | FK to Repo |
| tenant_id | string | defaults to `CLAUDRIEL_DEFAULT_TENANT` |

**WorkspaceProject**

Entity type ID: `workspace_project`. Keys: `['id' => 'wpid', 'uuid' => 'uuid', 'label' => 'uuid']`.

| Field | Type | Notes |
|-------|------|-------|
| wpid | integer, readOnly | auto-increment primary key |
| uuid | string, readOnly | auto-generated |
| workspace_uuid | string, required | FK to Workspace |
| project_uuid | string, required | FK to Project |
| tenant_id | string | defaults to `CLAUDRIEL_DEFAULT_TENANT` |

**WorkspaceRepo**

Entity type ID: `workspace_repo`. Keys: `['id' => 'wrid', 'uuid' => 'uuid', 'label' => 'uuid']`.

| Field | Type | Notes |
|-------|------|-------|
| wrid | integer, readOnly | auto-increment primary key |
| uuid | string, readOnly | auto-generated |
| workspace_uuid | string, required | FK to Workspace |
| repo_uuid | string, required | FK to Repo |
| tenant_id | string | defaults to `CLAUDRIEL_DEFAULT_TENANT` |

### Junction Entity Design Decisions

- **Uniqueness:** Before creating a junction, query `findBy(['project_uuid' => $x, 'repo_uuid' => $y])`. If non-empty, skip creation. This is application-level uniqueness (no DB constraint).
- **Deletion:** When a Project or Workspace is deleted, the deleting code must also delete associated junction entities. No cascade; explicit cleanup in the service layer.
- **GraphQL:** Junction entities get `fieldDefinitions` for schema generation but are not expected to be queried directly by the frontend. The `workspace/switch` internal API endpoint resolves junctions server-side and returns denormalized data.
- **Label field:** Junction entities use `uuid` as label since they have no natural human-readable name.

### Relationships

- A repo can belong to multiple projects (via ProjectRepo)
- A project can belong to multiple workspaces (via WorkspaceProject)
- A workspace can contain both projects and standalone repos (via WorkspaceProject and WorkspaceRepo)
- All relationships queryable from both sides via `findBy()` on junction entities

## Agent Tools

Four tools, all autonomous. No questions asked.

### workspace_setup

- **Input:** GitHub repo URL, org name, or `owner/repo` string
- **Behavior:**
  1. Hit GitHub API to get repo metadata (name, description, SSH URL, default branch)
  2. Check if Repo entity exists for this `remote_url` via `findBy()`; create if not
  3. Check if Project entity exists with name matching `github_owner` via `findBy(['name' => $owner])`; create if not
  4. Check for existing ProjectRepo junction; create if not
  5. Check if local clone exists at `~/dev/{repo_name}`; record `local_path` if found, leave empty if not
  6. Create or find a Workspace named after the project
  7. Create WorkspaceProject and WorkspaceRepo junctions (with uniqueness checks)
  8. Return summary of what was created/linked
- **No questions asked.** Everything derived from GitHub API + local filesystem.
- **GitHub auth:** Uses the user's GitHub integration OAuth token stored in the Integration entity (same pattern as `GoogleTokenManager`). Falls back to unauthenticated GitHub API for public repos.
- **Org-as-project heuristic:** The `github_owner` becomes the project name by default. This means personal repos under `jonesrussell/` would group under a "jonesrussell" project. This is a known simplification; `workspace_add_repo` with an explicit project target can override it.

### workspace_switch

- **Input:** Workspace name (case-insensitive match)
- **Behavior:**
  1. Fetch all workspaces via `findBy(['tenant_id' => $tenantId])`
  2. Match by case-insensitive name comparison in PHP (small dataset, acceptable)
  3. Load related projects via `WorkspaceProject` junctions, then repos via `ProjectRepo` junctions
  4. Load standalone repos via `WorkspaceRepo` junctions
  5. Return denormalized workspace context
- **Returns:** Workspace name, list of projects (each with their repos and local paths), list of standalone repos

### workspace_add_project

- **Input:** Project name or UUID, workspace name or UUID
- **Behavior:** Finds project and workspace by UUID or name (name lookup: fetch all, match in PHP). Creates WorkspaceProject junction with uniqueness check. Creates project entity if name doesn't match any existing project.

### workspace_add_repo

- **Input:** GitHub `owner/repo` or repo UUID, plus explicit `target_type` ("project" or "workspace") and `target` (name or UUID)
- **Behavior:** Creates/finds Repo entity (GitHub API lookup if `owner/repo` provided), creates ProjectRepo or WorkspaceRepo junction based on `target_type`.
- **Disambiguation:** The `target_type` parameter eliminates ambiguity between project and workspace targets.

## Backend Implementation

### New Entity Classes

In `src/Entity/`, extending `ContentEntityBase`:

- `Repo.php` (new)
- `ProjectRepo.php` (new)
- `WorkspaceProject.php` (new)
- `WorkspaceRepo.php` (new)

`Project.php` and `Workspace.php` already exist; no changes.

### Service Provider Changes

**`ProjectServiceProvider.php` (existing, extend):**

- Add Repo entity type registration with `fieldDefinitions`
- Add ProjectRepo junction entity type registration with `fieldDefinitions`
- Wire `SqlEntityStorage` + `StorageRepositoryAdapter` for Repo and ProjectRepo

**`WorkspaceServiceProvider.php` (existing, extend):**

- Add WorkspaceProject and WorkspaceRepo junction entity type registrations with `fieldDefinitions`
- Wire `SqlEntityStorage` + `StorageRepositoryAdapter` for each
- These junctions are workspace concerns and belong in the workspace provider

### New Internal API Controller

`InternalWorkspaceSetupController.php`:

- HMAC Bearer auth (same pattern as `InternalWorkspaceController`)
- Routes registered in `WorkspaceToolServiceProvider`:
  - `POST /api/internal/workspace/setup`
  - `POST /api/internal/workspace/switch`
  - `POST /api/internal/workspace/add-project`
  - `POST /api/internal/workspace/add-repo`
- GitHub API calls use `file_get_contents` with `stream_context_create` and `'ignore_errors' => true`

### New Agent Tools

In `agent/tools/`, following existing pattern (`TOOL_DEF` dict + `execute()` function, auto-discovered by `discover_tools()`):

- `workspace_setup.py`
- `workspace_switch.py`
- `workspace_add_project.py`
- `workspace_add_repo.py`

Each calls the corresponding internal API endpoint with HMAC Bearer auth.

## Workspace Context and Agent Session

The agent is stateless (Docker per-request). "Active workspace" is session-scoped.

### How switching works

1. User says "switch to minoo" or "work on waaseyaa"
2. Agent calls `workspace_switch` tool
3. Backend returns full workspace context: metadata, projects with repos, standalone repos, local paths
4. Agent stores context in conversation state (JSON-lines protocol supports multi-turn)
5. Subsequent file/git operations use repo paths from active workspace

### How the agent picks a repo

- Single repo in workspace: use it
- Multiple repos: infer from context (file paths, project name mentioned) or ask if genuinely ambiguous

### What the agent does NOT do

- No persistent "active workspace" in the database
- No workspace auto-switching based on heuristics
- No background syncing or polling

## Existing Code Changes

- **`ProjectServiceProvider`**: Add Repo + junction entity registrations
- **`WorkspaceToolServiceProvider`**: Add new internal API routes
- **`new-workspace` Claude Code skill**: Update to call `workspace_setup` backend endpoint instead of only creating markdown directories
- **Existing `Workspace` entity**: No field changes; scalar repo fields deprecated
- **`ClaudrielServiceProvider`**: No changes needed (already registers `ProjectServiceProvider`)

## Data Migration

One-time migration for existing workspaces with `repo_path`/`repo_url`/`project_id`:

1. Entity types must be registered and `ensureTable` must have run before migration executes (i.e., boot the app once after deploying the new code)
2. Query all workspaces
3. For each with `repo_url` set: create Repo entity, create WorkspaceRepo junction
4. For each with `project_id` set: create WorkspaceProject junction
5. Implement as a CLI command: `claudriel:migrate:workspace-junctions`

## Out of Scope

- Task, epic, or milestone entities
- Issue tracking or PR management (GitHub is source of truth)
- Automated workspace switching based on heuristics
- Background repo syncing or CI polling
- Markdown workspace directories (existing `workspaces/` templates remain as-is, independent of this feature)
- Removal of deprecated Workspace scalar fields (future cleanup)
- `workspace_remove_project` / `workspace_remove_repo` tools (add removal tools in a follow-up; junction deletion is straightforward once the add tools exist)

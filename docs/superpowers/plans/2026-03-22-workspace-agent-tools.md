# Workspace Agent Tools Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move workspace creation/deletion and repo cloning from hardcoded PHP regex into agent subprocess tools, enabling multi-step workflows like "create a workspace and clone jonesrussell/me so we can do some milestone planning."

**Architecture:** Three new Python agent tools (`workspace_create`, `workspace_delete`, `repo_clone`) call back to PHP internal API routes on `InternalWorkspaceController`. The existing `workspace_list` and `github_list_issues` tools complete the orchestration. The hardcoded local action path in `ChatStreamController` is removed.

**Tech Stack:** Python 3 (agent tools), PHP 8.4 (internal API), SQLite (entity storage), httpx (HTTP client)

**Spec:** `docs/superpowers/specs/2026-03-22-workspace-agent-tools-design.md`

---

## File Structure

| File | Responsibility |
|------|---------------|
| `agent/tools/workspace_create.py` | Tool def + executor: POST to PHP, return created workspace |
| `agent/tools/workspace_delete.py` | Tool def + executor: DELETE to PHP, return success |
| `agent/tools/repo_clone.py` | Tool def + executor: POST to PHP, return clone result |
| `agent/util/http.py` | Add `delete()` method to PhpApiClient |
| `src/Controller/InternalWorkspaceController.php` | Add `create()`, `delete()`, `cloneRepo()` methods |
| `src/Provider/WorkspaceToolServiceProvider.php` | Register 3 new routes, inject GitRepositoryManager |
| `src/Controller/ChatStreamController.php` | Remove local action methods |
| `tests/Unit/Controller/InternalWorkspaceControllerTest.php` | Tests for new controller methods |

---

### Task 1: Create `workspace_create` agent tool

**Files:**
- Create: `agent/tools/workspace_create.py`

- [ ] **Step 1: Create the tool file**

```python
"""Tool: Create a new workspace."""

TOOL_DEF = {
    "name": "workspace_create",
    "description": (
        "Create a new workspace. Extract a short, descriptive name from the "
        "user's request (prefer repo name, project name, or client name). "
        "NEVER use the full user sentence as the name. The name should be "
        "1-3 words maximum."
    ),
    "input_schema": {
        "type": "object",
        "properties": {
            "name": {
                "type": "string",
                "description": (
                    "Short workspace name (1-3 words). Examples: 'me' from "
                    "repo jonesrussell/me, 'Acme Corp' from a client project."
                ),
            },
            "description": {
                "type": "string",
                "description": "Optional description of the workspace purpose.",
            },
        },
        "required": ["name"],
    },
}


def execute(api, args: dict) -> dict:
    return api.post("/api/internal/workspaces/create", json_data={
        "name": args["name"],
        "description": args.get("description", ""),
        "mode": args.get("mode", "persistent"),
    })
```

- [ ] **Step 2: Verify tool loads**

Run: `cd /home/jones/dev/claudriel && python3 -c "from agent.tools.workspace_create import TOOL_DEF, execute; print(TOOL_DEF['name'])"`
Expected: `workspace_create`

- [ ] **Step 3: Commit**

```bash
git add agent/tools/workspace_create.py
git commit -m "feat(agent): add workspace_create tool"
```

---

### Task 2: Create `workspace_delete` agent tool

**Files:**
- Create: `agent/tools/workspace_delete.py`

- [ ] **Step 1: Create the tool file**

```python
"""Tool: Delete a workspace by UUID."""

TOOL_DEF = {
    "name": "workspace_delete",
    "description": (
        "Delete a workspace. ALWAYS call workspace_list first to resolve "
        "the correct workspace UUID. ALWAYS ask the user to confirm by "
        "echoing the workspace name before calling this tool."
    ),
    "input_schema": {
        "type": "object",
        "properties": {
            "uuid": {
                "type": "string",
                "description": "UUID of the workspace to delete.",
            },
        },
        "required": ["uuid"],
    },
}


def execute(api, args: dict) -> dict:
    uuid = args["uuid"]
    return api.post(f"/api/internal/workspaces/{uuid}/delete", json_data={})
```

- [ ] **Step 2: Verify tool loads**

Run: `cd /home/jones/dev/claudriel && python3 -c "from agent.tools.workspace_delete import TOOL_DEF, execute; print(TOOL_DEF['name'])"`
Expected: `workspace_delete`

- [ ] **Step 3: Commit**

```bash
git add agent/tools/workspace_delete.py
git commit -m "feat(agent): add workspace_delete tool"
```

---

### Task 3: Create `repo_clone` agent tool

**Files:**
- Create: `agent/tools/repo_clone.py`

- [ ] **Step 1: Create the tool file**

```python
"""Tool: Clone a Git repository into a workspace."""

TOOL_DEF = {
    "name": "repo_clone",
    "description": (
        "Clone a public Git repository into a workspace directory. "
        "The workspace must already exist (call workspace_create first). "
        "Repo format: owner/name (e.g., 'jonesrussell/me')."
    ),
    "input_schema": {
        "type": "object",
        "properties": {
            "workspace_uuid": {
                "type": "string",
                "description": "UUID of the workspace to clone into.",
            },
            "repo": {
                "type": "string",
                "description": "Repository in owner/name format (e.g., 'jonesrussell/me').",
            },
            "branch": {
                "type": "string",
                "description": "Branch to clone. Defaults to 'main'.",
            },
        },
        "required": ["workspace_uuid", "repo"],
    },
}


def execute(api, args: dict) -> dict:
    uuid = args["workspace_uuid"]
    return api.post(f"/api/internal/workspaces/{uuid}/clone-repo", json_data={
        "repo": args["repo"],
        "branch": args.get("branch", "main"),
    })
```

- [ ] **Step 2: Verify tool loads**

Run: `cd /home/jones/dev/claudriel && python3 -c "from agent.tools.repo_clone import TOOL_DEF, execute; print(TOOL_DEF['name'])"`
Expected: `repo_clone`

- [ ] **Step 3: Commit**

```bash
git add agent/tools/repo_clone.py
git commit -m "feat(agent): add repo_clone tool"
```

---

### Task 4: Verify agent tool auto-discovery

**Files:**
- Check: `agent/main.py`

- [ ] **Step 1: Check if tools are auto-discovered**

Read `agent/main.py` and look for the `discover_tools()` function. It dynamically imports all `.py` files from `agent/tools/`. If it uses `importlib` to scan the directory, the new tools will be auto-discovered. Verify by:

Run: `cd /home/jones/dev/claudriel && python3 -c "
import sys; sys.path.insert(0, '.')
from agent.main import discover_tools
tools, executors = discover_tools()
for t in tools:
    if t['name'].startswith('workspace') or t['name'] == 'repo_clone':
        print(f'  Found: {t[\"name\"]}')
"`

Expected output should include:
```
  Found: workspace_create
  Found: workspace_delete
  Found: workspace_list
  Found: repo_clone
```

If any tools are missing, check that the file exports `TOOL_DEF` and `execute` at module level, matching the pattern in `workspace_list.py`.

- [ ] **Step 2: If tools are NOT auto-discovered, register explicitly**

Add tool imports to the relevant registration list in `agent/main.py`. Follow the existing pattern for how other tools are registered.

- [ ] **Step 3: Commit if changes were needed**

```bash
git add agent/main.py
git commit -m "fix(agent): ensure new workspace tools are registered"
```

---

### Task 5: Add `create()` method to InternalWorkspaceController (TDD)

**Files:**
- Modify: `tests/Unit/Controller/InternalWorkspaceControllerTest.php`
- Modify: `src/Controller/InternalWorkspaceController.php`

- [ ] **Step 1: Write failing test for create**

Add to `InternalWorkspaceControllerTest.php`:

```php
public function test_create_workspace(): void
{
    $controller = $this->controller();
    $request = $this->authenticatedRequest('/api/internal/workspaces/create');
    $request->initialize(content: json_encode(['name' => 'me', 'description' => 'jonesrussell/me planning']));

    $response = $controller->create(httpRequest: $request);

    self::assertSame(200, $response->statusCode);
    $data = json_decode($response->content, true);
    self::assertSame('me', $data['name']);
    self::assertSame('active', $data['status']);
    self::assertSame('persistent', $data['mode']);
    self::assertArrayHasKey('uuid', $data);
}

public function test_create_rejects_missing_name(): void
{
    $controller = $this->controller();
    $request = $this->authenticatedRequest('/api/internal/workspaces/create');
    $request->initialize(content: json_encode(['description' => 'no name given']));

    $response = $controller->create(httpRequest: $request);

    self::assertSame(400, $response->statusCode);
}

public function test_create_rejects_unauthenticated(): void
{
    $controller = $this->controller();
    $request = Request::create('/api/internal/workspaces/create', 'POST', content: json_encode(['name' => 'test']));

    $response = $controller->create(httpRequest: $request);

    self::assertSame(401, $response->statusCode);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/jones/dev/claudriel && vendor/bin/phpunit tests/Unit/Controller/InternalWorkspaceControllerTest.php --filter="test_create" -v`
Expected: FAIL (method `create` does not exist)

- [ ] **Step 3: Implement `create()` method**

Add to `InternalWorkspaceController.php` after `workspaceContext()`:

```php
public function create(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
{
    if ($this->authenticate($httpRequest) === null) {
        return $this->jsonError('Unauthorized', 401);
    }

    $data = json_decode($httpRequest?->getContent() ?: '{}', true) ?: [];
    $name = trim((string) ($data['name'] ?? ''));

    if ($name === '' || mb_strlen($name) > 100) {
        return $this->jsonError('Workspace name is required (1-100 characters)', 400);
    }

    $mode = $data['mode'] ?? 'persistent';
    $description = $data['description'] ?? '';

    $workspace = new Workspace([
        'uuid' => $this->generateUuid(),
        'name' => $name,
        'description' => $description,
        'mode' => $mode,
        'status' => 'active',
        'tenant_id' => $this->tenantId,
    ]);
    $this->workspaceRepo->save($workspace);

    return $this->jsonResponse([
        'uuid' => $workspace->get('uuid'),
        'name' => $workspace->get('name'),
        'status' => 'active',
        'mode' => $mode,
        'created_at' => (new \DateTimeImmutable())->format('c'),
    ]);
}
```

Also add the `generateUuid()` helper (copy from `ChatStreamController` or use `Ramsey\Uuid`):

```php
private function generateUuid(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xFFFF), random_int(0, 0xFFFF),
        random_int(0, 0xFFFF),
        random_int(0, 0x0FFF) | 0x4000,
        random_int(0, 0x3FFF) | 0x8000,
        random_int(0, 0xFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFFF),
    );
}
```

Add the `use` statement at the top:

```php
use Claudriel\Entity\Workspace;
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /home/jones/dev/claudriel && vendor/bin/phpunit tests/Unit/Controller/InternalWorkspaceControllerTest.php -v`
Expected: All tests PASS (including existing tests)

- [ ] **Step 5: Commit**

```bash
git add src/Controller/InternalWorkspaceController.php tests/Unit/Controller/InternalWorkspaceControllerTest.php
git commit -m "feat(agent): add workspace create endpoint with tests"
```

---

### Task 6: Add `delete()` method to InternalWorkspaceController (TDD)

**Files:**
- Modify: `tests/Unit/Controller/InternalWorkspaceControllerTest.php`
- Modify: `src/Controller/InternalWorkspaceController.php`

- [ ] **Step 1: Write failing test for delete**

```php
public function test_delete_workspace(): void
{
    $this->seedWorkspace('ws-del', 'Doomed', self::TENANT);

    $controller = $this->controller();
    $request = $this->authenticatedRequest('/api/internal/workspaces/ws-del');

    $response = $controller->delete(params: ['uuid' => 'ws-del'], httpRequest: $request);

    self::assertSame(200, $response->statusCode);
    $data = json_decode($response->content, true);
    self::assertTrue($data['success']);

    // Verify it's gone
    $listResponse = $controller->listWorkspaces(httpRequest: $this->authenticatedRequest('/api/internal/workspaces/list'));
    $listData = json_decode($listResponse->content, true);
    self::assertSame(0, $listData['count']);
}

public function test_delete_returns_404_for_missing(): void
{
    $controller = $this->controller();
    $request = $this->authenticatedRequest('/api/internal/workspaces/nonexistent');

    $response = $controller->delete(params: ['uuid' => 'nonexistent'], httpRequest: $request);

    self::assertSame(404, $response->statusCode);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/jones/dev/claudriel && vendor/bin/phpunit tests/Unit/Controller/InternalWorkspaceControllerTest.php --filter="test_delete" -v`
Expected: FAIL

- [ ] **Step 3: Implement `delete()` method**

Add to `InternalWorkspaceController.php`:

```php
public function delete(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
{
    if ($this->authenticate($httpRequest) === null) {
        return $this->jsonError('Unauthorized', 401);
    }

    $uuid = $params['uuid'] ?? '';
    if ($uuid === '') {
        return $this->jsonError('Workspace UUID required', 400);
    }

    $results = $this->workspaceRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
    $workspace = $results[0] ?? null;

    if ($workspace === null) {
        return $this->jsonError('Workspace not found', 404);
    }

    $this->workspaceRepo->delete($workspace);

    return $this->jsonResponse(['success' => true]);
}
```

Note: `EntityRepositoryInterface::delete(EntityInterface $entity): void` takes a single entity. Do NOT use the array form `delete([$workspace])` — that is the raw `EntityStorageInterface` pattern used in `ChatStreamController`, not the repository interface.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /home/jones/dev/claudriel && vendor/bin/phpunit tests/Unit/Controller/InternalWorkspaceControllerTest.php -v`
Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add src/Controller/InternalWorkspaceController.php tests/Unit/Controller/InternalWorkspaceControllerTest.php
git commit -m "feat(agent): add workspace delete endpoint with tests"
```

---

### Task 7: Add `cloneRepo()` method to InternalWorkspaceController (TDD)

**Files:**
- Modify: `tests/Unit/Controller/InternalWorkspaceControllerTest.php`
- Modify: `src/Controller/InternalWorkspaceController.php`
- Modify: `src/Provider/WorkspaceToolServiceProvider.php` (inject GitRepositoryManager)

- [ ] **Step 1: Update controller constructor to accept GitRepositoryManager**

In `InternalWorkspaceController.php`, update the constructor:

```php
public function __construct(
    private readonly EntityRepositoryInterface $workspaceRepo,
    private readonly InternalApiTokenGenerator $apiTokenGenerator,
    private readonly string $tenantId,
    private readonly ?GitRepositoryManager $gitManager = null,
) {}
```

Add `use Claudriel\Domain\Git\GitRepositoryManager;` at the top.

The optional parameter preserves backward compatibility with existing tests while allowing injection.

- [ ] **Step 2: Update WorkspaceToolServiceProvider to inject GitRepositoryManager**

In `WorkspaceToolServiceProvider.php`, update the singleton registration:

```php
$this->singleton(InternalWorkspaceController::class, function () {
    return new InternalWorkspaceController(
        new StorageRepositoryAdapter($this->resolve(EntityTypeManager::class)->getStorage('workspace')),
        $this->resolve(InternalApiTokenGenerator::class),
        $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default',
        $this->resolve(GitRepositoryManager::class),
    );
});
```

Add `use Claudriel\Domain\Git\GitRepositoryManager;` at the top. Verify `GitRepositoryManager` is registered in the container (check `ClaudrielServiceProvider` or register it here if needed).

- [ ] **Step 3: Write failing test for cloneRepo**

Add to test file. Uses a mock `GitRepositoryManager` via the callable runner:

```php
public function test_clone_repo(): void
{
    $this->seedWorkspace('ws-clone', 'me', self::TENANT);

    $clonedTo = null;
    $clonedUrl = null;
    $gitManager = new \Claudriel\Domain\Git\GitRepositoryManager(
        workspaceRoot: sys_get_temp_dir() . '/claudriel-test-' . uniqid(),
        runner: function (string $cmd) use (&$clonedTo, &$clonedUrl): array {
            // Capture what was cloned
            if (str_contains($cmd, 'git clone')) {
                preg_match("/git clone.*?'([^']+)'.*?'([^']+)'/", $cmd, $m);
                $clonedUrl = $m[1] ?? '';
                $clonedTo = $m[2] ?? '';
            }
            return ['exit_code' => 0, 'output' => ''];
        },
    );

    $controller = $this->controllerWithGit($gitManager);
    $request = $this->authenticatedRequest('/api/internal/workspaces/ws-clone/clone-repo');
    $request->initialize(content: json_encode(['repo' => 'jonesrussell/me', 'branch' => 'main']));

    $response = $controller->cloneRepo(params: ['uuid' => 'ws-clone'], httpRequest: $request);

    self::assertSame(200, $response->statusCode);
    $data = json_decode($response->content, true);
    self::assertTrue($data['success']);
    self::assertStringContainsString('ws-clone', $data['local_path']);
    self::assertSame('https://github.com/jonesrussell/me.git', $clonedUrl);
}

public function test_clone_rejects_invalid_repo_format(): void
{
    $this->seedWorkspace('ws-bad', 'bad', self::TENANT);

    $controller = $this->controllerWithGit();
    $request = $this->authenticatedRequest('/api/internal/workspaces/ws-bad/clone-repo');
    $request->initialize(content: json_encode(['repo' => '../../../etc/passwd']));

    $response = $controller->cloneRepo(params: ['uuid' => 'ws-bad'], httpRequest: $request);

    self::assertSame(400, $response->statusCode);
}
```

Add the `controllerWithGit` helper:

```php
private function controllerWithGit(?GitRepositoryManager $gitManager = null): InternalWorkspaceController
{
    return new InternalWorkspaceController(
        $this->repo,
        $this->tokenGenerator,
        self::TENANT,
        $gitManager ?? new GitRepositoryManager(
            workspaceRoot: sys_get_temp_dir() . '/claudriel-test',
            runner: fn(string $cmd) => ['exit_code' => 0, 'output' => ''],
        ),
    );
}
```

Add `use Claudriel\Domain\Git\GitRepositoryManager;` at the top of test file.

- [ ] **Step 4: Run tests to verify they fail**

Run: `cd /home/jones/dev/claudriel && vendor/bin/phpunit tests/Unit/Controller/InternalWorkspaceControllerTest.php --filter="test_clone" -v`
Expected: FAIL (method `cloneRepo` does not exist)

- [ ] **Step 5: Implement `cloneRepo()` method**

Add to `InternalWorkspaceController.php`:

```php
private const REPO_PATTERN = '/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/';

public function cloneRepo(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
{
    if ($this->authenticate($httpRequest) === null) {
        return $this->jsonError('Unauthorized', 401);
    }

    if ($this->gitManager === null) {
        return $this->jsonError('Git operations not available', 500);
    }

    $uuid = $params['uuid'] ?? '';
    $data = json_decode($httpRequest?->getContent() ?: '{}', true) ?: [];
    $repo = trim((string) ($data['repo'] ?? ''));
    $branch = trim((string) ($data['branch'] ?? 'main'));

    if (! preg_match(self::REPO_PATTERN, $repo)) {
        return $this->jsonError('Invalid repo format. Expected: owner/name', 400);
    }

    $results = $this->workspaceRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
    if (($results[0] ?? null) === null) {
        return $this->jsonError('Workspace not found', 404);
    }

    $repoUrl = sprintf('https://github.com/%s.git', $repo);
    $localPath = $this->gitManager->buildWorkspaceRepoPath($uuid);

    try {
        $this->gitManager->clone($repoUrl, $localPath, $branch);
    } catch (\RuntimeException $e) {
        return $this->jsonError('Clone failed: ' . $e->getMessage(), 500);
    }

    return $this->jsonResponse([
        'success' => true,
        'local_path' => $localPath,
        'branch' => $branch,
        'repo_url' => $repoUrl,
    ]);
}
```

Note: **Artifact entity storage is out of scope for v1.** The spec mentions storing an `Artifact` entity (consistent with `WorkspaceCloneCommand`), but for the initial implementation we skip this. The clone result is returned directly to the agent. Artifact tracking can be added later if downstream code needs to query cloned repos.

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd /home/jones/dev/claudriel && vendor/bin/phpunit tests/Unit/Controller/InternalWorkspaceControllerTest.php -v`
Expected: All PASS

- [ ] **Step 7: Commit**

```bash
git add src/Controller/InternalWorkspaceController.php src/Provider/WorkspaceToolServiceProvider.php tests/Unit/Controller/InternalWorkspaceControllerTest.php
git commit -m "feat(agent): add workspace clone-repo endpoint with tests"
```

---

### Task 8: Register new routes in WorkspaceToolServiceProvider

**Files:**
- Modify: `src/Provider/WorkspaceToolServiceProvider.php`

- [ ] **Step 1: Add 3 new routes**

Add to the `routes()` method after the existing workspace context route:

```php
$workspaceCreateRoute = RouteBuilder::create('/api/internal/workspaces/create')
    ->controller(InternalWorkspaceController::class.'::create')
    ->allowAll()
    ->methods('POST')
    ->build();
$workspaceCreateRoute->setOption('_csrf', false);
$router->addRoute('claudriel.internal.workspaces.create', $workspaceCreateRoute);

$workspaceDeleteRoute = RouteBuilder::create('/api/internal/workspaces/{uuid}/delete')
    ->controller(InternalWorkspaceController::class.'::delete')
    ->allowAll()
    ->methods('POST')
    ->build();
$workspaceDeleteRoute->setOption('_csrf', false);
$router->addRoute('claudriel.internal.workspaces.delete', $workspaceDeleteRoute);

$workspaceCloneRoute = RouteBuilder::create('/api/internal/workspaces/{uuid}/clone-repo')
    ->controller(InternalWorkspaceController::class.'::cloneRepo')
    ->allowAll()
    ->methods('POST')
    ->build();
$workspaceCloneRoute->setOption('_csrf', false);
$router->addRoute('claudriel.internal.workspaces.clone', $workspaceCloneRoute);
```

Note: The delete route uses `POST /api/internal/workspaces/{uuid}/delete` (not `DELETE /api/internal/workspaces/{uuid}`) to avoid a path conflict with the existing `GET /api/internal/workspaces/{uuid}` context route. The Python tool in Task 2 already uses `api.post(...)` to match this route.

- [ ] **Step 2: Run existing tests to verify nothing breaks**

Run: `cd /home/jones/dev/claudriel && vendor/bin/phpunit tests/Unit/Controller/InternalWorkspaceControllerTest.php -v`
Expected: All PASS

- [ ] **Step 3: Commit**

```bash
git add src/Provider/WorkspaceToolServiceProvider.php agent/tools/workspace_delete.py
git commit -m "feat(agent): register workspace create/delete/clone routes"
```

---

### Task 9: Remove local action code from ChatStreamController

**Files:**
- Modify: `src/Controller/ChatStreamController.php`

- [ ] **Step 1: Remove the local action branch in the stream method**

In the method that calls `handleLocalAction()`, remove or bypass the call. Find the line that calls `$this->handleLocalAction(...)` and remove the conditional branch so all messages go to the agent subprocess.

Look for a pattern like:
```php
$localResponse = $this->handleLocalAction($userMsg, $msgStorage, $tenantId);
if ($localResponse !== null) {
    return $localResponse;
}
```

Remove these lines.

- [ ] **Step 2: Remove the helper methods**

Delete these methods from `ChatStreamController.php`:
- `handleLocalAction()` (lines ~130-188)
- `buildLocalActionResponse()` (lines ~190-216)
- `findWorkspaceByName()` (lines ~218-221)
- `extractWorkspaceDeletionNames()` (lines ~226-262)
- `buildWorkspaceDeletionResponse()` (lines ~268+)
- `formatWorkspaceNameList()` (if it exists and is only used by deletion)
- `extractWorkspaceName()` (lines ~548-575)

- [ ] **Step 3: Remove unused imports**

Remove `use Claudriel\Entity\Workspace;` and any other imports that are no longer referenced (like `TenantWorkspaceResolver` if only used by the removed methods).

- [ ] **Step 4: Run the full test suite**

Run: `cd /home/jones/dev/claudriel && vendor/bin/phpunit -v`
Expected: All PASS (some ChatStreamController tests may need updating if they tested local actions directly)

If any tests fail because they tested the local action path, delete those tests (they are testing behavior we are intentionally removing).

- [ ] **Step 5: Commit**

```bash
git add src/Controller/ChatStreamController.php
git commit -m "refactor(chat): remove hardcoded workspace local actions

Workspace creation and deletion are now handled by agent tools.
This removes the brittle regex-based intent parsing that caused
the garbage workspace name bug."
```

---

### Task 10: Smoke test the full flow locally

**Files:** None (manual testing)

- [ ] **Step 1: Start the local dev server**

Run: `cd /home/jones/dev/claudriel && php -S localhost:8081 -t public`

- [ ] **Step 2: Open the chat UI in browser**

Navigate to `http://localhost:8081` (or the admin at the appropriate port).

- [ ] **Step 3: Test workspace deletion**

Type: "list my workspaces"
Expected: Agent calls `workspace_list` tool, shows 3 garbage workspaces.

Type: "delete all three workspaces"
Expected: Agent calls `workspace_list`, shows the 3, asks for confirmation by name for each. After confirmation, calls `workspace_delete` for each UUID. All 3 deleted.

- [ ] **Step 4: Test the full create-and-clone flow**

Type: "create a workspace and clone jonesrussell/me so we can do some milestone planning"
Expected:
1. Agent calls `workspace_create(name: "me", description: "...")`
2. Agent calls `repo_clone(workspace_uuid: "<uuid>", repo: "jonesrussell/me")`
3. Agent calls `github_list_issues(repo: "jonesrussell/me")`
4. Agent presents findings and suggests milestone structure

- [ ] **Step 5: Verify the workspace was created correctly**

Run: `sqlite3 storage/claudriel.sqlite "SELECT wid, name, json_extract(_data, '$.status') FROM workspace;"`
Expected: One workspace named "me" with status "active"

- [ ] **Step 6: Commit any fixes discovered during smoke testing**

---

### Task 11: Deploy and verify on production

- [ ] **Step 1: Push changes and let CI deploy to staging**

Run: `git push origin main`

Wait for GitHub Actions deploy to complete.

- [ ] **Step 2: Verify on staging**

Navigate to `https://claudriel.northcloud.one` and repeat the smoke test from Task 10.

- [ ] **Step 3: Verify production deploy**

Navigate to `https://claudriel.ai` and verify the chat responds correctly.

- [ ] **Step 4: Clean up garbage workspaces on production via chat**

Use the chat UI on production to delete the 3 garbage workspaces, preserving the account and integrations (they are in separate tables and are not affected by workspace deletion).

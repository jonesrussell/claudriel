# Code Task Fixes and Agent Tools Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all #587 review findings (critical + important) and complete #594 (wire tools + add tests).

**Architecture:** CodeTaskRunner gets `proc_open` for reliable exit codes, proper error handling for git ops, and correct diff capture. InternalCodeTaskController gets DI singleton registration, tenant filtering, account_id on workspaces, and proper route wiring. Three PHP tool classes (already written, untracked) get tests and stay as-is.

**Tech Stack:** PHP 8.4, Waaseyaa framework, PHPUnit, Symfony Console

**Issues:** #587, #594

---

### Task 1: Fix CodeTaskRunner — replace shell_exec with proc_open and fix shellExec error handling

**Files:**
- Modify: `src/Domain/CodeTask/CodeTaskRunner.php`
- Test: `tests/Unit/Domain/CodeTask/CodeTaskRunnerTest.php`

Addresses #587 critical: "shellExec() silently swallows git errors", "Exit code capture via __CLAUDRIEL_EXIT__ marker"

- [ ] **Step 1: Write failing test for git error propagation**

```php
public function test_run_fails_when_branch_creation_throws(): void
{
    $task = new CodeTask([
        'uuid' => 'task-1',
        'workspace_uuid' => 'ws-1',
        'repo_uuid' => 'repo-1',
        'prompt' => 'Fix the bug',
        'branch_name' => 'claudriel/fix-the-bug',
    ]);

    $repo = $this->createMock(EntityRepositoryInterface::class);
    $repo->expects($this->atLeastOnce())->method('save');

    $callCount = 0;
    $runner = new CodeTaskRunner($repo, function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            // prepareWorkingBranch — simulate git checkout failure
            throw new \RuntimeException('fatal: cannot create branch');
        }
        return ['exit_code' => 0, 'output' => 'ok'];
    });

    $runner->run($task, '/tmp/test-repo');

    $this->assertSame('failed', $task->get('status'));
    $this->assertStringContainsString('cannot create branch', (string) $task->get('error'));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Domain/CodeTask/CodeTaskRunnerTest.php --filter=test_run_fails_when_branch_creation_throws`
Expected: FAIL — current `shellExec()` swallows the error

- [ ] **Step 3: Refactor CodeTaskRunner to use proc_open and check git exit codes**

Replace `invokeClaudeCode` to use `proc_open` instead of `shell_exec` with marker pattern:

```php
private function invokeClaudeCode(string $repoPath, string $prompt): array
{
    if ($this->processRunner !== null) {
        return ($this->processRunner)($repoPath, $prompt);
    }

    $command = sprintf(
        'cd %s && timeout %d claude --print --output-format stream-json --allowedTools "Edit,Write,Read,Glob,Grep,Bash" --max-turns 30 -p %s 2>&1',
        escapeshellarg($repoPath),
        self::TIMEOUT_SECONDS,
        escapeshellarg($prompt),
    );

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes);
    if (! is_resource($process)) {
        return ['exit_code' => 1, 'output' => 'Failed to start process'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $output = trim($stdout ?: '') . trim($stderr ?: '');

    return ['exit_code' => $exitCode, 'output' => $output];
}
```

Replace `shellExec` to throw on failure:

```php
private function shellExec(string $command): string
{
    $result = $this->shellExecSafe($command);
    // shellExecSafe doesn't expose exit code from shell_exec, so check output
    // for common git error prefixes
    if (str_starts_with($result, 'fatal:') || str_starts_with($result, 'error:')) {
        throw new \RuntimeException($result);
    }

    return $result;
}
```

Actually, a cleaner approach: make `shellExec` and `prepareWorkingBranch` also go through the `processRunner` callable when available (for testability), or use `proc_open` too. The simplest fix that addresses the review finding:

Replace `shellExec` with a method that checks exit codes:

```php
private function shellExec(string $command): string
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);
    if (! is_resource($process)) {
        throw new \RuntimeException('Failed to execute: ' . $command);
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        $error = trim($stderr ?: $stdout ?: 'Command failed');
        throw new \RuntimeException($error);
    }

    return trim($stdout ?: '');
}
```

Remove `shellExecSafe` — replace its one callsite (`captureDiff`) with inline `shellExec` wrapped in try-catch.

- [ ] **Step 4: Run all CodeTaskRunner tests**

Run: `vendor/bin/phpunit tests/Unit/Domain/CodeTask/CodeTaskRunnerTest.php`
Expected: All pass

- [ ] **Step 5: Rename misleading test**

Rename `test_run_sets_status_to_running` → `test_run_completes_successfully` (it asserts `completed`, not `running`).

- [ ] **Step 6: Run tests again**

Run: `vendor/bin/phpunit tests/Unit/Domain/CodeTask/CodeTaskRunnerTest.php`
Expected: All pass

- [ ] **Step 7: Commit**

```bash
git add src/Domain/CodeTask/CodeTaskRunner.php tests/Unit/Domain/CodeTask/CodeTaskRunnerTest.php
git commit -m "fix(#587): replace shell_exec with proc_open in CodeTaskRunner, fix silent git errors"
```

### Task 2: Fix captureDiff to use correct diff reference

**Files:**
- Modify: `src/Domain/CodeTask/CodeTaskRunner.php`
- Test: `tests/Unit/Domain/CodeTask/CodeTaskRunnerTest.php`

Addresses #587 critical: "captureDiff uses wrong diff reference (HEAD~1)"

- [ ] **Step 1: Fix captureDiff**

The current code does `git diff HEAD~1` which compares against the previous commit, not the working changes. After `prepareWorkingBranch` creates a new branch and Claude Code makes changes, we want uncommitted changes or changes since branch creation. Replace with:

```php
private function captureDiff(string $repoPath): string
{
    // Capture both staged and unstaged changes
    try {
        return $this->shellExec(sprintf(
            'git -C %s diff HEAD',
            escapeshellarg($repoPath),
        ));
    } catch (\RuntimeException) {
        return '';
    }
}
```

- [ ] **Step 2: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Domain/CodeTask/CodeTaskRunnerTest.php`
Expected: All pass

- [ ] **Step 3: Commit**

```bash
git add src/Domain/CodeTask/CodeTaskRunner.php
git commit -m "fix(#587): use git diff HEAD for correct diff capture in CodeTaskRunner"
```

### Task 3: Fix CodeTask entity — add branch_name default

**Files:**
- Modify: `src/Entity/CodeTask.php`
- Test: `tests/Unit/Entity/CodeTaskTest.php`

Addresses #587 important: "branch_name missing constructor default"

- [ ] **Step 1: Write failing test**

```php
public function test_branch_name_defaults_to_null(): void
{
    $task = new CodeTask(['workspace_uuid' => 'ws-1', 'repo_uuid' => 'repo-1', 'prompt' => 'Fix bug']);
    $this->assertNull($task->get('branch_name'));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Entity/CodeTaskTest.php --filter=test_branch_name_defaults_to_null`
Expected: FAIL — `branch_name` has no default

- [ ] **Step 3: Add default to constructor**

In `src/Entity/CodeTask.php`, add after the `claude_output` default block:

```php
if (! array_key_exists('branch_name', $values)) {
    $values['branch_name'] = null;
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Entity/CodeTaskTest.php`
Expected: All pass

- [ ] **Step 5: Commit**

```bash
git add src/Entity/CodeTask.php tests/Unit/Entity/CodeTaskTest.php
git commit -m "fix(#587): add branch_name default to CodeTask constructor"
```

### Task 4: Fix InternalCodeTaskController — workspace account_id, tenant filtering, DI singleton

**Files:**
- Modify: `src/Controller/InternalCodeTaskController.php`
- Modify: `src/Provider/CodeTaskServiceProvider.php`
- Test: `tests/Unit/Controller/InternalCodeTaskControllerTest.php`

Addresses #587 critical: "Workspace created without account_id", "GitRepositoryManager via DI constructor", "exec() background dispatch path resolution". Addresses #587 important: "Controller not registered as DI singleton", "status endpoint missing tenant_id filter".

- [ ] **Step 1: Write failing test for tenant isolation on status**

```php
public function test_status_filters_by_tenant(): void
{
    $task = new CodeTask([
        'ctid' => 1,
        'uuid' => 'task-1',
        'workspace_uuid' => 'ws-1',
        'repo_uuid' => 'repo-1',
        'prompt' => 'Fix bug',
        'status' => 'completed',
        'tenant_id' => 'other-tenant',
    ]);
    $this->codeTaskRepo->save($task);

    $controller = $this->makeController();
    $token = $this->tokenGenerator->generate('acct-1');
    $request = Request::create('/api/internal/code-tasks/task-1/status', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);
    // Default tenant is 'default', task has 'other-tenant'

    $response = $controller->status(['uuid' => 'task-1'], [], null, $request);
    self::assertSame(404, $response->statusCode);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Controller/InternalCodeTaskControllerTest.php --filter=test_status_filters_by_tenant`
Expected: FAIL — currently returns 200 without tenant check

- [ ] **Step 3: Fix status() to filter by tenant_id**

In `InternalCodeTaskController::status()`, after finding the task, add tenant check:

```php
$tenantId = $this->resolveTenantId($httpRequest);
$taskTenant = (string) ($task->get('tenant_id') ?? '');
if ($taskTenant !== '' && $taskTenant !== $tenantId) {
    return $this->jsonError('Code task not found', 404);
}
```

- [ ] **Step 4: Fix create() to set account_id on workspace**

In `resolveOrCreateWorkspace`, after creating the workspace, add account_id. Change the method signature to accept `$accountId`:

```php
private function resolveOrCreateWorkspace(string $repoFullName, string $tenantId, string $accountId): string
```

In the workspace creation block:

```php
$workspace = new Workspace([
    'name' => $repoFullName,
    'description' => 'Auto-created for code tasks on ' . $repoFullName,
    'tenant_id' => $tenantId,
    'account_id' => $accountId,
    'status' => 'active',
]);
```

Update the `create()` method call to pass accountId:

```php
$accountId = $this->authenticate($httpRequest) ?? '';
// ... (already validated non-null above)
$workspaceUuid = $this->resolveOrCreateWorkspace($repoFullName, $tenantId, $accountId);
```

Store the authenticated accountId from `authenticate()` so it's available later. Refactor the top of `create()`:

```php
$accountId = $this->authenticate($httpRequest);
if ($accountId === null) {
    return $this->jsonError('Unauthorized', 401);
}
```

Then use `$accountId` in the `resolveOrCreateWorkspace` call.

- [ ] **Step 5: Fix background dispatch path**

Replace `dirname(__DIR__, 2)` with a reliable path resolution. Use the project root detected from the current file location:

```php
$consolePath = dirname(__DIR__, 2) . '/bin/console';
```

Actually, `dirname(__DIR__, 2)` from `src/Controller/` goes to project root, which is correct for dev. For production, the deploy path is different. The fix is to use the Waaseyaa kernel's project root. Since this controller doesn't have access to the kernel, use a simpler approach: resolve from `$_SERVER['DOCUMENT_ROOT']` or use the `CLAUDRIEL_ROOT` env:

```php
$projectRoot = $_ENV['CLAUDRIEL_ROOT'] ?? getenv('CLAUDRIEL_ROOT') ?: dirname(__DIR__, 2);
$consolePath = $projectRoot . '/bin/console';
```

- [ ] **Step 6: Register InternalCodeTaskController as DI singleton in CodeTaskServiceProvider**

In `CodeTaskServiceProvider::register()`, add:

```php
$this->singleton(InternalCodeTaskController::class, function () {
    $entityTypeManager = $this->resolve(EntityTypeManagerInterface::class);
    $database = $this->resolve(DatabaseInterface::class);
    $dispatcher = $this->resolve(EventDispatcherInterface::class);

    $makeRepo = function (string $typeId) use ($entityTypeManager, $database, $dispatcher) {
        $storage = new SqlEntityStorage(
            $entityTypeManager->getDefinition($typeId),
            $database,
            $dispatcher,
        );
        return new StorageRepositoryAdapter($storage);
    };

    $codeTaskRepo = $makeRepo('code_task');
    $workspaceRepo = $makeRepo('workspace');
    $repoRepo = $makeRepo('repo');
    $workspaceRepoRepo = $makeRepo('workspace_repo');

    $secret = $_ENV['AGENT_INTERNAL_SECRET'] ?? getenv('AGENT_INTERNAL_SECRET') ?: '';
    $tokenGen = new InternalApiTokenGenerator($secret);

    $runner = $this->resolve(CodeTaskRunner::class);
    $gitManager = new GitRepositoryManager();

    return new InternalCodeTaskController(
        $codeTaskRepo,
        $workspaceRepo,
        $repoRepo,
        $workspaceRepoRepo,
        $tokenGen,
        $runner,
        $gitManager,
    );
});
```

- [ ] **Step 7: Wire routes in CodeTaskServiceProvider**

Replace the empty `routes()` body:

```php
public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
{
    $router->addRoute(
        'claudriel.internal.code_task.create',
        RouteBuilder::create('/api/internal/code-tasks/create')
            ->controller(InternalCodeTaskController::class . '::create')
            ->allowAll()
            ->methods('POST')
            ->build(),
    );
    $router->addRoute(
        'claudriel.internal.code_task.status',
        RouteBuilder::create('/api/internal/code-tasks/{uuid}/status')
            ->controller(InternalCodeTaskController::class . '::status')
            ->allowAll()
            ->methods('GET')
            ->build(),
    );
}
```

Add required imports at top of file:

```php
use Claudriel\Controller\InternalCodeTaskController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Domain\Git\GitRepositoryManager;
use Waaseyaa\Routing\RouteBuilder;
```

- [ ] **Step 8: Run all controller tests**

Run: `vendor/bin/phpunit tests/Unit/Controller/InternalCodeTaskControllerTest.php`
Expected: All pass

- [ ] **Step 9: Commit**

```bash
git add src/Controller/InternalCodeTaskController.php src/Provider/CodeTaskServiceProvider.php tests/Unit/Controller/InternalCodeTaskControllerTest.php
git commit -m "fix(#587): add tenant filter, account_id on workspace, DI singleton, route wiring"
```

### Task 5: Add tests for PHP agent tools (#594)

**Files:**
- Create: `tests/Unit/Domain/Chat/Tool/CodeTaskCreateToolTest.php`
- Create: `tests/Unit/Domain/Chat/Tool/CodeTaskStatusToolTest.php`
- Create: `tests/Unit/Domain/Chat/Tool/RepoCloneToolTest.php`

Addresses #594: the tool files exist but have no tests.

- [ ] **Step 1: Write CodeTaskCreateTool tests**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Chat\Tool;

use Claudriel\Domain\Chat\Tool\CodeTaskCreateTool;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use PHPUnit\Framework\TestCase;

final class CodeTaskCreateToolTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';

    public function test_definition_returns_valid_schema(): void
    {
        $tool = $this->makeTool();
        $def = $tool->definition();

        $this->assertSame('code_task_create', $def['name']);
        $this->assertArrayHasKey('description', $def);
        $this->assertSame(['repo', 'prompt'], $def['input_schema']['required']);
    }

    public function test_execute_rejects_empty_repo(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['repo' => '', 'prompt' => 'Fix bug']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_execute_rejects_empty_prompt(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['repo' => 'owner/repo', 'prompt' => '']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_execute_rejects_invalid_repo_format(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['repo' => 'not-a-valid-repo', 'prompt' => 'Fix bug']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('owner/name', $result['error']);
    }

    private function makeTool(): CodeTaskCreateTool
    {
        return new CodeTaskCreateTool(
            'http://localhost:8081',
            'acct-1',
            'default',
            new InternalApiTokenGenerator(self::SECRET),
        );
    }
}
```

- [ ] **Step 2: Write CodeTaskStatusTool tests**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Chat\Tool;

use Claudriel\Domain\Chat\Tool\CodeTaskStatusTool;
use Claudriel\Entity\CodeTask;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class CodeTaskStatusToolTest extends TestCase
{
    public function test_definition_returns_valid_schema(): void
    {
        $repo = $this->makeRepo();
        $tool = new CodeTaskStatusTool($repo);
        $def = $tool->definition();

        $this->assertSame('code_task_status', $def['name']);
        $this->assertSame(['task_uuid'], $def['input_schema']['required']);
    }

    public function test_execute_rejects_empty_uuid(): void
    {
        $repo = $this->makeRepo();
        $tool = new CodeTaskStatusTool($repo);
        $result = $tool->execute(['task_uuid' => '']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_execute_returns_not_found(): void
    {
        $repo = $this->makeRepo();
        $tool = new CodeTaskStatusTool($repo);
        $result = $tool->execute(['task_uuid' => 'nonexistent']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_execute_returns_task_data(): void
    {
        $repo = $this->makeRepo();
        $task = new CodeTask([
            'ctid' => 1,
            'uuid' => 'task-1',
            'workspace_uuid' => 'ws-1',
            'repo_uuid' => 'repo-1',
            'prompt' => 'Fix bug',
            'status' => 'completed',
            'pr_url' => 'https://github.com/test/repo/pull/1',
        ]);
        $repo->save($task);

        $tool = new CodeTaskStatusTool($repo);
        $result = $tool->execute(['task_uuid' => 'task-1']);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('https://github.com/test/repo/pull/1', $result['pr_url']);
    }

    private function makeRepo(): EntityRepository
    {
        return new EntityRepository(
            new EntityType(
                id: 'code_task',
                label: 'Code Task',
                class: CodeTask::class,
                keys: ['id' => 'ctid', 'uuid' => 'uuid', 'label' => 'prompt'],
            ),
            new InMemoryStorageDriver(),
            new EventDispatcher(),
        );
    }
}
```

- [ ] **Step 3: Write RepoCloneTool tests**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Chat\Tool;

use Claudriel\Domain\Chat\Tool\RepoCloneTool;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use PHPUnit\Framework\TestCase;

final class RepoCloneToolTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';

    public function test_definition_returns_valid_schema(): void
    {
        $tool = $this->makeTool();
        $def = $tool->definition();

        $this->assertSame('repo_clone', $def['name']);
        $this->assertSame(['workspace_uuid', 'repo'], $def['input_schema']['required']);
    }

    public function test_execute_rejects_empty_workspace(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['workspace_uuid' => '', 'repo' => 'owner/repo']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_execute_rejects_empty_repo(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['workspace_uuid' => 'ws-1', 'repo' => '']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_execute_rejects_invalid_repo_format(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['workspace_uuid' => 'ws-1', 'repo' => 'invalid']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('owner/name', $result['error']);
    }

    private function makeTool(): RepoCloneTool
    {
        return new RepoCloneTool(
            'http://localhost:8081',
            'acct-1',
            'default',
            new InternalApiTokenGenerator(self::SECRET),
        );
    }
}
```

- [ ] **Step 4: Run all tool tests**

Run: `vendor/bin/phpunit tests/Unit/Domain/Chat/Tool/`
Expected: All pass

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Domain/Chat/Tool/CodeTaskCreateToolTest.php tests/Unit/Domain/Chat/Tool/CodeTaskStatusToolTest.php tests/Unit/Domain/Chat/Tool/RepoCloneToolTest.php
git commit -m "test(#594): add unit tests for CodeTask and RepoClone agent tools"
```

### Task 6: Fix test convention violations

**Files:**
- Modify: `tests/Unit/Controller/InternalCodeTaskControllerTest.php`

Addresses #587 important: "Tests use concrete EntityRepository — should be EntityRepositoryInterface"

- [ ] **Step 1: Update type hints**

Change the property declarations from concrete `EntityRepository` to `EntityRepositoryInterface`:

```php
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

// Change these property types:
private EntityRepositoryInterface $codeTaskRepo;
private EntityRepositoryInterface $workspaceRepo;
private EntityRepositoryInterface $repoRepo;
private EntityRepositoryInterface $workspaceRepoRepo;
```

The `setUp()` still creates `EntityRepository` instances (concrete), which is fine — only the property types need to be the interface.

- [ ] **Step 2: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Controller/InternalCodeTaskControllerTest.php`
Expected: All pass

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Controller/InternalCodeTaskControllerTest.php
git commit -m "fix(#587): use EntityRepositoryInterface in test property types"
```

### Task 7: Stage untracked tool files and final commit

**Files:**
- Stage: `src/Domain/Chat/Tool/CodeTaskCreateTool.php`
- Stage: `src/Domain/Chat/Tool/CodeTaskStatusTool.php`
- Stage: `src/Domain/Chat/Tool/RepoCloneTool.php`

These files are already written and wired in ChatStreamController. They just need to be committed.

- [ ] **Step 1: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 2: Run PHPStan**

Run: `vendor/bin/phpstan analyse`
Expected: No new errors (baseline may need regeneration)

- [ ] **Step 3: Commit tool files**

```bash
git add src/Domain/Chat/Tool/CodeTaskCreateTool.php src/Domain/Chat/Tool/CodeTaskStatusTool.php src/Domain/Chat/Tool/RepoCloneTool.php
git commit -m "feat(#594): add CodeTaskCreate, CodeTaskStatus, and RepoClone PHP agent tools"
```

- [ ] **Step 4: Close issues**

Close #587 and #594 referencing the commits.

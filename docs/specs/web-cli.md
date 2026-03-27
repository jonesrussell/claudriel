# Web & CLI Specification

## File Map

| File | Purpose |
|------|---------|
| `src/Controller/DayBriefController.php` | `GET /brief` — returns JSON day brief |
| `src/Controller/InternalCodeTaskController.php` | `POST /api/internal/code-tasks/create`, `GET /api/internal/code-tasks/{uuid}/status` |
| `src/Command/BriefCommand.php` | `claudriel:brief` — prints day brief to terminal |
| `src/Command/CommitmentsCommand.php` | `claudriel:commitments` — lists active commitments |
| `src/Command/CodeTaskRunCommand.php` | `claudriel:code-task:run {uuid}` — executes a queued code task |
| `src/McClaudiaServiceProvider.php` | Registers routes + entity types |
| `public/index.php` | HTTP entry point (Waaseyaa HttpKernel) |
| `bin/waaseyaa` | CLI entry point (Waaseyaa ConsoleKernel) |
| `templates/day-brief.html.twig` | Twig template (exists, not yet rendered) |

## Route Registration

```php
// In McClaudiaServiceProvider::routes(WaaseyaaRouter $router)
$router->addRoute(
    'claudriel.brief',
    RouteBuilder::create('/brief')
        ->controller(DayBriefController::class . '::show')
        ->allowAll()
        ->methods('GET')
        ->build(),
);
```

## CLI Command Registration

Commands use Symfony Console `#[AsCommand]` attribute:

```php
#[AsCommand(name: 'claudriel:brief', description: 'Show your Day Brief')]
final class BriefCommand extends Command { ... }
```

**Known issue:** ConsoleKernel auto-discovery may not pick up commands automatically (issue #9 — "wire CLI commands into ConsoleKernel" is open). Manual registration in kernel bootstrap may be needed.

## DayBriefController

```php
public function show(): Response
// Calls DayBriefAssembler::assemble(tenantId: 'default', since: -24 hours)
// Returns: Symfony\Component\HttpFoundation\Response with JSON body
// Content-Type: application/json, HTTP 200
```

## BriefCommand Output Format

```
<info>Day Brief</info>

<comment>Recent events (N)</comment>
  [source] type

<comment>Pending commitments (N)</comment>
  • Title (80% confidence)

<error>Drifting (no activity 48h+)</error>  ← only if drifting_commitments non-empty
  ! Title
```

## CommitmentsCommand Output Format

```
[STATUS] Title
```
Outputs "No active commitments." if none found. Uses `findBy(['status' => 'active'])`.

## Waaseyaa Kernels

```
public/index.php  → new Waaseyaa\Foundation\Kernel\HttpKernel(dirname(__DIR__))
bin/waaseyaa      → new Waaseyaa\Foundation\Kernel\ConsoleKernel(dirname(__DIR__))
```

Both kernels resolve service providers from `src/McClaudiaServiceProvider.php` automatically if registered in config.

## InternalCodeTaskController

HMAC-authenticated internal API for the agent subprocess to create and monitor code tasks.

```php
// POST /api/internal/code-tasks/create
// Body: { "repo": "owner/name", "prompt": "...", "branch_name"?: "..." }
// Returns: { "task_uuid": "...", "status": "queued", "branch_name": "..." }
public function create(...): SsrResponse

// GET /api/internal/code-tasks/{uuid}/status
// Returns: { "uuid", "status", "branch_name", "pr_url", "summary", "diff_preview", "error", "started_at", "completed_at" }
public function status(...): SsrResponse
```

The `create` endpoint resolves or creates a workspace + repo for the given GitHub repo, saves a `CodeTask` entity, then dispatches `claudriel:code-task:run {uuid}` as a background process.

## CodeTaskRunCommand

```php
#[AsCommand(name: 'claudriel:code-task:run', description: 'Execute a queued code task via Claude Code CLI')]
// Argument: uuid (required) — CodeTask UUID
// Loads CodeTask, resolves repo path via GitRepositoryManager, delegates to CodeTaskRunner::run()
// Exit: SUCCESS if task completed, FAILURE otherwise
```

## Adding New Routes

1. Create controller in `src/Controller/`
2. Add `->addRoute(name, RouteBuilder::create('/path')...->build())` in `McClaudiaServiceProvider::routes()`
3. Name routes as `claudriel.<name>` for clarity

## Adding New Commands

1. Create in `src/Command/`, extend `Symfony\Component\Console\Command\Command`
2. Add `#[AsCommand(name: 'claudriel:foo')]` attribute
3. Verify ConsoleKernel picks it up (see issue #9)

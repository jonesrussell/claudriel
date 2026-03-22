# Telescope Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Integrate `waaseyaa/telescope` to record HTTP requests, database queries, events, and cache operations for observability across Claudriel.

**Architecture:** Register `TelescopeServiceProvider` in Claudriel's provider list. Create a `TelescopeServiceProvider` in Claudriel that configures the Waaseyaa telescope with Claudriel-specific settings (SQLite file path, ignore paths). Wire recorders into the HTTP middleware pipeline and entity event system. Add a CLI command to query entries and a route to view them in the admin.

**Tech Stack:** PHP 8.4, Waaseyaa telescope package, SQLite storage, PHPUnit

**Issue:** #478
**Spec:** `docs/superpowers/specs/2026-03-22-waaseyaa-full-alignment-design.md`

---

## File Structure

| File | Purpose |
|------|---------|
| `src/Provider/TelescopeServiceProvider.php` | Claudriel service provider that configures and registers Waaseyaa telescope |
| `src/Middleware/TelescopeRequestMiddleware.php` | HTTP middleware that records request start/end via `RequestRecorder` |
| `src/Command/TelescopeCommand.php` | CLI command to query and display telescope entries |
| `tests/Unit/Provider/TelescopeServiceProviderTest.php` | Tests provider configuration and recorder availability |
| `tests/Unit/Middleware/TelescopeRequestMiddlewareTest.php` | Tests request recording middleware |
| `tests/Unit/Command/TelescopeCommandTest.php` | Tests CLI output formatting |

---

### Task 1: Add waaseyaa/telescope dependency

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Require the package**

Run: `composer require waaseyaa/telescope:dev-main`

- [ ] **Step 2: Verify it installed**

Run: `composer show waaseyaa/telescope`
Expected: Package info displayed with version `dev-main`

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "feat(#478): add waaseyaa/telescope dependency"
```

---

### Task 2: Create Claudriel TelescopeServiceProvider

**Files:**
- Create: `src/Provider/TelescopeServiceProvider.php`
- Test: `tests/Unit/Provider/TelescopeServiceProviderTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Provider/TelescopeServiceProviderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Provider;

use Claudriel\Provider\TelescopeServiceProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\Recorder\CacheRecorder;
use Waaseyaa\Telescope\Recorder\EventRecorder;
use Waaseyaa\Telescope\Recorder\QueryRecorder;
use Waaseyaa\Telescope\Recorder\RequestRecorder;

final class TelescopeServiceProviderTest extends TestCase
{
    public function test_provides_all_recorders_when_enabled(): void
    {
        $provider = new TelescopeServiceProvider();
        $telescope = $provider->getTelescope();

        self::assertTrue($telescope->isEnabled());
        self::assertInstanceOf(QueryRecorder::class, $telescope->getQueryRecorder());
        self::assertInstanceOf(EventRecorder::class, $telescope->getEventRecorder());
        self::assertInstanceOf(RequestRecorder::class, $telescope->getRequestRecorder());
        self::assertInstanceOf(CacheRecorder::class, $telescope->getCacheRecorder());
    }

    public function test_store_persists_and_retrieves_entries(): void
    {
        $provider = new TelescopeServiceProvider();
        $telescope = $provider->getTelescope();
        $store = $telescope->getStore();

        $store->store('test', ['message' => 'hello']);

        $entries = $store->query('test');
        self::assertCount(1, $entries);
        self::assertSame('hello', $entries[0]->data['message']);
    }

    public function test_ignores_health_and_broadcast_paths(): void
    {
        $provider = new TelescopeServiceProvider();
        $telescope = $provider->getTelescope();
        $recorder = $telescope->getRequestRecorder();

        // Record a health check request — should be ignored
        $recorder->record('GET', '/health', 200, 1.0);
        $entries = $telescope->getStore()->query('request');
        self::assertCount(0, $entries);

        // Record a normal request — should be recorded
        $recorder->record('GET', '/brief', 200, 5.0);
        $entries = $telescope->getStore()->query('request');
        self::assertCount(1, $entries);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Provider/TelescopeServiceProviderTest.php -v`
Expected: FAIL — `TelescopeServiceProvider` class not found

- [ ] **Step 3: Write the provider**

Create `src/Provider/TelescopeServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Telescope\Storage\SqliteTelescopeStore;
use Waaseyaa\Telescope\TelescopeServiceProvider as WaaseyaaTelescopeServiceProvider;

final class TelescopeServiceProvider extends ServiceProvider
{
    private ?WaaseyaaTelescopeServiceProvider $telescope = null;

    public function register(): void
    {
        // Telescope is configured lazily via getTelescope()
    }

    public function getTelescope(): WaaseyaaTelescopeServiceProvider
    {
        if ($this->telescope === null) {
            $storagePath = $this->getStoragePath();
            $store = $storagePath !== null
                ? SqliteTelescopeStore::createFromPath($storagePath)
                : SqliteTelescopeStore::createInMemory();

            $this->telescope = new WaaseyaaTelescopeServiceProvider(
                config: [
                    'enabled' => true,
                    'record' => [
                        'queries' => true,
                        'events' => true,
                        'requests' => true,
                        'cache' => true,
                        'slow_query_threshold' => 100.0,
                        'slow_queries_only' => false,
                    ],
                    'ignore_paths' => ['/health', '/api/broadcast/*', '/favicon.ico'],
                ],
                store: $store,
            );
        }

        return $this->telescope;
    }

    private function getStoragePath(): ?string
    {
        $varDir = dirname(__DIR__, 2) . '/var';
        if (is_dir($varDir) || mkdir($varDir, 0o755, true)) {
            return $varDir . '/telescope.sqlite';
        }

        return null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Provider/TelescopeServiceProviderTest.php -v`
Expected: 3 tests pass

- [ ] **Step 5: Commit**

```bash
git add src/Provider/TelescopeServiceProvider.php tests/Unit/Provider/TelescopeServiceProviderTest.php
git commit -m "feat(#478): create TelescopeServiceProvider with config and storage"
```

---

### Task 3: Create request recording middleware

**Files:**
- Create: `src/Middleware/TelescopeRequestMiddleware.php`
- Test: `tests/Unit/Middleware/TelescopeRequestMiddlewareTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Middleware/TelescopeRequestMiddlewareTest.php`:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Middleware;

use Claudriel\Middleware\TelescopeRequestMiddleware;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\Storage\SqliteTelescopeStore;
use Waaseyaa\Telescope\TelescopeServiceProvider;

final class TelescopeRequestMiddlewareTest extends TestCase
{
    public function test_records_request_entry(): void
    {
        $store = SqliteTelescopeStore::createInMemory();
        $telescope = new TelescopeServiceProvider(
            config: ['enabled' => true],
            store: $store,
        );

        $middleware = new TelescopeRequestMiddleware($telescope);

        // Simulate recording a request
        $middleware->recordRequest('GET', '/brief', 200, 12.5, 'DayBriefController::index');

        $entries = $store->query('request');
        self::assertCount(1, $entries);
        self::assertSame('GET', $entries[0]->data['method']);
        self::assertSame('/brief', $entries[0]->data['uri']);
        self::assertSame(200, $entries[0]->data['status_code']);
        self::assertSame('DayBriefController::index', $entries[0]->data['controller']);
    }

    public function test_skips_recording_when_telescope_disabled(): void
    {
        $store = SqliteTelescopeStore::createInMemory();
        $telescope = new TelescopeServiceProvider(
            config: ['enabled' => false],
            store: $store,
        );

        $middleware = new TelescopeRequestMiddleware($telescope);
        $middleware->recordRequest('GET', '/brief', 200, 12.5);

        $entries = $store->query('request');
        self::assertCount(0, $entries);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Middleware/TelescopeRequestMiddlewareTest.php -v`
Expected: FAIL — class not found

- [ ] **Step 3: Write the middleware**

Create `src/Middleware/TelescopeRequestMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Middleware;

use Waaseyaa\Telescope\TelescopeServiceProvider;

final class TelescopeRequestMiddleware
{
    public function __construct(
        private readonly TelescopeServiceProvider $telescope,
    ) {}

    /**
     * Record an HTTP request to telescope.
     *
     * Call this from the kernel's request handling, passing timing data.
     */
    public function recordRequest(
        string $method,
        string $uri,
        int $statusCode,
        float $durationMs,
        string $controller = '',
        array $middleware = [],
    ): void {
        $recorder = $this->telescope->getRequestRecorder();
        if ($recorder === null) {
            return;
        }

        $recorder->record($method, $uri, $statusCode, $durationMs, $controller, $middleware);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Middleware/TelescopeRequestMiddlewareTest.php -v`
Expected: 2 tests pass

- [ ] **Step 5: Commit**

```bash
git add src/Middleware/TelescopeRequestMiddleware.php tests/Unit/Middleware/TelescopeRequestMiddlewareTest.php
git commit -m "feat(#478): add TelescopeRequestMiddleware for HTTP request recording"
```

---

### Task 4: Wire telescope into Claudriel bootstrap

**Files:**
- Modify: `composer.json` (providers array)
- Modify: `src/Provider/ClaudrielServiceProvider.php` (wire middleware into request lifecycle)

- [ ] **Step 1: Register the provider in composer.json**

Add `"Claudriel\\Provider\\TelescopeServiceProvider"` to the `extra.waaseyaa.providers` array in `composer.json`, **before** `ClaudrielServiceProvider` (so telescope is available when routes are registered).

- [ ] **Step 2: Wire request recording into ClaudrielServiceProvider**

In `ClaudrielServiceProvider::middleware()`, add telescope request middleware. Check how existing middleware is registered (look at `AccountSessionMiddleware` pattern) and follow the same approach.

The key integration point: after the HTTP response is sent, call `TelescopeRequestMiddleware::recordRequest()` with the request method, URI, status code, and duration. The exact wiring depends on how Waaseyaa's middleware pipeline exposes timing — check `AbstractKernel::handle()` for where to hook in.

If the middleware pipeline doesn't support post-response hooks, wire the recording directly in `public/index.php` after `$kernel->handle()` returns.

- [ ] **Step 3: Run the full test suite to verify no regressions**

Run: `vendor/bin/phpunit --testsuite Unit`
Expected: All existing tests pass

- [ ] **Step 4: Manual smoke test**

Run: `PHP_CLI_SERVER_WORKERS=4 php -S 0.0.0.0:8081 -t public`
Visit `http://localhost:8081/brief` in browser.
Check that `var/telescope.sqlite` exists and has entries:

Run: `sqlite3 var/telescope.sqlite "SELECT type, json_extract(data, '$.uri') FROM telescope_entries LIMIT 5;"`
Expected: Shows `request|/brief` entries

- [ ] **Step 5: Commit**

```bash
git add composer.json src/Provider/ClaudrielServiceProvider.php public/index.php
git commit -m "feat(#478): wire telescope into Claudriel request lifecycle"
```

---

### Task 5: Add query recording

**Files:**
- Modify: `src/Provider/TelescopeServiceProvider.php` (expose query recorder for database layer)

- [ ] **Step 1: Identify where database queries execute**

Claudriel uses `SqlEntityStorage` which goes through Waaseyaa's database layer. Check if Waaseyaa's database package has an event or hook for query execution. Look at:
- `waaseyaa/packages/database-legacy/src/` for query execution hooks
- `waaseyaa/packages/entity-storage/src/SqlEntityStorage.php` for where queries run

If the database layer dispatches events, wire `QueryRecorder` as a listener.
If not, this is a **framework gap** — create a Waaseyaa issue for query event dispatching and skip this sub-task for now (telescope will still record requests, events, and cache).

- [ ] **Step 2: Wire query recording if hook exists**

If a hook exists, register the `QueryRecorder` as a listener in `TelescopeServiceProvider::register()`.

- [ ] **Step 3: Test query recording**

Run the manual smoke test again and check for `query` type entries:

Run: `sqlite3 var/telescope.sqlite "SELECT count(*) FROM telescope_entries WHERE type='query';"`
Expected: Count > 0 (if wired) or 0 (if framework gap identified)

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat(#478): wire query recording into telescope"
```

---

### Task 6: Add event recording

**Files:**
- Modify: `src/Provider/TelescopeServiceProvider.php`

- [ ] **Step 1: Identify Claudriel's event dispatcher**

Check how `ClaudrielServiceProvider` dispatches events. Look for `EventDispatcherInterface` or similar in the kernel/providers. The `EntityEvent` dispatched on entity save is the primary target.

- [ ] **Step 2: Wire EventRecorder as a listener**

Register `EventRecorder::record()` as a listener for all dispatched events. The recorder needs: event class name, payload array, and listener list.

- [ ] **Step 3: Smoke test event recording**

Trigger an entity save (e.g., via the admin or CLI) and check:

Run: `sqlite3 var/telescope.sqlite "SELECT json_extract(data, '$.event') FROM telescope_entries WHERE type='event' LIMIT 5;"`
Expected: Shows event class names

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat(#478): wire event recording into telescope"
```

---

### Task 7: Create telescope CLI command

**Files:**
- Create: `src/Command/TelescopeCommand.php`
- Test: `tests/Unit/Command/TelescopeCommandTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Command/TelescopeCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Command;

use Claudriel\Command\TelescopeCommand;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\Storage\SqliteTelescopeStore;
use Waaseyaa\Telescope\TelescopeServiceProvider;

final class TelescopeCommandTest extends TestCase
{
    public function test_formats_request_entries(): void
    {
        $store = SqliteTelescopeStore::createInMemory();
        $telescope = new TelescopeServiceProvider(store: $store);

        $telescope->getRequestRecorder()->record('GET', '/brief', 200, 12.5);
        $telescope->getRequestRecorder()->record('POST', '/graphql', 200, 45.3);

        $command = new TelescopeCommand($telescope);
        $output = $command->formatEntries('request', 10);

        self::assertStringContainsString('GET', $output);
        self::assertStringContainsString('/brief', $output);
        self::assertStringContainsString('200', $output);
        self::assertStringContainsString('POST', $output);
        self::assertStringContainsString('/graphql', $output);
    }

    public function test_filters_by_type(): void
    {
        $store = SqliteTelescopeStore::createInMemory();
        $telescope = new TelescopeServiceProvider(store: $store);

        $telescope->getRequestRecorder()->record('GET', '/brief', 200, 12.5);
        $telescope->getEventRecorder()->record('EntitySaved', ['id' => 1]);

        $command = new TelescopeCommand($telescope);

        $requestOutput = $command->formatEntries('request', 10);
        self::assertStringContainsString('/brief', $requestOutput);
        self::assertStringNotContainsString('EntitySaved', $requestOutput);
    }

    public function test_shows_empty_message_when_no_entries(): void
    {
        $store = SqliteTelescopeStore::createInMemory();
        $telescope = new TelescopeServiceProvider(store: $store);

        $command = new TelescopeCommand($telescope);
        $output = $command->formatEntries('request', 10);

        self::assertStringContainsString('No entries', $output);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Command/TelescopeCommandTest.php -v`
Expected: FAIL — class not found

- [ ] **Step 3: Write the command**

Create `src/Command/TelescopeCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Telescope\TelescopeServiceProvider;

#[AsCommand(name: 'claudriel:telescope', description: 'Query telescope observability entries')]
final class TelescopeCommand extends Command
{
    public function __construct(
        private readonly TelescopeServiceProvider $telescope,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('type', InputArgument::OPTIONAL, 'Entry type: request, query, event, cache', 'request');
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Number of entries', '20');
        $this->addOption('clear', null, InputOption::VALUE_NONE, 'Clear all entries');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('clear')) {
            $this->telescope->getStore()->clear();
            $output->writeln('Telescope entries cleared.');
            return Command::SUCCESS;
        }

        $type = $input->getArgument('type');
        $limit = (int) $input->getOption('limit');

        $output->writeln($this->formatEntries($type, $limit));

        return Command::SUCCESS;
    }

    public function formatEntries(string $type, int $limit): string
    {
        $entries = $this->telescope->getStore()->query($type, $limit);

        if ($entries === []) {
            return "No entries found for type: {$type}";
        }

        $lines = [];
        foreach ($entries as $entry) {
            $time = $entry->createdAt->format('H:i:s.u');
            $summary = $this->summarizeEntry($entry->type, $entry->data);
            $lines[] = "[{$time}] {$summary}";
        }

        return implode("\n", $lines);
    }

    private function summarizeEntry(string $type, array $data): string
    {
        return match ($type) {
            'request' => sprintf(
                '%s %s → %d (%.1fms)',
                $data['method'] ?? '?',
                $data['uri'] ?? '?',
                $data['status_code'] ?? 0,
                $data['duration'] ?? 0,
            ),
            'query' => sprintf(
                '%s (%.1fms)%s',
                mb_substr($data['sql'] ?? '?', 0, 80),
                $data['duration'] ?? 0,
                ($data['slow'] ?? false) ? ' [SLOW]' : '',
            ),
            'event' => $data['event'] ?? '?',
            'cache' => sprintf(
                '%s %s',
                strtoupper($data['operation'] ?? '?'),
                $data['key'] ?? '?',
            ),
            default => json_encode($data, JSON_THROW_ON_ERROR),
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Command/TelescopeCommandTest.php -v`
Expected: 3 tests pass

- [ ] **Step 5: Register the command**

Add the command to `ClaudrielServiceProvider::commands()` following the existing pattern (check how other commands like `BriefCommand` are registered). The command needs `TelescopeServiceProvider` injected — get it from the service resolver or instantiate inline with the telescope provider.

- [ ] **Step 6: Smoke test the CLI**

Run: `php bin/claudriel claudriel:telescope --limit 5`
Expected: Shows recent telescope entries (or "No entries" if no requests have been made)

Run: `php bin/claudriel claudriel:telescope query --limit 5`
Expected: Shows recent query entries

- [ ] **Step 7: Commit**

```bash
git add src/Command/TelescopeCommand.php tests/Unit/Command/TelescopeCommandTest.php src/Provider/ClaudrielServiceProvider.php
git commit -m "feat(#478): add claudriel:telescope CLI command"
```

---

### Task 8: Run full test suite and verify

- [ ] **Step 1: Run complete test suite**

Run: `vendor/bin/phpunit`
Expected: All tests pass with no regressions

- [ ] **Step 2: Run linter**

Run: `vendor/bin/pint --dirty`
Expected: No formatting issues (or auto-fixed)

- [ ] **Step 3: Run PHPStan if configured**

Run: `vendor/bin/phpstan analyse src/ tests/ --level=5` (or whatever level the project uses)
Expected: No new errors

- [ ] **Step 4: Final smoke test**

1. Start dev server: `PHP_CLI_SERVER_WORKERS=4 php -S 0.0.0.0:8081 -t public`
2. Visit `/brief` and `/admin/` in browser
3. Run: `php bin/claudriel claudriel:telescope --limit 10`
4. Verify request entries appear with correct URIs, status codes, and timing

- [ ] **Step 5: Final commit if any cleanup needed**

```bash
git add -A
git commit -m "chore(#478): telescope integration cleanup and verification"
```

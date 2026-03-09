# Phase 2 — Daily Use Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Wire CLI commands, add Day Brief v1 grouping, commitment status actions, and session tracking so MyClaudia is useful every morning.

**Architecture:** Four sequential issues (#9, #12, #14, #13). Each issue is a self-contained task with tests-first discipline. Framework changes (Waaseyaa `ServiceProvider` + `ConsoleKernel`) are minimal and backward-compatible.

**Tech Stack:** PHP 8.3, Symfony Console, PHPUnit (via `vendor/bin/phpunit`), SQLite, Waaseyaa framework at `/home/jones/dev/waaseyaa/packages/`.

---

## Context: How the kernel works

- `bin/waaseyaa` → `new Waaseyaa\Foundation\Kernel\ConsoleKernel(dirname(__DIR__))` → `handle()`
- `ConsoleKernel` is `final`. It calls `$this->boot()` (populates `$this->providers`), then manually registers all framework commands into a `WaaseyaaApplication`, then runs it.
- **The gap**: `ConsoleKernel` never calls any method on registered `ServiceProvider` instances to collect app-level commands. So `BriefCommand` and `CommitmentsCommand` never get added to the app.
- `ServiceProvider::routes(WaaseyaaRouter)` already follows the pattern we need: the kernel calls a provider method and passes a dependency. We add `commands(...)` in the same style.

## Test runner

```bash
cd /home/jones/dev/myclaudia && vendor/bin/phpunit --testdox
```

For a single test class:
```bash
vendor/bin/phpunit tests/Unit/path/to/FooTest.php --testdox
```

---

## Task 1: Wire CLI commands into ConsoleKernel (Issue #9)

**Files:**
- Modify: `/home/jones/dev/waaseyaa/packages/foundation/src/ServiceProvider/ServiceProvider.php`
- Modify: `/home/jones/dev/waaseyaa/packages/foundation/src/Kernel/ConsoleKernel.php`
- Modify: `/home/jones/dev/myclaudia/src/McClaudiaServiceProvider.php`
- Create: `/home/jones/dev/myclaudia/tests/Unit/Command/BriefCommandTest.php`
- Create: `/home/jones/dev/myclaudia/tests/Unit/Command/CommitmentsCommandTest.php`

### Step 1: Write failing test for BriefCommand

```php
<?php
// tests/Unit/Command/BriefCommandTest.php
declare(strict_types=1);

namespace MyClaudia\Tests\Unit\Command;

use MyClaudia\Command\BriefCommand;
use MyClaudia\DayBrief\DayBriefAssembler;
use MyClaudia\DriftDetector;
use MyClaudia\Entity\Commitment;
use MyClaudia\Entity\McEvent;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class BriefCommandTest extends TestCase
{
    public function testBriefCommandOutputsHeader(): void
    {
        $dispatcher    = new EventDispatcher();
        $eventRepo     = new EntityRepository(
            new EntityType('mc_event', 'Event', McEvent::class, ['id' => 'eid', 'uuid' => 'uuid']),
            new InMemoryStorageDriver(),
            $dispatcher,
        );
        $commitmentRepo = new EntityRepository(
            new EntityType('commitment', 'Commitment', Commitment::class, ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver(),
            $dispatcher,
        );
        $assembler = new DayBriefAssembler($eventRepo, $commitmentRepo, new DriftDetector($commitmentRepo));
        $command   = new BriefCommand($assembler);
        $tester    = new CommandTester($command);

        $tester->execute([]);

        self::assertStringContainsString('Day Brief', $tester->getDisplay());
        self::assertStringContainsString('Recent events (0)', $tester->getDisplay());
    }
}
```

### Step 2: Run to verify it fails (or passes — if it already works in isolation)

```bash
cd /home/jones/dev/myclaudia && vendor/bin/phpunit tests/Unit/Command/BriefCommandTest.php --testdox
```

Expected: PASS (BriefCommand itself is fine; the issue is only that ConsoleKernel doesn't register it)

### Step 3: Write failing test for CommitmentsCommand

```php
<?php
// tests/Unit/Command/CommitmentsCommandTest.php
declare(strict_types=1);

namespace MyClaudia\Tests\Unit\Command;

use MyClaudia\Command\CommitmentsCommand;
use MyClaudia\Entity\Commitment;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class CommitmentsCommandTest extends TestCase
{
    public function testNoCommitmentsOutputsMessage(): void
    {
        $repo = new EntityRepository(
            new EntityType('commitment', 'Commitment', Commitment::class, ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver(),
            new EventDispatcher(),
        );
        $command = new CommitmentsCommand($repo);
        $tester  = new CommandTester($command);

        $tester->execute([]);

        self::assertStringContainsString('No active commitments', $tester->getDisplay());
    }

    public function testListsActiveCommitments(): void
    {
        $repo = new EntityRepository(
            new EntityType('commitment', 'Commitment', Commitment::class, ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver(),
            new EventDispatcher(),
        );
        $repo->save(new Commitment(['title' => 'Write tests', 'status' => 'active']));
        $command = new CommitmentsCommand($repo);
        $tester  = new CommandTester($command);

        $tester->execute([]);

        self::assertStringContainsString('Write tests', $tester->getDisplay());
        self::assertStringContainsString('ACTIVE', $tester->getDisplay());
    }
}
```

### Step 4: Run tests

```bash
cd /home/jones/dev/myclaudia && vendor/bin/phpunit tests/Unit/Command/ --testdox
```

Both should PASS (commands work in isolation).

### Step 5: Add `commands()` method to ServiceProvider base class

Edit `/home/jones/dev/waaseyaa/packages/foundation/src/ServiceProvider/ServiceProvider.php`.

After the `routes()` method (line 30), add:

```php
/**
 * Return application-level commands for this provider.
 *
 * @param \Waaseyaa\Entity\EntityTypeManager $entityTypeManager
 * @param \Waaseyaa\Database\PdoDatabase $database
 * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $dispatcher
 * @return \Symfony\Component\Console\Command\Command[]
 */
public function commands(
    \Waaseyaa\Entity\EntityTypeManager $entityTypeManager,
    \Waaseyaa\Database\PdoDatabase $database,
    \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $dispatcher,
): array {
    return [];
}
```

### Step 6: Call provider commands() in ConsoleKernel

Edit `/home/jones/dev/waaseyaa/packages/foundation/src/Kernel/ConsoleKernel.php`.

After line 203 (`new TelescopePruneCommand(),`) and before `]);`, add nothing yet. After `]);` (the closing of `registerCommands`), add:

```php
foreach ($this->providers as $provider) {
    $pluginCommands = $provider->commands($this->entityTypeManager, $this->database, $this->dispatcher);
    if ($pluginCommands !== []) {
        $app->registerCommands($pluginCommands);
    }
}
```

### Step 7: Implement `commands()` in McClaudiaServiceProvider

Edit `/home/jones/dev/myclaudia/src/McClaudiaServiceProvider.php`.

Add imports at top:
```php
use MyClaudia\Command\BriefCommand;
use MyClaudia\Command\CommitmentsCommand;
use MyClaudia\DayBrief\DayBriefAssembler;
use MyClaudia\DriftDetector;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Driver\SqliteStorageDriver; // check actual class name
use Symfony\Component\EventDispatcher\EventDispatcher;
```

> **Important:** Check the actual `EntityRepository` constructor signature by reading:
> `/home/jones/dev/waaseyaa/packages/entity-storage/src/EntityRepository.php`
> The test already demonstrates the correct pattern using `InMemoryStorageDriver`.
> For production, `ConsoleKernel` passes the real `PdoDatabase`; look at how `AbstractKernel::bootEntityTypeManager()` builds the storage — it uses `SqlEntityStorage`. You can get the repository via `$entityTypeManager->getRepository('mc_event')`.

Add `commands()` method to `McClaudiaServiceProvider`:

```php
public function commands(
    \Waaseyaa\Entity\EntityTypeManager $entityTypeManager,
    \Waaseyaa\Database\PdoDatabase $database,
    \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $dispatcher,
): array {
    $eventRepo      = $entityTypeManager->getRepository('mc_event');
    $commitmentRepo = $entityTypeManager->getRepository('commitment');
    $driftDetector  = new DriftDetector($commitmentRepo);
    $assembler      = new DayBriefAssembler($eventRepo, $commitmentRepo, $driftDetector);

    return [
        new BriefCommand($assembler),
        new CommitmentsCommand($commitmentRepo),
    ];
}
```

> **Verify** that `EntityTypeManager::getRepository()` exists and returns an `EntityRepositoryInterface`.
> If not, check `AbstractKernel::bootEntityTypeManager()` to see how repositories are created.

### Step 8: Verify bin/waaseyaa lists myclaudia commands

```bash
cd /home/jones/dev/myclaudia && php bin/waaseyaa list
```

Expected: `myclaudia:brief` and `myclaudia:commitments` appear in the list.

### Step 9: Run all tests

```bash
cd /home/jones/dev/myclaudia && vendor/bin/phpunit --testdox
```

All green.

### Step 10: Commit

```bash
cd /home/jones/dev/myclaudia
git add src/McClaudiaServiceProvider.php tests/Unit/Command/
git commit -m "feat(#9): wire CLI commands into ConsoleKernel via ServiceProvider::commands()"

cd /home/jones/dev/waaseyaa
git add packages/foundation/src/ServiceProvider/ServiceProvider.php packages/foundation/src/Kernel/ConsoleKernel.php
git commit -m "feat: add ServiceProvider::commands() hook for plugin CLI commands"
```

---

## Task 2: Day Brief v1 sections and grouping (Issue #12)

**Files:**
- Modify: `src/DayBrief/DayBriefAssembler.php`
- Modify: `src/Command/BriefCommand.php`
- Modify: `src/Controller/DayBriefController.php`
- Modify: `tests/Unit/DayBrief/DayBriefAssemblerTest.php`

### Step 1: Write failing test for people section

Add to `tests/Unit/DayBrief/DayBriefAssemblerTest.php`:

```php
public function testAssembleIncludesPeopleSection(): void
{
    $dispatcher = new EventDispatcher();
    $eventRepo  = new EntityRepository(
        new EntityType('mc_event', 'Event', McEvent::class, ['id' => 'eid', 'uuid' => 'uuid']),
        new InMemoryStorageDriver(),
        $dispatcher,
    );
    $commitmentRepo = new EntityRepository(
        new EntityType('commitment', 'Commitment', Commitment::class, ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
        new InMemoryStorageDriver(),
        $dispatcher,
    );

    $payload = json_encode(['from_email' => 'jane@example.com', 'from_name' => 'Jane Doe', 'subject' => 'Hello']);
    $eventRepo->save(new McEvent([
        'source'    => 'gmail',
        'type'      => 'message.received',
        'payload'   => $payload,
        'occurred'  => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
        'tenant_id' => 'user-1',
    ]));

    $assembler = new DayBriefAssembler($eventRepo, $commitmentRepo, new DriftDetector($commitmentRepo));
    $brief     = $assembler->assemble('user-1', new \DateTimeImmutable('-24 hours'));

    self::assertArrayHasKey('people', $brief);
    self::assertArrayHasKey('jane@example.com', $brief['people']);
    self::assertSame('Jane Doe', $brief['people']['jane@example.com']);
}

public function testAssembleGroupsEventsBySource(): void
{
    $dispatcher = new EventDispatcher();
    $eventRepo  = new EntityRepository(
        new EntityType('mc_event', 'Event', McEvent::class, ['id' => 'eid', 'uuid' => 'uuid']),
        new InMemoryStorageDriver(),
        $dispatcher,
    );
    $commitmentRepo = new EntityRepository(
        new EntityType('commitment', 'Commitment', Commitment::class, ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
        new InMemoryStorageDriver(),
        $dispatcher,
    );

    $eventRepo->save(new McEvent([
        'source' => 'gmail', 'type' => 'message.received',
        'payload' => json_encode(['subject' => 'Test']),
        'occurred' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
        'tenant_id' => 'user-1',
    ]));

    $assembler = new DayBriefAssembler($eventRepo, $commitmentRepo, new DriftDetector($commitmentRepo));
    $brief     = $assembler->assemble('user-1', new \DateTimeImmutable('-24 hours'));

    self::assertArrayHasKey('events_by_source', $brief);
    self::assertArrayHasKey('gmail', $brief['events_by_source']);
    self::assertCount(1, $brief['events_by_source']['gmail']);
}
```

### Step 2: Run to verify failure

```bash
vendor/bin/phpunit tests/Unit/DayBrief/DayBriefAssemblerTest.php --testdox
```

Expected: FAIL — `people` and `events_by_source` keys don't exist.

### Step 3: Update DayBriefAssembler

```php
/** @return array{recent_events: array, events_by_source: array, people: array<string,string>, pending_commitments: array, drifting_commitments: array} */
public function assemble(string $tenantId, \DateTimeImmutable $since): array
{
    $recentEvents = array_values(array_filter(
        $this->eventRepo->findBy(['tenant_id' => $tenantId]),
        fn ($e) => new \DateTimeImmutable($e->get('occurred') ?? 'now') >= $since,
    ));

    $eventsBySource = [];
    $people = [];
    foreach ($recentEvents as $event) {
        $source  = $event->get('source') ?? 'unknown';
        $eventsBySource[$source][] = $event;

        $payload = json_decode($event->get('payload') ?? '{}', true);
        $email   = $payload['from_email'] ?? null;
        $name    = $payload['from_name'] ?? null;
        if ($email !== null && $email !== '') {
            $people[$email] = $name ?? $email;
        }
    }

    return [
        'recent_events'        => $recentEvents,
        'events_by_source'     => $eventsBySource,
        'people'               => $people,
        'pending_commitments'  => $this->commitmentRepo->findBy(['status' => 'pending', 'tenant_id' => $tenantId]),
        'drifting_commitments' => $this->driftDetector->findDrifting($tenantId),
    ];
}
```

### Step 4: Run tests

```bash
vendor/bin/phpunit tests/Unit/DayBrief/ --testdox
```

Expected: all PASS.

### Step 5: Update BriefCommand to show subject + people

Update the output in `BriefCommand::execute()`:

```php
$output->writeln(sprintf('<comment>Recent events (%d)</comment>', count($brief['recent_events'])));
foreach ($brief['events_by_source'] as $source => $events) {
    $output->writeln(sprintf('  [%s] %d message(s)', strtoupper($source), count($events)));
    foreach ($events as $event) {
        $payload = json_decode($event->get('payload') ?? '{}', true);
        $subject = $payload['subject'] ?? $event->get('type');
        $from    = $payload['from_name'] ?? $payload['from_email'] ?? '';
        $output->writeln(sprintf('    • %s%s', $subject, $from !== '' ? " (from $from)" : ''));
    }
}

if (!empty($brief['people'])) {
    $output->writeln('');
    $output->writeln(sprintf('<comment>People (%d)</comment>', count($brief['people'])));
    foreach ($brief['people'] as $email => $name) {
        $output->writeln(sprintf('  %s <%s>', $name, $email));
    }
}
```

### Step 6: Run all tests

```bash
vendor/bin/phpunit --testdox
```

All green.

### Step 7: Commit

```bash
git add src/DayBrief/DayBriefAssembler.php src/Command/BriefCommand.php src/Controller/DayBriefController.php tests/Unit/DayBrief/DayBriefAssemblerTest.php
git commit -m "feat(#12): Day Brief v1 — source grouping and people section"
```

---

## Task 3: Session tracking for Day Brief (Issue #14)

**Files:**
- Create: `src/DayBrief/BriefSessionStore.php`
- Create: `tests/Unit/DayBrief/BriefSessionStoreTest.php`
- Modify: `src/Command/BriefCommand.php`
- Modify: `src/Controller/DayBriefController.php`
- Modify: `src/McClaudiaServiceProvider.php`

### Step 1: Write failing test for BriefSessionStore

```php
<?php
// tests/Unit/DayBrief/BriefSessionStoreTest.php
declare(strict_types=1);

namespace MyClaudia\Tests\Unit\DayBrief;

use MyClaudia\DayBrief\BriefSessionStore;
use PHPUnit\Framework\TestCase;

final class BriefSessionStoreTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir() . '/last-brief-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function testReturnsNullWhenNoSessionExists(): void
    {
        $store = new BriefSessionStore($this->tmpFile);
        self::assertNull($store->getLastBriefAt());
    }

    public function testStoreAndRetrieveTimestamp(): void
    {
        $store = new BriefSessionStore($this->tmpFile);
        $now   = new \DateTimeImmutable('2026-03-08T10:00:00+00:00');

        $store->recordBriefAt($now);

        $retrieved = $store->getLastBriefAt();
        self::assertNotNull($retrieved);
        self::assertSame($now->format(\DateTimeInterface::ATOM), $retrieved->format(\DateTimeInterface::ATOM));
    }

    public function testOverwritesPreviousTimestamp(): void
    {
        $store = new BriefSessionStore($this->tmpFile);
        $store->recordBriefAt(new \DateTimeImmutable('2026-03-07T08:00:00+00:00'));
        $store->recordBriefAt(new \DateTimeImmutable('2026-03-08T09:00:00+00:00'));

        $retrieved = $store->getLastBriefAt();
        self::assertNotNull($retrieved);
        self::assertStringContainsString('2026-03-08', $retrieved->format('Y-m-d'));
    }
}
```

### Step 2: Run to verify failure

```bash
vendor/bin/phpunit tests/Unit/DayBrief/BriefSessionStoreTest.php --testdox
```

Expected: FAIL — class doesn't exist.

### Step 3: Implement BriefSessionStore

```php
<?php
// src/DayBrief/BriefSessionStore.php
declare(strict_types=1);

namespace MyClaudia\DayBrief;

final class BriefSessionStore
{
    public function __construct(private readonly string $storageFile) {}

    public function getLastBriefAt(): ?\DateTimeImmutable
    {
        if (!file_exists($this->storageFile)) {
            return null;
        }

        $contents = trim((string) file_get_contents($this->storageFile));
        if ($contents === '') {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $contents);
        return $dt !== false ? $dt : null;
    }

    public function recordBriefAt(\DateTimeImmutable $at): void
    {
        $dir = dirname($this->storageFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->storageFile, $at->format(\DateTimeInterface::ATOM));
    }
}
```

### Step 4: Run tests

```bash
vendor/bin/phpunit tests/Unit/DayBrief/BriefSessionStoreTest.php --testdox
```

All three PASS.

### Step 5: Update BriefCommand to use session store

Change constructor to accept `BriefSessionStore`:

```php
public function __construct(
    private readonly DayBriefAssembler $assembler,
    private readonly BriefSessionStore $sessionStore,
) {
    parent::__construct();
}
```

Change `execute()` to use session:

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $since = $this->sessionStore->getLastBriefAt() ?? new \DateTimeImmutable('-24 hours');
    $brief = $this->assembler->assemble(tenantId: 'default', since: $since);

    // ... existing output ...

    $this->sessionStore->recordBriefAt(new \DateTimeImmutable());
    return Command::SUCCESS;
}
```

### Step 6: Update McClaudiaServiceProvider::commands() for new constructor

```php
$sessionStore = new BriefSessionStore($this->projectRoot . '/storage/last-brief');

return [
    new BriefCommand($assembler, $sessionStore),
    new CommitmentsCommand($commitmentRepo),
];
```

Add `use MyClaudia\DayBrief\BriefSessionStore;` to imports.

### Step 7: Update DayBriefController to use session store

```php
public function __construct(
    private readonly DayBriefAssembler $assembler,
    private readonly BriefSessionStore $sessionStore,
) {}

public function show(): Response
{
    $since = $this->sessionStore->getLastBriefAt() ?? new \DateTimeImmutable('-24 hours');
    $brief = $this->assembler->assemble(tenantId: 'default', since: $since);
    $this->sessionStore->recordBriefAt(new \DateTimeImmutable());
    // ... rest unchanged ...
}
```

> Note: `DayBriefController` is instantiated somewhere in the kernel/router. Check how it is constructed and wire `BriefSessionStore` there. The route is registered in `McClaudiaServiceProvider::routes()`. Look at how the Waaseyaa HttpKernel resolves controller dependencies (likely via reflection or a DI container). If it's manual instantiation, update the route handler.

### Step 8: Run all tests

```bash
vendor/bin/phpunit --testdox
```

All green.

### Step 9: Commit

```bash
git add src/DayBrief/BriefSessionStore.php src/Command/BriefCommand.php src/Controller/DayBriefController.php src/McClaudiaServiceProvider.php tests/Unit/DayBrief/BriefSessionStoreTest.php
git commit -m "feat(#14): session tracking — Day Brief shows events since last view"
```

---

## Task 4: Commitment actions — done / ignore / track (Issue #13)

**Files:**
- Create: `src/Command/CommitmentUpdateCommand.php`
- Create: `src/Controller/CommitmentUpdateController.php`
- Create: `tests/Unit/Command/CommitmentUpdateCommandTest.php`
- Create: `tests/Unit/Controller/CommitmentUpdateControllerTest.php`
- Modify: `src/McClaudiaServiceProvider.php`

### Step 1: Write failing test for CommitmentUpdateCommand

```php
<?php
// tests/Unit/Command/CommitmentUpdateCommandTest.php
declare(strict_types=1);

namespace MyClaudia\Tests\Unit\Command;

use MyClaudia\Command\CommitmentUpdateCommand;
use MyClaudia\Entity\Commitment;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class CommitmentUpdateCommandTest extends TestCase
{
    private EntityRepository $repo;
    private Commitment $commitment;

    protected function setUp(): void
    {
        $this->repo = new EntityRepository(
            new EntityType('commitment', 'Commitment', Commitment::class, ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver(),
            new EventDispatcher(),
        );
        $this->commitment = new Commitment(['title' => 'Review PR', 'status' => 'pending']);
        $this->repo->save($this->commitment);
    }

    public function testMarkAsDone(): void
    {
        $command = new CommitmentUpdateCommand($this->repo);
        $tester  = new CommandTester($command);

        $tester->execute(['uuid' => $this->commitment->uuid(), 'action' => 'done']);

        self::assertStringContainsString('done', $tester->getDisplay());
        $updated = $this->repo->load($this->commitment->uuid());
        self::assertSame('done', $updated->get('status'));
    }

    public function testMarkAsIgnored(): void
    {
        $command = new CommitmentUpdateCommand($this->repo);
        $tester  = new CommandTester($command);

        $tester->execute(['uuid' => $this->commitment->uuid(), 'action' => 'ignore']);

        $updated = $this->repo->load($this->commitment->uuid());
        self::assertSame('ignored', $updated->get('status'));
    }

    public function testMarkAsTracked(): void
    {
        $command = new CommitmentUpdateCommand($this->repo);
        $tester  = new CommandTester($command);

        $tester->execute(['uuid' => $this->commitment->uuid(), 'action' => 'track']);

        $updated = $this->repo->load($this->commitment->uuid());
        self::assertSame('active', $updated->get('status'));
    }

    public function testUnknownUuidReturnsFailure(): void
    {
        $command = new CommitmentUpdateCommand($this->repo);
        $tester  = new CommandTester($command);

        $tester->execute(['uuid' => 'no-such-uuid', 'action' => 'done']);

        self::assertStringContainsString('not found', $tester->getDisplay());
        self::assertSame(1, $tester->getStatusCode());
    }

    public function testInvalidActionReturnsFailure(): void
    {
        $command = new CommitmentUpdateCommand($this->repo);
        $tester  = new CommandTester($command);

        $tester->execute(['uuid' => $this->commitment->uuid(), 'action' => 'bogus']);

        self::assertStringContainsString('Invalid action', $tester->getDisplay());
        self::assertSame(1, $tester->getStatusCode());
    }
}
```

### Step 2: Run to verify failure

```bash
vendor/bin/phpunit tests/Unit/Command/CommitmentUpdateCommandTest.php --testdox
```

Expected: FAIL — class doesn't exist.

> **Check:** Does `EntityRepositoryInterface` (or `EntityRepository`) have a `load(string $uuid)` method?
> Look at `/home/jones/dev/waaseyaa/packages/entity-storage/src/EntityRepository.php` before implementing.
> The test uses `$this->repo->load($this->commitment->uuid())`. Verify this is the correct API.

### Step 3: Implement CommitmentUpdateCommand

```php
<?php
// src/Command/CommitmentUpdateCommand.php
declare(strict_types=1);

namespace MyClaudia\Command;

use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'myclaudia:commitment:update', description: 'Update a commitment status')]
final class CommitmentUpdateCommand extends Command
{
    private const ACTIONS = [
        'done'   => 'done',
        'ignore' => 'ignored',
        'track'  => 'active',
    ];

    public function __construct(private readonly EntityRepositoryInterface $commitmentRepo)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('uuid', InputArgument::REQUIRED, 'Commitment UUID')
            ->addArgument('action', InputArgument::REQUIRED, 'Action: done, ignore, track');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uuid   = (string) $input->getArgument('uuid');
        $action = (string) $input->getArgument('action');

        if (!array_key_exists($action, self::ACTIONS)) {
            $output->writeln(sprintf('<error>Invalid action "%s". Use: done, ignore, track</error>', $action));
            return Command::FAILURE;
        }

        $commitment = $this->commitmentRepo->load($uuid);
        if ($commitment === null) {
            $output->writeln(sprintf('<error>Commitment not found: %s</error>', $uuid));
            return Command::FAILURE;
        }

        $newStatus = self::ACTIONS[$action];
        $commitment->set('status', $newStatus);
        $this->commitmentRepo->save($commitment);

        $output->writeln(sprintf('<info>Commitment marked as %s.</info>', $newStatus));
        return Command::SUCCESS;
    }
}
```

> **Important:** Verify the method to load by UUID. It might be `findByUuid($uuid)`, `findOne(['uuid' => $uuid])`, or `load($uuid)`. Check `EntityRepositoryInterface` before finalizing.

### Step 4: Run tests

```bash
vendor/bin/phpunit tests/Unit/Command/CommitmentUpdateCommandTest.php --testdox
```

All five PASS.

### Step 5: Write failing test for CommitmentUpdateController

```php
<?php
// tests/Unit/Controller/CommitmentUpdateControllerTest.php
declare(strict_types=1);

namespace MyClaudia\Tests\Unit\Controller;

use MyClaudia\Controller\CommitmentUpdateController;
use MyClaudia\Entity\Commitment;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class CommitmentUpdateControllerTest extends TestCase
{
    public function testUpdateStatusToDone(): void
    {
        $repo = new EntityRepository(
            new EntityType('commitment', 'Commitment', Commitment::class, ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver(),
            new EventDispatcher(),
        );
        $commitment = new Commitment(['title' => 'Send email', 'status' => 'pending']);
        $repo->save($commitment);

        $controller = new CommitmentUpdateController($repo);
        $request    = Request::create('/commitments/' . $commitment->uuid(), 'PATCH', [], [], [], [],
            json_encode(['status' => 'done']));

        $response = $controller->update($request, $commitment->uuid());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame('done', $body['status']);
    }

    public function testReturns404ForUnknownUuid(): void
    {
        $repo = new EntityRepository(
            new EntityType('commitment', 'Commitment', Commitment::class, ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver(),
            new EventDispatcher(),
        );
        $controller = new CommitmentUpdateController($repo);
        $request    = Request::create('/commitments/no-such', 'PATCH', [], [], [], [],
            json_encode(['status' => 'done']));

        $response = $controller->update($request, 'no-such');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testReturns422ForInvalidStatus(): void
    {
        $repo = new EntityRepository(
            new EntityType('commitment', 'Commitment', Commitment::class, ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver(),
            new EventDispatcher(),
        );
        $commitment = new Commitment(['title' => 'Do thing', 'status' => 'pending']);
        $repo->save($commitment);

        $controller = new CommitmentUpdateController($repo);
        $request    = Request::create('/commitments/' . $commitment->uuid(), 'PATCH', [], [], [], [],
            json_encode(['status' => 'flying']));

        $response = $controller->update($request, $commitment->uuid());

        self::assertSame(422, $response->getStatusCode());
    }
}
```

### Step 6: Run to verify failure

```bash
vendor/bin/phpunit tests/Unit/Controller/CommitmentUpdateControllerTest.php --testdox
```

Expected: FAIL — class doesn't exist.

### Step 7: Implement CommitmentUpdateController

```php
<?php
// src/Controller/CommitmentUpdateController.php
declare(strict_types=1);

namespace MyClaudia\Controller;

use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CommitmentUpdateController
{
    private const VALID_STATUSES = ['pending', 'active', 'done', 'ignored'];

    public function __construct(private readonly EntityRepositoryInterface $commitmentRepo) {}

    public function update(Request $request, string $uuid): Response
    {
        $commitment = $this->commitmentRepo->load($uuid);
        if ($commitment === null) {
            return new Response(json_encode(['error' => 'Not found']), 404, ['Content-Type' => 'application/json']);
        }

        $body   = json_decode((string) $request->getContent(), true) ?? [];
        $status = $body['status'] ?? null;

        if (!is_string($status) || !in_array($status, self::VALID_STATUSES, true)) {
            return new Response(
                json_encode(['error' => 'Invalid status. Allowed: ' . implode(', ', self::VALID_STATUSES)]),
                422,
                ['Content-Type' => 'application/json'],
            );
        }

        $commitment->set('status', $status);
        $this->commitmentRepo->save($commitment);

        return new Response(
            json_encode(['uuid' => $uuid, 'status' => $status]),
            200,
            ['Content-Type' => 'application/json'],
        );
    }
}
```

### Step 8: Register new route and commands

In `McClaudiaServiceProvider::routes()`, add:

```php
$router->addRoute(
    'myclaudia.commitment.update',
    RouteBuilder::create('/commitments/{uuid}')
        ->controller(CommitmentUpdateController::class . '::update')
        ->allowAll()
        ->methods('PATCH')
        ->build(),
);
```

In `McClaudiaServiceProvider::commands()`, add:

```php
new CommitmentUpdateCommand($commitmentRepo),
```

### Step 9: Run all tests

```bash
vendor/bin/phpunit --testdox
```

All green.

### Step 10: Commit

```bash
git add src/Command/CommitmentUpdateCommand.php src/Controller/CommitmentUpdateController.php src/McClaudiaServiceProvider.php tests/Unit/Command/CommitmentUpdateCommandTest.php tests/Unit/Controller/CommitmentUpdateControllerTest.php
git commit -m "feat(#13): commitment actions — done/ignore/track via CLI and web"
```

---

## Final verification

```bash
cd /home/jones/dev/myclaudia
vendor/bin/phpunit --testdox
php bin/waaseyaa list                              # myclaudia:brief, :commitments, :commitment:update visible
php bin/waaseyaa myclaudia:brief                   # shows Day Brief
php bin/waaseyaa myclaudia:commitments             # shows commitments or "No active commitments."
```

---

## PR sequence

After all tasks pass locally, create one PR per issue or a single Phase 2 PR:

```
feat(#9): wire CLI commands into ConsoleKernel
feat(#12): Day Brief v1 — source grouping and people section
feat(#14): session tracking for Day Brief
feat(#13): commitment actions done/ignore/track
```

Each PR title format: `feat(#N): description` with `Closes #N` in body.

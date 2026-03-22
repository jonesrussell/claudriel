# Cache Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Integrate `waaseyaa/cache` to provide tag-based caching with automatic entity invalidation, starting with DayBriefAssembler.

**Architecture:** Register a `CacheServiceProvider` that creates a `CacheFactory` with SQLite-backed `DatabaseBackend` bins. Wire `EntityCacheInvalidator` as a listener on entity save/delete events to auto-invalidate cache tags. Wrap `DayBriefAssembler::assemble()` with cache-aside logic keyed by tenant + workspace + date, tagged with entity types.

**Tech Stack:** PHP 8.4, waaseyaa/cache (DatabaseBackend + CacheTagsInvalidator), SQLite via PDO, PHPUnit

**Issue:** #479
**Spec:** `docs/superpowers/specs/2026-03-22-waaseyaa-full-alignment-design.md`

---

## File Structure

| File | Purpose |
|------|---------|
| `src/Provider/CacheServiceProvider.php` | Creates CacheFactory, CacheTagsInvalidator, EntityCacheInvalidator; registers event listeners |
| `src/DayBrief/CachedDayBriefAssembler.php` | Decorator that wraps DayBriefAssembler with cache-aside logic |
| `tests/Unit/Provider/CacheServiceProviderTest.php` | Tests factory creation, bin registration, invalidator wiring |
| `tests/Unit/DayBrief/CachedDayBriefAssemblerTest.php` | Tests cache hit, cache miss, and invalidation scenarios |

---

### Task 1: Add waaseyaa/cache dependency

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Require the package**

Run: `composer require waaseyaa/cache:dev-main`

- [ ] **Step 2: Verify it installed**

Run: `composer show waaseyaa/cache`
Expected: Package info displayed

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "feat(#479): add waaseyaa/cache dependency"
```

---

### Task 2: Create CacheServiceProvider

**Files:**
- Create: `src/Provider/CacheServiceProvider.php`
- Test: `tests/Unit/Provider/CacheServiceProviderTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Provider/CacheServiceProviderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Provider;

use Claudriel\Provider\CacheServiceProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Cache\Backend\MemoryBackend;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Cache\TagAwareCacheInterface;

final class CacheServiceProviderTest extends TestCase
{
    public function test_provides_cache_factory_with_brief_bin(): void
    {
        $provider = new CacheServiceProvider();
        $factory = $provider->getCacheFactory();

        $briefCache = $factory->get('brief');
        self::assertInstanceOf(CacheBackendInterface::class, $briefCache);
        self::assertInstanceOf(TagAwareCacheInterface::class, $briefCache);
    }

    public function test_cache_set_and_get(): void
    {
        $provider = new CacheServiceProvider();
        $cache = $provider->getCacheFactory()->get('brief');

        $cache->set('test:key', ['data' => 'hello'], tags: ['entity:McEvent']);

        $item = $cache->get('test:key');
        self::assertSame(['data' => 'hello'], $item->data);
        self::assertTrue($item->valid);
    }

    public function test_tag_invalidation_clears_tagged_items(): void
    {
        $provider = new CacheServiceProvider();
        $cache = $provider->getCacheFactory()->get('brief');
        $invalidator = $provider->getCacheTagsInvalidator();

        $cache->set('key1', 'value1', tags: ['entity:McEvent']);
        $cache->set('key2', 'value2', tags: ['entity:Commitment']);

        $invalidator->invalidateTags(['entity:McEvent']);

        $item1 = $cache->get('key1');
        $item2 = $cache->get('key2');

        self::assertFalse($item1->valid);
        self::assertTrue($item2->valid);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Provider/CacheServiceProviderTest.php`
Expected: FAIL — `CacheServiceProvider` class not found

- [ ] **Step 3: Write the provider**

Create `src/Provider/CacheServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Waaseyaa\Cache\Backend\DatabaseBackend;
use Waaseyaa\Cache\CacheFactory;
use Waaseyaa\Cache\CacheConfiguration;
use Waaseyaa\Cache\CacheTagsInvalidator;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class CacheServiceProvider extends ServiceProvider
{
    private ?CacheFactory $cacheFactory = null;
    private ?CacheTagsInvalidator $cacheTagsInvalidator = null;

    public function register(): void
    {
        // Cache is configured lazily via getCacheFactory().
        // EntityCacheInvalidator wiring requires EventDispatcher access,
        // which is handled in ClaudrielServiceProvider where the dispatcher
        // is available.
    }

    public function getCacheFactory(): CacheFactory
    {
        if ($this->cacheFactory === null) {
            $pdo = $this->createCachePdo();
            $config = new CacheConfiguration(DatabaseBackend::class);
            $config->setFactoryForBin('brief', fn () => new DatabaseBackend($pdo, 'cache_brief'));
            $config->setFactoryForBin('entities', fn () => new DatabaseBackend($pdo, 'cache_entities'));

            $this->cacheFactory = new CacheFactory($config);
        }

        return $this->cacheFactory;
    }

    public function getCacheTagsInvalidator(): CacheTagsInvalidator
    {
        if ($this->cacheTagsInvalidator === null) {
            $this->cacheTagsInvalidator = new CacheTagsInvalidator();

            $factory = $this->getCacheFactory();
            $this->cacheTagsInvalidator->registerBin('brief', $factory->get('brief'));
            $this->cacheTagsInvalidator->registerBin('entities', $factory->get('entities'));
        }

        return $this->cacheTagsInvalidator;
    }

    private function createCachePdo(): \PDO
    {
        $storageDir = dirname(__DIR__, 2).'/storage';
        if (! is_dir($storageDir)) {
            mkdir($storageDir, 0o755, true);
        }

        $pdo = new \PDO('sqlite:'.$storageDir.'/cache.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Provider/CacheServiceProviderTest.php`
Expected: 3 tests pass

Note: If tests fail because `CacheFactory` constructor doesn't accept `CacheConfiguration`, check the actual constructor signature and adjust. The factory may take a string class name or configuration object — adapt to what the package actually accepts.

- [ ] **Step 5: Register in composer.json providers**

Add `"Claudriel\\Provider\\CacheServiceProvider"` to `extra.waaseyaa.providers` in `composer.json`, before `ClaudrielServiceProvider`.

- [ ] **Step 6: Add storage/cache.sqlite to .gitignore**

Add `storage/cache.sqlite` to `.gitignore`.

- [ ] **Step 7: Commit**

```bash
git add src/Provider/CacheServiceProvider.php tests/Unit/Provider/CacheServiceProviderTest.php composer.json .gitignore
git commit -m "feat(#479): create CacheServiceProvider with SQLite backend and tag invalidation"
```

---

### Task 3: Wire EntityCacheInvalidator for automatic invalidation on entity save/delete

**Files:**
- Modify: `src/Provider/CacheServiceProvider.php`
- Test: `tests/Unit/Provider/CacheServiceProviderTest.php`

- [ ] **Step 1: Investigate event dispatcher availability**

Check how entity events are dispatched in Claudriel. Read:
- `waaseyaa/packages/entity-storage/src/SqlEntityStorage.php` — find where `POST_SAVE` / `POST_DELETE` events are dispatched
- `waaseyaa/packages/entity/src/Event/EntityEvents.php` — find event name constants
- `waaseyaa/packages/cache/src/Listener/EntityCacheInvalidator.php` — understand the listener interface

The `EntityCacheInvalidator` needs a `CacheTagsInvalidator` in its constructor and exposes `onPostSave(EntityEvent $event)` and `onPostDelete(EntityEvent $event)` methods.

- [ ] **Step 2: Write the failing test for entity invalidation**

Add to `tests/Unit/Provider/CacheServiceProviderTest.php`:

```php
public function test_entity_cache_invalidator_creates_correct_tags(): void
{
    $provider = new CacheServiceProvider();
    $invalidator = $provider->getEntityCacheInvalidator();

    self::assertInstanceOf(
        \Waaseyaa\Cache\Listener\EntityCacheInvalidator::class,
        $invalidator,
    );
}
```

- [ ] **Step 3: Add getEntityCacheInvalidator() to the provider**

Add to `CacheServiceProvider`:

```php
public function getEntityCacheInvalidator(): \Waaseyaa\Cache\Listener\EntityCacheInvalidator
{
    return new \Waaseyaa\Cache\Listener\EntityCacheInvalidator(
        $this->getCacheTagsInvalidator(),
    );
}
```

- [ ] **Step 4: Wire event listeners in ClaudrielServiceProvider**

In `ClaudrielServiceProvider`, where the event dispatcher is available, register the entity cache invalidator as a listener. Find the appropriate hook point (likely in `register()` or a boot method where the dispatcher exists).

```php
// In ClaudrielServiceProvider, after obtaining $dispatcher:
$cacheProvider = /* get CacheServiceProvider instance */;
$entityInvalidator = $cacheProvider->getEntityCacheInvalidator();
$dispatcher->addListener('waaseyaa.entity.post_save', [$entityInvalidator, 'onPostSave']);
$dispatcher->addListener('waaseyaa.entity.post_delete', [$entityInvalidator, 'onPostDelete']);
```

If the dispatcher is not accessible from the provider, document this as a framework gap (similar to telescope's event recording gap) and wire it where the dispatcher IS available.

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Provider/CacheServiceProviderTest.php`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add src/Provider/CacheServiceProvider.php src/Provider/ClaudrielServiceProvider.php tests/Unit/Provider/CacheServiceProviderTest.php
git commit -m "feat(#479): wire EntityCacheInvalidator for auto-invalidation on entity save/delete"
```

---

### Task 4: Create CachedDayBriefAssembler decorator

**Files:**
- Create: `src/DayBrief/CachedDayBriefAssembler.php`
- Test: `tests/Unit/DayBrief/CachedDayBriefAssemblerTest.php`

- [ ] **Step 1: Read DayBriefAssembler to understand the interface**

Read `src/DayBrief/DayBriefAssembler.php` to understand:
- The `assemble()` method signature
- What parameters affect the cache key (tenantId, workspaceUuid, since date)
- What entity types are queried (McEvent, Commitment, ScheduleEntry, Person, TriageEntry, Skill)

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/DayBrief/CachedDayBriefAssemblerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\DayBrief;

use Claudriel\DayBrief\CachedDayBriefAssembler;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Cache\Backend\MemoryBackend;

final class CachedDayBriefAssemblerTest extends TestCase
{
    public function test_returns_cached_result_on_second_call(): void
    {
        $callCount = 0;
        $inner = function () use (&$callCount): array {
            $callCount++;
            return ['events' => [], 'commitments' => [], 'counts' => ['events' => 0]];
        };

        $cache = new MemoryBackend();
        $assembler = new CachedDayBriefAssembler($inner, $cache);

        $result1 = $assembler->assemble('tenant-1', new \DateTimeImmutable('2026-03-22'));
        $result2 = $assembler->assemble('tenant-1', new \DateTimeImmutable('2026-03-22'));

        self::assertSame(1, $callCount, 'Inner assembler should only be called once');
        self::assertSame($result1, $result2);
    }

    public function test_different_keys_call_inner_separately(): void
    {
        $callCount = 0;
        $inner = function () use (&$callCount): array {
            $callCount++;
            return ['events' => []];
        };

        $cache = new MemoryBackend();
        $assembler = new CachedDayBriefAssembler($inner, $cache);

        $assembler->assemble('tenant-1', new \DateTimeImmutable('2026-03-22'));
        $assembler->assemble('tenant-2', new \DateTimeImmutable('2026-03-22'));

        self::assertSame(2, $callCount);
    }

    public function test_invalidated_cache_calls_inner_again(): void
    {
        $callCount = 0;
        $inner = function () use (&$callCount): array {
            $callCount++;
            return ['events' => []];
        };

        $cache = new MemoryBackend();
        $assembler = new CachedDayBriefAssembler($inner, $cache);

        $assembler->assemble('tenant-1', new \DateTimeImmutable('2026-03-22'));

        // Simulate entity invalidation
        $cache->invalidateByTags(['entity:McEvent']);

        $assembler->assemble('tenant-1', new \DateTimeImmutable('2026-03-22'));

        self::assertSame(2, $callCount, 'Should re-call inner after invalidation');
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/DayBrief/CachedDayBriefAssemblerTest.php`
Expected: FAIL — class not found

- [ ] **Step 4: Write the decorator**

Create `src/DayBrief/CachedDayBriefAssembler.php`:

```php
<?php

declare(strict_types=1);

namespace Claudriel\DayBrief;

use Waaseyaa\Cache\TagAwareCacheInterface;

final class CachedDayBriefAssembler
{
    private const int TTL = 3600; // 1 hour

    private const array TAGS = [
        'entity:McEvent',
        'entity:Commitment',
        'entity:ScheduleEntry',
        'entity:Person',
        'entity:TriageEntry',
        'entity:Skill',
    ];

    /**
     * @param callable $inner The real DayBriefAssembler::assemble callable
     */
    public function __construct(
        private readonly mixed $inner,
        private readonly TagAwareCacheInterface $cache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function assemble(
        string $tenantId,
        \DateTimeImmutable $since,
        ?string $workspaceUuid = null,
    ): array {
        $cacheKey = $this->buildCacheKey($tenantId, $since, $workspaceUuid);

        $item = $this->cache->get($cacheKey);
        if ($item->valid) {
            return $item->data;
        }

        $result = ($this->inner)($tenantId, $since, $workspaceUuid);

        $this->cache->set($cacheKey, $result, self::TTL, self::TAGS);

        return $result;
    }

    private function buildCacheKey(
        string $tenantId,
        \DateTimeImmutable $since,
        ?string $workspaceUuid,
    ): string {
        return sprintf(
            'brief:%s:%s:%s',
            $tenantId,
            $workspaceUuid ?? 'all',
            $since->format('Y-m-d'),
        );
    }
}
```

Note: Check the actual `CacheBackendInterface::set()` signature. The parameters may be `set(string $cid, mixed $data, int $expire = -1, array $tags = [])` or use named parameters. Adapt the `set()` call to match the actual API. Also check if `get()` on a missing key returns a `CacheItem` with `valid: false` or throws — adapt the cache-hit check accordingly.

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/DayBrief/CachedDayBriefAssemblerTest.php`
Expected: 3 tests pass

- [ ] **Step 6: Commit**

```bash
git add src/DayBrief/CachedDayBriefAssembler.php tests/Unit/DayBrief/CachedDayBriefAssemblerTest.php
git commit -m "feat(#479): add CachedDayBriefAssembler decorator with tag-based invalidation"
```

---

### Task 5: Wire CachedDayBriefAssembler into the application

**Files:**
- Modify: `src/Provider/DayBriefServiceProvider.php` (or wherever DayBriefAssembler is instantiated)

- [ ] **Step 1: Find where DayBriefAssembler is instantiated**

Search for `new DayBriefAssembler` or where it's registered in the service container. Check `src/Provider/DayBriefServiceProvider.php` and `src/Provider/ClaudrielServiceProvider.php`.

- [ ] **Step 2: Wrap DayBriefAssembler with CachedDayBriefAssembler**

Where the assembler is created, wrap it:

```php
$assembler = new DayBriefAssembler(/* existing deps */);
$briefCache = $cacheProvider->getCacheFactory()->get('brief');
$cachedAssembler = new CachedDayBriefAssembler(
    fn (string $t, \DateTimeImmutable $s, ?string $w = null) => $assembler->assemble($t, $s, $w),
    $briefCache,
);
```

Inject `$cachedAssembler` where `$assembler` was previously used (controllers, commands).

- [ ] **Step 3: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All tests pass with no regressions

- [ ] **Step 4: Commit**

```bash
git add src/Provider/DayBriefServiceProvider.php
git commit -m "feat(#479): wire CachedDayBriefAssembler into brief endpoints"
```

---

### Task 6: Add telescope visibility for cache operations

**Files:**
- Modify: `src/Provider/CacheServiceProvider.php` (optional: wire CacheRecorder if telescope is available)

- [ ] **Step 1: Check if telescope CacheRecorder can observe cache operations**

The telescope `CacheRecorder` has `recordHit()`, `recordMiss()`, `recordSet()`, `recordForget()` methods. Check if the cache package dispatches events that telescope can listen to, or if we need to manually wire recording into `CachedDayBriefAssembler`.

If the cache package has no built-in event dispatching for cache operations, document this as a future enhancement and skip this task. Cache hit/miss visibility is "nice to have" per the spec, not blocking.

- [ ] **Step 2: If wiring is possible, add telescope recording**

Wire `CacheRecorder` calls in the `CachedDayBriefAssembler::assemble()` method around the cache get/set calls, or as a cache backend decorator.

- [ ] **Step 3: Commit if changes made**

```bash
git add -p
git commit -m "feat(#479): add telescope visibility for cache operations"
```

---

### Task 7: Final verification

- [ ] **Step 1: Run complete test suite**

Run: `vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 2: Run linter**

Run: `vendor/bin/pint --dirty`

- [ ] **Step 3: Manual smoke test**

1. Start dev server: `PHP_CLI_SERVER_WORKERS=4 php -S 0.0.0.0:8081 -t public`
2. Visit `/brief` twice
3. Check `storage/cache.sqlite` exists and has entries:
   `sqlite3 storage/cache.sqlite "SELECT cid, tags FROM cache_brief LIMIT 5;"`
4. Verify second `/brief` call is faster (cache hit)

- [ ] **Step 4: Commit if cleanup needed**

```bash
git add -A
git commit -m "chore(#479): cache integration cleanup and verification"
```

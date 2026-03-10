# Day Brief Redesign Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the prototype dump-everything brief with a chat-first interface featuring event deduplication, categorization, person tiering, rich cards, and a collapsible context panel.

**Architecture:** Backend changes add `content_hash`, `category`, and `tier` fields to entities, with an `EventCategorizer` and `ActionabilityStep` for classification. The `DayBriefAssembler` gets a new return shape consumed by three surfaces (JSON API, CLI, chat context). Frontend replaces the two-column layout with a collapsible context panel + chat with rich cards.

**Tech Stack:** PHP 8.3, Waaseyaa framework (ContentEntityBase, EntityRepository, PipelineStepInterface), Twig templates, vanilla JS, SSE streaming, Pest 4 for testing.

**Spec:** `docs/superpowers/specs/2026-03-10-day-brief-redesign-design.md`

**Prerequisites:** Before starting, create all 8 GitHub issues under milestone v0.2 (see spec's GitHub Issues section). Replace `#issue` placeholders in commit messages with the actual issue numbers.

**Test style:** This codebase uses PHPUnit class-based tests (`final class FooTest extends TestCase` with `public function test_something(): void`). All new tests must follow this style. Do NOT use Pest closure syntax (`test('...', function () { ... })`). Read existing test files before writing new tests to match patterns exactly.

---

## Chunk 1: Event Deduplication (GitHub Issue #1)

### Task 1: Add `content_hash` field to McEvent

**Files:**
- Modify: `src/Entity/McEvent.php:15-20` (entityKeys)
- Modify: `tests/Unit/Entity/McEventTest.php`

- [ ] **Step 1: Write test for content_hash in entityKeys**

In `tests/Unit/Entity/McEventTest.php`, add:

```php
public function test_entity_keys_include_content_hash(): void
{
    $event = new McEvent();
    $keys = $event->getEntityKeys();
    $this->assertArrayHasKey('content_hash', $keys);
    $this->assertSame('content_hash', $keys['content_hash']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Entity/McEventTest.php --filter=content_hash`
Expected: FAIL — `content_hash` key not found in entityKeys

- [ ] **Step 3: Add content_hash to McEvent entityKeys**

In `src/Entity/McEvent.php`, add `'content_hash' => 'content_hash'` to the `entityKeys` array (around line 17-20).

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Entity/McEventTest.php --filter=content_hash`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Entity/McEvent.php tests/Unit/Entity/McEventTest.php
git commit -m "feat(#issue): add content_hash to McEvent entityKeys"
```

### Task 2: Add `category` field to McEvent

**Files:**
- Modify: `tests/Unit/Entity/McEventTest.php`

- [ ] **Step 1: Write test for category field**

```php
public function test_category_defaults_to_notification(): void
{
    $event = new McEvent();
    $this->assertSame('notification', $event->get('category'));
}

public function test_category_can_be_set(): void
{
    $event = new McEvent();
    $event->set('category', 'job_hunt');
    $this->assertSame('job_hunt', $event->get('category'));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Entity/McEventTest.php --filter=category`
Expected: FAIL — category returns null, not 'notification'

- [ ] **Step 3: Set default category in McEvent constructor**

In `src/Entity/McEvent.php` constructor (lines 22-25), after calling parent, add:

```php
if ($this->get('category') === null) {
    $this->set('category', 'notification');
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Entity/McEventTest.php --filter=category`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Entity/McEvent.php tests/Unit/Entity/McEventTest.php
git commit -m "feat(#issue): add category field to McEvent with notification default"
```

### Task 3: Create ContentHasher utility

**Files:**
- Create: `src/Support/ContentHasher.php`
- Create: `tests/Unit/Support/ContentHasherTest.php`

- [ ] **Step 1: Write tests for ContentHasher**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Claudriel\Support\ContentHasher;
use PHPUnit\Framework\TestCase;

final class ContentHasherTest extends TestCase
{
    public function test_hashes_calendar_event_by_title_start_time_calendar_id(): void
    {
        $payload = [
            'source' => 'google-calendar',
            'type' => 'calendar.event',
            'title' => 'Job Applications',
            'start_time' => '2026-03-10T08:00:00',
            'calendar_id' => 'primary',
        ];
        $hash = ContentHasher::hash($payload);
        $this->assertSame(64, strlen($hash)); // sha256

        // Same content = same hash
        $hash2 = ContentHasher::hash($payload);
        $this->assertSame($hash, $hash2);
    }

    public function test_hashes_gmail_message_by_message_id(): void
    {
        $payload = [
            'source' => 'gmail',
            'type' => 'message.received',
            'message_id' => '18e1a2b3c4d5e6f7',
        ];
        $hash = ContentHasher::hash($payload);
        $this->assertSame(64, strlen($hash));
    }

    public function test_different_content_produces_different_hashes(): void
    {
        $payload1 = ['source' => 'gmail', 'type' => 'message.received', 'message_id' => 'abc'];
        $payload2 = ['source' => 'gmail', 'type' => 'message.received', 'message_id' => 'def'];
        $this->assertNotSame(ContentHasher::hash($payload1), ContentHasher::hash($payload2));
    }

    public function test_falls_back_to_source_type_json_hash_for_unknown_sources(): void
    {
        $payload = ['source' => 'smoke-test', 'type' => 'test.ping', 'data' => 'hello'];
        $hash = ContentHasher::hash($payload);
        $this->assertSame(64, strlen($hash));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/Support/ContentHasherTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implement ContentHasher**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Support;

final class ContentHasher
{
    public static function hash(array $payload): string
    {
        $source = $payload['source'] ?? '';
        $type = $payload['type'] ?? '';

        $key = match ($source) {
            'google-calendar' => implode('|', [
                $source,
                $type,
                $payload['title'] ?? '',
                $payload['start_time'] ?? '',
                $payload['calendar_id'] ?? '',
            ]),
            'gmail' => implode('|', [
                $source,
                $type,
                $payload['message_id'] ?? '',
            ]),
            default => implode('|', [
                $source,
                $type,
                json_encode($payload, JSON_SORT_KEYS),
            ]),
        };

        return hash('sha256', $key);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Support/ContentHasherTest.php`
Expected: PASS (all 4 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Support/ContentHasher.php tests/Unit/Support/ContentHasherTest.php
git commit -m "feat(#issue): add ContentHasher for event deduplication"
```

### Task 4: Integrate deduplication into EventHandler

**Files:**
- Modify: `src/Ingestion/EventHandler.php:19-31` (handle method)
- Modify: `tests/Unit/Ingestion/EventHandlerTest.php`

- [ ] **Step 1: Write test for duplicate rejection**

In `tests/Unit/Ingestion/EventHandlerTest.php`, add:

```php
public function test_skips_duplicate_events_with_same_content_hash(): void
{
    $driver = new InMemoryStorageDriver();
    $dispatcher = new EventDispatcher();
    $eventRepo = new EntityRepository(
        new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid', 'content_hash' => 'content_hash']),
        $driver,
        $dispatcher,
    );
    $personRepo = new EntityRepository(
        new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
        new InMemoryStorageDriver(),
        $dispatcher,
    );

    $handler = new EventHandler($eventRepo, $personRepo);
    $envelope = new Envelope(
        source: 'gmail',
        type: 'message.received',
        payload: ['message_id' => 'msg-dup', 'thread_id' => 't1', 'from_email' => 'jane@example.com', 'from_name' => 'Jane', 'subject' => 'Hello', 'body' => 'Test', 'date' => '2026-03-08T09:00:00+00:00'],
        timestamp: '2026-03-08T09:00:00+00:00',
        traceId: 'trace-dup-1',
        tenantId: 'user-1',
    );

    // First handle: event is saved
    $first = $handler->handle($envelope);

    // Second handle with same payload (different traceId simulates second fetch cycle)
    $envelope2 = new Envelope(
        source: 'gmail',
        type: 'message.received',
        payload: ['message_id' => 'msg-dup', 'thread_id' => 't1', 'from_email' => 'jane@example.com', 'from_name' => 'Jane', 'subject' => 'Hello', 'body' => 'Test', 'date' => '2026-03-08T09:00:00+00:00'],
        timestamp: '2026-03-08T09:00:00+00:00',
        traceId: 'trace-dup-2',
        tenantId: 'user-1',
    );
    $second = $handler->handle($envelope2);

    // Only one event should exist
    $events = $eventRepo->findBy([]);
    self::assertCount(1, $events);
    self::assertSame($first->get('content_hash'), $second->get('content_hash'));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Ingestion/EventHandlerTest.php --filter=duplicate`
Expected: FAIL

- [ ] **Step 3: Modify EventHandler::handle() to check for duplicates**

In `src/Ingestion/EventHandler.php`, in the `handle()` method:
1. After creating the McEvent from Envelope, compute `ContentHasher::hash()` from the event payload
2. Set `content_hash` on the McEvent
3. Query the repo for an existing event with the same `content_hash`
4. If found, return the existing event (skip save)
5. If not found, proceed with save and upsertPerson

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Ingestion/EventHandlerTest.php --filter=duplicate`
Expected: PASS

- [ ] **Step 5: Run full test suite**

Run: `vendor/bin/pest`
Expected: All tests pass (no regressions)

- [ ] **Step 6: Commit**

```bash
git add src/Ingestion/EventHandler.php tests/Unit/Ingestion/EventHandlerTest.php
git commit -m "feat(#issue): deduplicate events by content_hash in EventHandler"
```

---

## Chunk 2: Person Tiering (GitHub Issue #2)

### Task 5: Add `tier` field to Person entity

**Files:**
- Modify: `src/Entity/Person.php:19-22` (constructor)
- Modify: `tests/Unit/Entity/PersonTest.php`

- [ ] **Step 1: Write tests for tier field**

Match the existing PHPUnit class style in `PersonTest.php`:

```php
public function test_person_defaults_to_contact_tier(): void
{
    $person = new Person();
    $this->assertSame('contact', $person->get('tier'));
}

public function test_person_tier_can_be_set(): void
{
    $person = new Person();
    $person->set('tier', 'automated');
    $this->assertSame('automated', $person->get('tier'));
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/Entity/PersonTest.php --filter=tier`
Expected: FAIL — tier returns null

- [ ] **Step 3: Set default tier in Person constructor**

In `src/Entity/Person.php` constructor, after calling parent:

```php
if ($this->get('tier') === null) {
    $this->set('tier', 'contact');
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Entity/PersonTest.php --filter=tier`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Entity/Person.php tests/Unit/Entity/PersonTest.php
git commit -m "feat(#issue): add tier field to Person with contact default"
```

### Task 6: Create PersonTierClassifier

**Files:**
- Create: `src/Support/PersonTierClassifier.php`
- Create: `tests/Unit/Support/PersonTierClassifierTest.php`

- [ ] **Step 1: Write tests for tier classification**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Claudriel\Support\PersonTierClassifier;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PersonTierClassifierTest extends TestCase
{
    #[DataProvider('automatedEmailsProvider')]
    public function test_classifies_noreply_emails_as_automated(string $email): void
    {
        $this->assertSame('automated', PersonTierClassifier::classify($email));
    }

    public static function automatedEmailsProvider(): array
    {
        return [
            ['noreply@github.com'],
            ['no-reply@twinehq.com'],
            ['alert@indeed.com'],
            ['messages-noreply@linkedin.com'],
            ['jobalerts-noreply@linkedin.com'],
            ['messaging-digest-noreply@linkedin.com'],
            ['noreply@glassdoor.com'],
        ];
    }

    public function test_classifies_patreon_senders_as_creator(): void
    {
        $this->assertSame('creator', PersonTierClassifier::classify('bingo@patreon.com'));
    }

    #[DataProvider('contactEmailsProvider')]
    public function test_classifies_regular_emails_as_contact(string $email): void
    {
        $this->assertSame('contact', PersonTierClassifier::classify($email));
    }

    public static function contactEmailsProvider(): array
    {
        return [
            ['chris@example.com'],
            ['russell@gmail.com'],
            ['friend@hotmail.com'],
        ];
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/Support/PersonTierClassifierTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implement PersonTierClassifier**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Support;

final class PersonTierClassifier
{
    private const AUTOMATED_PATTERNS = [
        '/noreply@/i',
        '/no-reply@/i',
        '/^alert@/i',
        '/digest-noreply@/i',
        '/jobalerts-noreply@/i',
        '/messages-noreply@/i',
        '/messaging-digest-noreply@/i',
    ];

    private const CREATOR_DOMAINS = [
        'patreon.com',
    ];

    public static function classify(string $email): string
    {
        foreach (self::AUTOMATED_PATTERNS as $pattern) {
            if (preg_match($pattern, $email)) {
                return 'automated';
            }
        }

        $domain = strtolower(substr($email, strrpos($email, '@') + 1));
        if (in_array($domain, self::CREATOR_DOMAINS, true)) {
            return 'creator';
        }

        return 'contact';
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Support/PersonTierClassifierTest.php`
Expected: PASS (all tests)

- [ ] **Step 5: Commit**

```bash
git add src/Support/PersonTierClassifier.php tests/Unit/Support/PersonTierClassifierTest.php
git commit -m "feat(#issue): add PersonTierClassifier for contact/creator/automated detection"
```

### Task 7: Integrate tier classification into EventHandler

**Files:**
- Modify: `src/Ingestion/EventHandler.php:34-43` (upsertPerson method)
- Modify: `tests/Unit/Ingestion/EventHandlerTest.php`

- [ ] **Step 1: Write test for tier assignment on person creation**

Add these tests to `EventHandlerTest.php`, matching the existing setup pattern:

```php
public function test_upserts_person_with_automated_tier_for_noreply_email(): void
{
    $driver = new InMemoryStorageDriver();
    $dispatcher = new EventDispatcher();
    $eventRepo = new EntityRepository(
        new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid', 'content_hash' => 'content_hash']),
        $driver,
        $dispatcher,
    );
    $personRepo = new EntityRepository(
        new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
        new InMemoryStorageDriver(),
        $dispatcher,
    );

    $handler = new EventHandler($eventRepo, $personRepo);
    $envelope = new Envelope(
        source: 'gmail',
        type: 'message.received',
        payload: ['message_id' => 'msg-noreply', 'thread_id' => 't1', 'from_email' => 'noreply@github.com', 'from_name' => 'GitHub', 'subject' => 'Token expired', 'body' => 'Your PAT expired', 'date' => '2026-03-08T09:00:00+00:00'],
        timestamp: '2026-03-08T09:00:00+00:00',
        traceId: 'trace-tier-1',
        tenantId: 'user-1',
    );

    $handler->handle($envelope);

    $persons = $personRepo->findBy([]);
    self::assertCount(1, $persons);
    self::assertSame('automated', $persons[0]->get('tier'));
}

public function test_upserts_person_with_contact_tier_for_regular_email(): void
{
    $driver = new InMemoryStorageDriver();
    $dispatcher = new EventDispatcher();
    $eventRepo = new EntityRepository(
        new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid', 'content_hash' => 'content_hash']),
        $driver,
        $dispatcher,
    );
    $personRepo = new EntityRepository(
        new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
        new InMemoryStorageDriver(),
        $dispatcher,
    );

    $handler = new EventHandler($eventRepo, $personRepo);
    $envelope = new Envelope(
        source: 'gmail',
        type: 'message.received',
        payload: ['message_id' => 'msg-chris', 'thread_id' => 't2', 'from_email' => 'chris@example.com', 'from_name' => 'Chris Schultz', 'subject' => 'Hey', 'body' => 'What is up', 'date' => '2026-03-08T09:00:00+00:00'],
        timestamp: '2026-03-08T09:00:00+00:00',
        traceId: 'trace-tier-2',
        tenantId: 'user-1',
    );

    $handler->handle($envelope);

    $persons = $personRepo->findBy([]);
    self::assertCount(1, $persons);
    self::assertSame('contact', $persons[0]->get('tier'));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Ingestion/EventHandlerTest.php --filter=tier`
Expected: FAIL

- [ ] **Step 3: Integrate PersonTierClassifier into upsertPerson()**

In `EventHandler::upsertPerson()`, after creating the Person entity, call:

```php
$person->set('tier', PersonTierClassifier::classify($email));
```

Note: `upsertPerson` currently returns early if the person already exists (`count > 0`). This means existing persons won't get retroactive tier classification. This is intentional for now — migration is handled separately.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Ingestion/EventHandlerTest.php --filter=tier`
Expected: PASS

- [ ] **Step 5: Run full test suite**

Run: `vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add src/Ingestion/EventHandler.php tests/Unit/Ingestion/EventHandlerTest.php
git commit -m "feat(#issue): classify person tier on ingestion"
```

### Task 7b: Migration script for existing Person records

**Files:**
- Create: `src/Command/MigratePersonTiersCommand.php`
- Create: `tests/Unit/Command/MigratePersonTiersCommandTest.php`

- [ ] **Step 1: Write the migration command**

Create a CLI command `claudriel:migrate:person-tiers` that:
1. Loads all existing Person entities
2. For each person, computes tier via `PersonTierClassifier::classify($email)`
3. Sets the tier and saves
4. Outputs count of updated records per tier

- [ ] **Step 2: Write test for migration command**

Test that the command updates existing persons with correct tiers.

- [ ] **Step 3: Run test, verify pass**

Run: `vendor/bin/pest tests/Unit/Command/MigratePersonTiersCommandTest.php`

- [ ] **Step 4: Commit**

```bash
git add src/Command/MigratePersonTiersCommand.php tests/Unit/Command/MigratePersonTiersCommandTest.php
git commit -m "feat(#issue): add migration command for person tier backfill"
```

---

## Chunk 3: Event Categorization (GitHub Issue #3)

### Task 8: Create EventCategorizer

**Files:**
- Create: `src/Ingestion/EventCategorizer.php`
- Create: `tests/Unit/Ingestion/EventCategorizerTest.php`

- [ ] **Step 1: Write tests for event categorization**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Ingestion;

use Claudriel\Ingestion\EventCategorizer;
use PHPUnit\Framework\TestCase;

final class EventCategorizerTest extends TestCase
{
    public function test_categorizes_google_calendar_events_as_schedule(): void
    {
        $this->assertSame('schedule', EventCategorizer::categorize('google-calendar', 'calendar.event', []));
    }

    public function test_categorizes_indeed_job_alerts_as_job_hunt(): void
    {
        $this->assertSame('job_hunt', EventCategorizer::categorize('gmail', 'message.received', [
            'sender_email' => 'alert@indeed.com',
        ]));
    }

    public function test_categorizes_linkedin_job_alerts_as_job_hunt(): void
    {
        $this->assertSame('job_hunt', EventCategorizer::categorize('gmail', 'message.received', [
            'sender_email' => 'jobalerts-noreply@linkedin.com',
        ]));
    }

    public function test_categorizes_glassdoor_alerts_as_job_hunt(): void
    {
        $this->assertSame('job_hunt', EventCategorizer::categorize('gmail', 'message.received', [
            'sender_email' => 'noreply@glassdoor.com',
        ]));
    }

    public function test_categorizes_twine_alerts_as_job_hunt(): void
    {
        $this->assertSame('job_hunt', EventCategorizer::categorize('gmail', 'message.received', [
            'sender_email' => 'no-reply@twinehq.com',
        ]));
    }

    public function test_categorizes_contact_tier_sender_as_people(): void
    {
        $this->assertSame('people', EventCategorizer::categorize('gmail', 'message.received', [
            'sender_email' => 'chris@example.com',
            'sender_tier' => 'contact',
        ]));
    }

    public function test_categorizes_creator_tier_sender_as_creator(): void
    {
        $this->assertSame('creator', EventCategorizer::categorize('gmail', 'message.received', [
            'sender_email' => 'bingo@patreon.com',
            'sender_tier' => 'creator',
        ]));
    }

    public function test_categorizes_automated_gmail_as_notification(): void
    {
        $this->assertSame('notification', EventCategorizer::categorize('gmail', 'message.received', [
            'sender_email' => 'noreply@github.com',
            'sender_tier' => 'automated',
        ]));
    }

    public function test_categorizes_smoke_test_as_notification(): void
    {
        $this->assertSame('notification', EventCategorizer::categorize('smoke-test', 'test.ping', []));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/Ingestion/EventCategorizerTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implement EventCategorizer**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Ingestion;

final class EventCategorizer
{
    private const JOB_HUNT_DOMAINS = [
        'indeed.com',
        'glassdoor.com',
        'twinehq.com',
    ];

    private const JOB_HUNT_PATTERNS = [
        '/jobalerts-noreply@linkedin\.com/i',
    ];

    public static function categorize(string $source, string $type, array $context = []): string
    {
        if ($source === 'google-calendar') {
            return 'schedule';
        }

        if ($source === 'gmail' && $type === 'message.received') {
            $senderEmail = $context['sender_email'] ?? '';

            // Check job hunt by domain
            $domain = strtolower(substr($senderEmail, strrpos($senderEmail, '@') + 1));
            if (in_array($domain, self::JOB_HUNT_DOMAINS, true)) {
                return 'job_hunt';
            }

            // Check job hunt by pattern
            foreach (self::JOB_HUNT_PATTERNS as $pattern) {
                if (preg_match($pattern, $senderEmail)) {
                    return 'job_hunt';
                }
            }

            // Check person tier
            $tier = $context['sender_tier'] ?? 'contact';
            if ($tier === 'contact') {
                return 'people';
            }
            if ($tier === 'creator') {
                return 'creator';
            }
        }

        return 'notification';
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Ingestion/EventCategorizerTest.php`
Expected: PASS (all 9 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Ingestion/EventCategorizer.php tests/Unit/Ingestion/EventCategorizerTest.php
git commit -m "feat(#issue): add EventCategorizer for schedule/job_hunt/people/creator/notification"
```

### Task 9: Integrate categorization into EventHandler

**Files:**
- Modify: `src/Ingestion/EventHandler.php:19-31` (handle method)
- Modify: `tests/Unit/Ingestion/EventHandlerTest.php`

- [ ] **Step 1: Write test for category assignment on event creation**

Test that when EventHandler handles a Gmail message from Indeed, the resulting McEvent has `category=job_hunt`. And when from google-calendar, `category=schedule`.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Ingestion/EventHandlerTest.php --filter=category`
Expected: FAIL

- [ ] **Step 3: Integrate EventCategorizer into handle()**

In `EventHandler::handle()`, after creating the McEvent and before saving:
1. Get the Person (from upsertPerson or existing lookup) to determine sender_tier
2. Call `EventCategorizer::categorize($source, $type, ['sender_email' => $email, 'sender_tier' => $person->get('tier')])`
3. Set `category` on the McEvent

Note: this requires reordering — `upsertPerson` must happen before categorization so we have the tier. Move `upsertPerson` call before the event save.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Ingestion/EventHandlerTest.php --filter=category`
Expected: PASS

- [ ] **Step 5: Run full test suite**

Run: `vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add src/Ingestion/EventHandler.php tests/Unit/Ingestion/EventHandlerTest.php
git commit -m "feat(#issue): categorize events on ingestion via EventCategorizer"
```

---

## Chunk 4: Redesign DayBriefAssembler (GitHub Issue #4)

### Task 10: Rewrite DayBriefAssembler::assemble() return shape

**Files:**
- Modify: `src/Domain/DayBrief/Assembler/DayBriefAssembler.php:20-56`
- Modify: `tests/Unit/DayBrief/DayBriefAssemblerTest.php`
- Modify: `src/Provider/ClaudrielServiceProvider.php:263` (add `$personRepo` to assembler construction)

**Important:** The current `DayBriefAssembler` constructor takes 4 args: `$eventRepo, $commitmentRepo, $driftDetector, $skillRepo`. This task adds `$personRepo` as a 5th argument. You must update:
1. The constructor in `DayBriefAssembler.php`
2. The instantiation in `ClaudrielServiceProvider.php` (around line 263)
3. All test files that construct `DayBriefAssembler`

- [ ] **Step 1: Write test for new assemble() return shape**

Read the existing `tests/Unit/DayBrief/DayBriefAssemblerTest.php` first to understand the current test patterns and setup (PHPUnit class style, real or mocked repos).

Write a test matching the existing style:

```php
public function test_assemble_returns_categorized_brief_data(): void
{
    // Setup: create test events with different categories set on them
    // Create McEvents with category='schedule', 'job_hunt', 'people', 'notification'
    // Create Person entities for people/creator lookups
    // Create Commitment entities for pending/drifting
    //
    // Assert return has keys: schedule, job_hunt, people, creators, notifications, commitments, counts, generated_at
    // Assert schedule contains only category=schedule events
    // Assert job_hunt contains only category=job_hunt events
    // Assert people contains only category=people events with Person data attached
    // Assert commitments has 'pending' and 'drifting' sub-arrays
    // Assert counts has 'job_alerts', 'messages', 'due_today', 'drifting' as integers
    //
    // Match the existing test's repo setup exactly.
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/DayBrief/DayBriefAssemblerTest.php --filter=categorized`
Expected: FAIL — old return shape doesn't have new keys

- [ ] **Step 3: Rewrite assemble() method**

In `src/Domain/DayBrief/Assembler/DayBriefAssembler.php`, rewrite `assemble()` to:
1. Load all events since `$since` from eventRepo
2. Group events by `category` field
3. For `people` and `creators` categories, attach the Person entity
4. Load pending commitments (status=pending, confidence >= 0.7)
5. Load drifting commitments via DriftDetector
6. Compute counts
7. Return the new shape as defined in the spec

```php
public function assemble(string $tenantId, \DateTimeImmutable $since): array
{
    $events = $this->eventRepo->loadAll(); // filter by since
    $commitments = $this->commitmentRepo->loadAll();
    $people = $this->personRepo->loadAll();

    // Index people by email for lookups
    $peopleByEmail = [];
    foreach ($people as $person) {
        $email = $person->get('email');
        if ($email) {
            $peopleByEmail[$email] = $person;
        }
    }

    // Group events by category
    $schedule = [];
    $jobHunt = [];
    $peopleEvents = [];
    $creators = [];
    $notifications = [];

    foreach ($events as $event) {
        $category = $event->get('category') ?? 'notification';
        $occurred = $event->get('occurred_at');

        // Filter by since
        if ($occurred && new \DateTimeImmutable($occurred) < $since) {
            continue;
        }

        match ($category) {
            'schedule' => $schedule[] = [
                'title' => $event->get('title') ?? $event->get('subject') ?? '',
                'start_time' => $event->get('start_time') ?? $occurred,
                'end_time' => $event->get('end_time') ?? '',
                'calendar_id' => $event->get('calendar_id') ?? '',
            ],
            'job_hunt' => $jobHunt[] = [
                'title' => $event->get('subject') ?? $event->get('title') ?? '',
                'source_name' => $event->get('sender_name') ?? '',
                'details' => $event->get('snippet') ?? '',
            ],
            'people' => $peopleEvents[] = [
                'person' => $peopleByEmail[$event->get('sender_email')] ?? null,
                'event' => $event,
                'summary' => $event->get('subject') ?? '',
            ],
            'creator' => $creators[] = [
                'person' => $peopleByEmail[$event->get('sender_email')] ?? null,
                'event' => $event,
                'summary' => $event->get('subject') ?? '',
            ],
            default => $notifications[] = [
                'event' => $event,
                'actionable' => (bool) ($event->get('actionable') ?? false),
            ],
        };
    }

    // Commitments
    $pending = array_filter($commitments, fn ($c) =>
        $c->get('status') === 'pending' && ($c->get('confidence') ?? 0) >= 0.7
    );
    $drifting = $this->driftDetector->findDrifting($tenantId);

    // Due today
    $today = (new \DateTimeImmutable())->format('Y-m-d');
    $dueToday = count(array_filter($pending, fn ($c) =>
        ($c->get('due_date') ?? '') === $today
    ));

    return [
        'schedule' => $schedule,
        'job_hunt' => $jobHunt,
        'people' => $peopleEvents,
        'creators' => $creators,
        'notifications' => $notifications,
        'commitments' => [
            'pending' => array_values($pending),
            'drifting' => $drifting,
        ],
        'counts' => [
            'job_alerts' => count($jobHunt),
            'messages' => count($peopleEvents),
            'due_today' => $dueToday,
            'drifting' => count($drifting),
        ],
        'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
    ];
}
```

**Adapt this to match the actual repository API.** The existing code may use `findBy([])`, `loadAll()`, or another query method. Read the existing `assemble()` method and repository interfaces before implementing. Key changes:
1. Add `private EntityRepositoryInterface $personRepo` to the constructor
2. Use the actual query method (likely `findBy([])` not `loadAll()`)
3. Update `ClaudrielServiceProvider` to inject `$personRepo` when constructing the assembler

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/DayBrief/DayBriefAssemblerTest.php --filter=categorized`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Domain/DayBrief/Assembler/DayBriefAssembler.php tests/Unit/DayBrief/DayBriefAssemblerTest.php
git commit -m "feat(#issue): rewrite DayBriefAssembler for categorized output"
```

### Task 11: Update DayBriefController for new assemble() shape

**Files:**
- Modify: `src/Controller/DayBriefController.php:26-146`
- Modify: `tests/Unit/Controller/DayBriefControllerTest.php`

- [ ] **Step 1: Read current DayBriefController and its test**

Read both files. Note that the controller currently duplicates assembler logic (loads events/commitments manually). Refactor to use DayBriefAssembler directly.

- [ ] **Step 2: Write test for updated JSON response shape**

Test that GET /brief with Accept: application/json returns the new categorized shape with keys: schedule, job_hunt, people, creators, notifications, commitments, counts, generated_at.

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Controller/DayBriefControllerTest.php --filter=categorized`
Expected: FAIL

- [ ] **Step 4: Refactor DayBriefController to use DayBriefAssembler**

Replace the manual event/commitment loading with a single call to `$this->assembler->assemble()`. Return the result as JSON or pass to Twig.

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Controller/DayBriefControllerTest.php --filter=categorized`
Expected: PASS

- [ ] **Step 6: Run full test suite**

Run: `vendor/bin/pest`
Expected: All pass (some existing DayBriefController tests may need updating for new shape)

- [ ] **Step 7: Commit**

```bash
git add src/Controller/DayBriefController.php tests/Unit/Controller/DayBriefControllerTest.php
git commit -m "feat(#issue): update DayBriefController for categorized brief output"
```

### Task 12: Update ChatSystemPromptBuilder for new shape

**Files:**
- Modify: `src/Domain/Chat/ChatSystemPromptBuilder.php:160-189` (formatBriefContext)
- Modify: `tests/Unit/Domain/Chat/ChatSystemPromptBuilderTest.php`

- [ ] **Step 1: Read current ChatSystemPromptBuilder and its test**

Understand how `formatBriefContext()` currently renders the brief data into markdown for the AI.

- [ ] **Step 2: Write test for new format**

Test that `formatBriefContext()` with the new assemble() shape produces markdown with categorized sections (Schedule, Job Hunt, People, etc.).

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Domain/Chat/ChatSystemPromptBuilderTest.php --filter=brief`
Expected: FAIL

- [ ] **Step 4: Update formatBriefContext() for new shape**

Rewrite to iterate over the new categorized arrays and format each section with appropriate headers and content.

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Domain/Chat/ChatSystemPromptBuilderTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Chat/ChatSystemPromptBuilder.php tests/Unit/Domain/Chat/ChatSystemPromptBuilderTest.php
git commit -m "feat(#issue): update ChatSystemPromptBuilder for categorized brief context"
```

### Task 13: Update BriefCommand for new shape

**Files:**
- Modify: `src/Command/BriefCommand.php:24-66` (execute method)
- Modify: `tests/Unit/Command/BriefCommandTest.php`

- [ ] **Step 1: Read current BriefCommand and its test**

- [ ] **Step 2: Write test for new CLI output format**

Test that the command outputs categorized sections: Schedule, Job Hunt, People, Commitments, Notifications.

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Command/BriefCommandTest.php --filter=categorized`
Expected: FAIL

- [ ] **Step 4: Rewrite execute() for categorized output**

Render each category section with appropriate Symfony console formatting.

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Command/BriefCommandTest.php`
Expected: PASS

- [ ] **Step 6: Run full test suite**

Run: `vendor/bin/pest`
Expected: All pass

- [ ] **Step 7: Commit**

```bash
git add src/Command/BriefCommand.php tests/Unit/Command/BriefCommandTest.php
git commit -m "feat(#issue): update BriefCommand for categorized brief output"
```

---

## Chunk 5: ActionabilityStep (part of GitHub Issue #3)

### Task 9b: Create ActionabilityStep pipeline step

**Files:**
- Create: `src/Pipeline/ActionabilityStep.php`
- Create: `tests/Unit/Pipeline/ActionabilityStepTest.php`

This step classifies notification-category events as actionable or not. It implements `PipelineStepInterface` (Layer 2, `src/Pipeline/`).

- [ ] **Step 1: Read existing pipeline step**

Read `src/Pipeline/CommitmentExtractionStep.php` and its test to understand the `PipelineStepInterface` contract and `StepResult` patterns.

- [ ] **Step 2: Write test for ActionabilityStep**

```php
public function test_marks_expired_token_notification_as_actionable(): void
{
    // Create McEvent with category='notification', subject containing 'expired'
    // Run ActionabilityStep
    // Assert StepResult::success with actionable=true
}

public function test_marks_informational_notification_as_not_actionable(): void
{
    // Create McEvent with category='notification', subject='Copilot CLI is now GA'
    // Run ActionabilityStep
    // Assert StepResult::success with actionable=false
}
```

Match the existing `CommitmentExtractionStepTest` setup style.

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/Pipeline/ActionabilityStepTest.php`
Expected: FAIL — class not found

- [ ] **Step 4: Implement ActionabilityStep**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Pipeline;

use Waaseyaa\AiPipeline\PipelineStepInterface;
use Waaseyaa\AiPipeline\StepResult;

final class ActionabilityStep implements PipelineStepInterface
{
    private const ACTIONABLE_KEYWORDS = [
        'expired', 'expiring', 'action required', 'overdue',
        'failed', 'error', 'urgent', 'renew', 'verify',
        'confirm', 'approve', 'review required',
    ];

    public function execute(array $context): StepResult
    {
        $subject = strtolower($context['subject'] ?? '');
        $body = strtolower($context['body'] ?? '');
        $text = $subject . ' ' . $body;

        foreach (self::ACTIONABLE_KEYWORDS as $keyword) {
            if (str_contains($text, $keyword)) {
                return StepResult::success(['actionable' => true]);
            }
        }

        return StepResult::success(['actionable' => false]);
    }
}
```

**Note:** This is a keyword-based heuristic for v0.2. The spec mentions AI classification as a future enhancement. For now, keywords are sufficient and testable.

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Pipeline/ActionabilityStepTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Pipeline/ActionabilityStep.php tests/Unit/Pipeline/ActionabilityStepTest.php
git commit -m "feat(#issue): add ActionabilityStep for notification classification"
```

---

## Chunk 6: Chat-First Layout & Context Panel (GitHub Issues #5, #6, #7, #8)

### Task 14: Create new dashboard template with collapsible panel + chat

**Files:**
- Modify: `templates/dashboard.twig` (full rewrite)
- Modify: `templates/base.html.twig` (if nav changes needed)

- [ ] **Step 1: Read current dashboard.twig and base.html.twig fully**

Understand the current layout, CSS, and JS. Note what to preserve (chat JS, SSE streaming) and what to replace (two-column brief+chat layout).

- [ ] **Step 2: Design the new layout structure**

The new layout has:
- Collapsible context panel (left, ~300px)
- Chat area (right, primary, full-width when panel collapsed)
- No Brief/Chat tab navigation
- Minimal nav: Claudriel wordmark + panel toggle

- [ ] **Step 3: Rewrite dashboard.twig with new layout**

Structure:
```html
<!-- Context Panel -->
<aside id="context-panel" class="context-panel">
  <div class="panel-section" id="schedule-timeline">...</div>
  <div class="panel-section" id="action-items">...</div>
  <div class="panel-section" id="at-a-glance">...</div>
  <button id="panel-toggle">...</button>
</aside>

<!-- Chat Area -->
<main id="chat-area" class="chat-area">
  <div id="chat-messages">...</div>
  <div id="chat-input">...</div>
</main>
```

CSS:
- Panel open: `grid-template-columns: 300px 1fr`
- Panel collapsed: `grid-template-columns: 48px 1fr`
- Panel toggle persists in localStorage
- Responsive: panel closed by default under 1024px

- [ ] **Step 4: Implement panel population from /brief JSON**

On page load, fetch `/brief` JSON and populate:
- Schedule timeline section
- Action items section
- At-a-glance counters

- [ ] **Step 5: Implement panel collapse/expand**

JS: toggle `.collapsed` class, update localStorage, adjust grid columns.

- [ ] **Step 6: Implement panel click-to-chat**

Clicking a panel section pre-fills the chat input. E.g., clicking "4 Job Alerts" types "Show me today's job listings" in the input.

- [ ] **Step 7: Verify existing chat functionality preserved**

Test that:
- Chat message sending works (POST /api/chat/send)
- SSE streaming works (/stream/chat/{messageId})
- Session management works

- [ ] **Step 8: Commit**

```bash
git add templates/dashboard.twig templates/base.html.twig
git commit -m "feat(#issue): chat-first layout with collapsible context panel"
```

### Task 15: Rich card rendering in chat

**Files:**
- Modify: `templates/dashboard.twig` (card CSS + rendering JS)

- [ ] **Step 1: Define card HTML structure**

Each card type needs a CSS class and HTML template:

```html
<div class="chat-card chat-card--schedule">
  <div class="card-header">📅 Today's Schedule</div>
  <div class="card-body">...</div>
</div>
```

Card types: `schedule`, `job-hunt`, `people`, `commitment`, `creator`, `notification-summary`

- [ ] **Step 2: Implement card detection in chat message rendering**

**Approach: Markdown section parsing.** The AI returns markdown with emoji-prefixed section headers (e.g., `📅 Today's Schedule`, `💼 Job Hunt`). The frontend JS parses these sections and wraps them in card HTML. This leverages the existing markdown rendering (check `dashboard.twig` for how chat messages are currently rendered — likely innerHTML or a markdown library).

Steps:
1. Read how chat messages are currently rendered in `dashboard.twig`
2. Add a `renderCards(markdownHtml)` function that scans for known section patterns
3. Wrap matched sections in `<div class="chat-card chat-card--{type}">` containers
4. Call this function after rendering each Claudriel message

- [ ] **Step 3: Style cards**

CSS for each card type with distinct colors matching the mockup:
- Schedule: blue (`#3b82f6`)
- Job Hunt: green (`#10b981`)
- People: amber (`#f59e0b`)
- Commitment: red/amber (based on urgency)
- Creator: purple (`#8b5cf6`)
- Notification: gray

- [ ] **Step 4: Test card rendering**

Open the app, trigger a morning brief, verify cards render correctly in the chat.

- [ ] **Step 5: Commit**

```bash
git add templates/dashboard.twig
git commit -m "feat(#issue): rich card rendering for chat messages"
```

### Task 16: Auto-trigger morning brief

**Files:**
- Modify: `templates/dashboard.twig` (JS)
- Possibly modify: `src/Controller/ChatController.php` or `src/Domain/Chat/ChatSystemPromptBuilder.php`

- [ ] **Step 1: Read BriefSessionStore**

Check `src/Domain/DayBrief/Service/BriefSessionStore.php` — this already tracks last brief timestamp. Use it to determine if a brief has been sent today.

- [ ] **Step 2: Implement auto-brief on page load**

**Approach: localStorage flag.** Simpler than an API check, and the brief is per-browser-session anyway.

On page load JS:
1. Check `localStorage.getItem('claudriel_brief_date')` against today's date string
2. If not today, automatically send a chat message like "Good morning, give me my daily brief"
3. On successful brief response, set `localStorage.setItem('claudriel_brief_date', todayStr)`
4. This triggers the existing chat flow, which now has categorized brief data in its context

- [ ] **Step 3: Test the auto-brief flow**

Open the app fresh. Verify Claudriel automatically sends the morning brief with rich cards. Refresh — verify it doesn't re-send.

- [ ] **Step 4: Commit**

```bash
git add templates/dashboard.twig
git commit -m "feat(#issue): auto-trigger morning brief on first load of day"
```

### Task 17: Final integration testing

- [ ] **Step 1: Run full test suite**

Run: `vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 2: Manual smoke test**

Open http://localhost:9889/ and verify:
1. Context panel shows schedule, action items, counters
2. Chat auto-sends morning brief with rich cards
3. Cards are styled and readable
4. Panel collapses/expands, persists across refresh
5. Clicking panel sections pre-fills chat
6. Chat conversation works normally after brief
7. No duplicate events or bot people

- [ ] **Step 3: Commit any final fixes**

Stage only the specific files you changed (list them explicitly):

```bash
git add templates/dashboard.twig src/Controller/DayBriefController.php  # etc.
git commit -m "fix(#issue): integration fixes for day brief redesign"
```

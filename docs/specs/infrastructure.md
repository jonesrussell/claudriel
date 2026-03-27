# Infrastructure Specification

Covers the service provider (central wiring) and support utilities.

## File Map

| File | Purpose |
|------|---------|
| `src/Provider/ClaudrielServiceProvider.php` | Registers all entity types, routes, and CLI commands |
| `src/Provider/CodeTaskServiceProvider.php` | Registers CodeTask entity type and CodeTaskRunner singleton |
| `src/Support/BriefSignal.php` | File-based timestamp tracking for brief generation signals |
| `src/Support/DriftDetector.php` | Finds active commitments unchanged for 48+ hours |
| `src/Support/StorageRepositoryAdapter.php` | Adapts SqlEntityStorage to EntityRepositoryInterface |

## ClaudrielServiceProvider

Central wiring point for Claudriel. Extends Waaseyaa `ServiceProvider`.

### Entity Registration

`register()` registers 8 entity types via `$this->entityType(new EntityType(...))`:

| Entity Type ID | Key Mapping | Purpose |
|---------------|-------------|---------|
| `account` | id→aid, uuid, label→name | User accounts |
| `mc_event` | id→eid, uuid | Ingested events |
| `person` | id→pid, uuid, label→name | People extracted from events |
| `integration` | id→iid, uuid, label→name | External service integrations |
| `commitment` | id→cid, uuid, label→title | Tracked commitments |
| `skill` | id→sid, uuid, label→name | Matched skills |
| `chat_session` | id→csid, uuid, label→title | Chat conversation threads |
| `chat_message` | id→cmid, uuid | Individual chat messages |

### Route Registration

`routes(WaaseyaaRouter $router)` registers 9 routes:

| Method | Path | Handler | Notes |
|--------|------|---------|-------|
| `GET` | `/` | `DashboardController::show` | Dashboard unification |
| `GET` | `/brief` | `DayBriefController::show` | Legacy JSON API |
| `GET` | `/chat` | `DashboardController::show` | Redirects to dashboard |
| `GET` | `/stream/brief` | `BriefStreamController::stream` | SSE brief stream |
| `GET` | `/stream/chat/{messageId}` | `ChatStreamController::stream` | SSE chat stream |
| `PATCH` | `/commitments/{uuid}` | `CommitmentUpdateController::update` | Update commitment |
| `POST` | `/api/ingest` | `IngestController::handle` | Event ingestion |
| `GET` | `/api/context` | `ContextController::show` | Context data |
| `POST` | `/api/chat/send` | `ChatController::send` | Send chat message |

### CLI Command Bootstrap

`commands(EntityTypeManager, PdoDatabase, EventDispatcherInterface): array` creates:

1. `BriefCommand` (uses DayBriefAssembler + BriefSessionStore)
2. `CommitmentsCommand`
3. `CommitmentUpdateCommand`
4. `SkillsCommand`

Creates fresh `EntityRepository` instances with `SqlStorageDriver` + `SingleConnectionResolver`.

## BriefSignal

File-based timestamp utility. Tracks when the brief was last generated.

```php
final class BriefSignal
{
    public function __construct(string $filePath)
    public function touch(): void                       // writes current timestamp to file
    public function lastModified(): int                 // filemtime() with cache clearing
    public function hasChangedSince(int $sinceTimestamp): bool
}
```

No external dependencies (stdlib only). Used by `BriefSessionStore` in the DayBrief layer.

## DriftDetector

Identifies commitments that may need attention.

```php
final class DriftDetector
{
    const DRIFT_HOURS = 48;

    public function __construct(EntityRepositoryInterface $repo)  // commitment repo
    public function findDrifting(string $tenantId): ContentEntityInterface[]
}
```

Logic: loads all commitments, filters in-memory for `status === 'active'` AND `updated_at < (now - 48h)`. Pending commitments are NOT checked.

## CodeTaskServiceProvider

Dedicated provider for the CodeTask subsystem. Registers:

- `code_task` EntityType with full `fieldDefinitions` (enables GraphQL schema)
- `CodeTaskRunner` as a singleton (resolves its own `SqlEntityStorage` + `StorageRepositoryAdapter`)

```php
// CodeTaskRunner wiring
$storage = new SqlEntityStorage($entityType, $database, $dispatcher);
$repo = new StorageRepositoryAdapter($storage);
return new CodeTaskRunner($repo);
```

## Storage Strategy

Domain service providers (e.g., `CodeTaskServiceProvider`, `PipelineServiceProvider`) create entity repositories using `SqlEntityStorage` + `StorageRepositoryAdapter`. `ClaudrielServiceProvider::commands()` creates parallel repository instances for CLI commands using the same pattern. This dual-instance approach is intentional (#377).

## Adding New Entity Types

1. Create entity class in `src/Entity/` extending `ContentEntityBase`
2. Register EntityType with `fieldDefinitions` in the relevant service provider's `register()`
3. Wire persistence via `SqlEntityStorage` + `StorageRepositoryAdapter` (see `.claude/rules/waaseyaa-entity-wiring.md`)
4. If needed, register routes in `routes()` and/or commands in `commands()`

<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Service;

use Claudriel\Entity\Tenant;
use Claudriel\Entity\Workspace;
use Claudriel\Service\WorkspaceBootstrapService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class WorkspaceBootstrapServiceTest extends TestCase
{
    public function test_bootstrap_creates_default_workspace_once_per_tenant(): void
    {
        $entityTypeManager = $this->buildEntityTypeManager();
        $tenant = new Tenant([
            'name' => 'Tenant A',
            'metadata' => ['bootstrap_source' => 'public_signup'],
        ]);
        $entityTypeManager->getStorage('tenant')->save($tenant);

        $service = new WorkspaceBootstrapService($entityTypeManager);

        $first = $service->bootstrapDefaultWorkspace($tenant);
        $second = $service->bootstrapDefaultWorkspace($tenant);

        self::assertInstanceOf(Workspace::class, $first);
        self::assertSame($first->get('uuid'), $second->get('uuid'));
        self::assertCount(1, $entityTypeManager->getStorage('workspace')->getQuery()->execute());
        self::assertSame($first->get('uuid'), $tenant->get('metadata')['default_workspace_uuid']);
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $db = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher;
        $entityTypeManager = new EntityTypeManager($dispatcher, function ($definition) use ($db, $dispatcher): SqlEntityStorage {
            (new SqlSchemaHandler($definition, $db))->ensureTable();

            return new SqlEntityStorage($definition, $db, $dispatcher);
        });

        $entityTypeManager->registerEntityType(new EntityType(
            id: 'tenant',
            label: 'Tenant',
            class: Tenant::class,
            keys: ['id' => 'tid', 'uuid' => 'uuid', 'label' => 'name'],
        ));
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'workspace',
            label: 'Workspace',
            class: Workspace::class,
            keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        return $entityTypeManager;
    }
}

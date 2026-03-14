<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Controller\AdminSessionController;
use Claudriel\Entity\Account;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\Person;
use Claudriel\Entity\ScheduleEntry;
use Claudriel\Entity\Tenant;
use Claudriel\Entity\TriageEntry;
use Claudriel\Entity\Workspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class AdminSessionControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetSession();
    }

    protected function tearDown(): void
    {
        $this->resetSession();
    }

    public function test_state_requires_authenticated_admin_account(): void
    {
        $response = $this->controller()->state();

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('Not authenticated.', $response->content);
    }

    public function test_state_returns_tenant_context_and_admin_entity_types(): void
    {
        $entityTypeManager = $this->buildEntityTypeManager();
        $entityTypeManager->getStorage('tenant')->save(new Tenant([
            'uuid' => 'tenant-123',
            'name' => 'Tenant One',
            'metadata' => ['default_workspace_uuid' => 'workspace-abc'],
        ]));

        $account = new Account([
            'email' => 'owner@example.com',
            'status' => 'active',
            'email_verified_at' => '2026-03-14T15:00:00+00:00',
            'tenant_id' => 'tenant-123',
            'roles' => ['tenant_owner'],
        ]);

        $response = $this->controller($entityTypeManager)->state(account: new AuthenticatedAccount($account));

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('"tenant_id":"tenant-123"', $response->content);
        self::assertStringContainsString('"default_workspace_uuid":"workspace-abc"', $response->content);
        self::assertStringContainsString('"id":"workspace"', $response->content);
        self::assertStringNotContainsString('"id":"account"', $response->content);
    }

    private function controller(?EntityTypeManager $entityTypeManager = null): AdminSessionController
    {
        return new AdminSessionController($entityTypeManager ?? $this->buildEntityTypeManager());
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $db = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher;
        $entityTypeManager = new EntityTypeManager($dispatcher, function ($definition) use ($db, $dispatcher): SqlEntityStorage {
            (new SqlSchemaHandler($definition, $db))->ensureTable();

            return new SqlEntityStorage($definition, $db, $dispatcher);
        });

        foreach ([
            new EntityType(id: 'account', label: 'Account', class: Account::class, keys: ['id' => 'aid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'tenant', label: 'Tenant', class: Tenant::class, keys: ['id' => 'tid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'workspace', label: 'Workspace', class: Workspace::class, keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'schedule_entry', label: 'Schedule Entry', class: ScheduleEntry::class, keys: ['id' => 'seid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'triage_entry', label: 'Triage Entry', class: TriageEntry::class, keys: ['id' => 'teid', 'uuid' => 'uuid', 'label' => 'sender_name']),
        ] as $definition) {
            $entityTypeManager->registerEntityType($definition);
        }

        return $entityTypeManager;
    }

    private function resetSession(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }

        $_SESSION = [];
        session_id('claudriel-admin-session-'.bin2hex(random_bytes(4)));
        session_start();
    }
}

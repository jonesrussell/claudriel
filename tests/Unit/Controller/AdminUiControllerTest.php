<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Controller\AdminUiController;
use Claudriel\Entity\Account;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\Person;
use Claudriel\Entity\ScheduleEntry;
use Claudriel\Entity\Tenant;
use Claudriel\Entity\TriageEntry;
use Claudriel\Entity\Workspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\SSR\SsrResponse;

final class AdminUiControllerTest extends TestCase
{
    private string $buildRoot;

    protected function setUp(): void
    {
        $this->resetSession();
        $this->buildRoot = sys_get_temp_dir().'/claudriel-admin-ui-'.bin2hex(random_bytes(4));
        mkdir($this->buildRoot, 0777, true);
        file_put_contents($this->buildRoot.'/index.html', '<!doctype html><html><body><div id="__nuxt">Claudriel Admin</div></body></html>');
    }

    protected function tearDown(): void
    {
        $this->resetSession();
        @unlink($this->buildRoot.'/index.html');
        @rmdir($this->buildRoot);
    }

    public function test_show_redirects_anonymous_requests_to_public_login(): void
    {
        $response = $this->controller()->show(httpRequest: Request::create('/admin/commitment', 'GET'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login?redirect=%2Fadmin%2Fcommitment', $response->headers->get('Location'));
    }

    public function test_show_forbids_authenticated_accounts_without_admin_access(): void
    {
        $response = $this->controller()->show(
            account: new AuthenticatedAccount(new Account([
                'email' => 'member@example.com',
                'status' => 'active',
                'email_verified_at' => '2026-03-14T15:00:00+00:00',
                'tenant_id' => 'tenant-123',
                'roles' => [],
            ])),
        );

        self::assertInstanceOf(SsrResponse::class, $response);
        self::assertSame(403, $response->statusCode);
        self::assertStringContainsString('Admin access is required.', $response->content);
    }

    public function test_show_renders_admin_bundle_for_tenant_owner_accounts(): void
    {
        $response = $this->controller()->show(
            account: new AuthenticatedAccount(new Account([
                'email' => 'owner@example.com',
                'status' => 'active',
                'email_verified_at' => '2026-03-14T15:00:00+00:00',
                'tenant_id' => 'tenant-123',
                'roles' => ['tenant_owner'],
            ])),
        );

        self::assertInstanceOf(SsrResponse::class, $response);
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Claudriel Admin', $response->content);
    }

    private function controller(?EntityTypeManager $entityTypeManager = null): AdminUiController
    {
        return new AdminUiController($entityTypeManager ?? $this->buildEntityTypeManager(), $this->buildRoot);
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
        session_id('claudriel-admin-ui-'.bin2hex(random_bytes(4)));
        session_start();
    }
}

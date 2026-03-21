<?php

declare(strict_types=1);

namespace Claudriel\Tests\Feature\Subscriber;

use Claudriel\Entity\Project;
use Claudriel\Entity\ProjectRepo;
use Claudriel\Entity\Repo;
use Claudriel\Entity\Workspace;
use Claudriel\Entity\WorkspaceProject;
use Claudriel\Entity\WorkspaceRepo;
use Claudriel\Subscriber\JunctionCascadeSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

#[CoversClass(JunctionCascadeSubscriber::class)]
final class JunctionCascadeTest extends TestCase
{
    private DBALDatabase $db;

    private EntityTypeManager $manager;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher;

        $this->manager = new EntityTypeManager(
            $dispatcher,
            function ($definition) use ($dispatcher): SqlEntityStorage {
                (new SqlSchemaHandler($definition, $this->db))->ensureTable();

                return new SqlEntityStorage($definition, $this->db, $dispatcher);
            },
        );

        // Register cascade subscriber after manager (it needs the manager reference)
        $dispatcher->addSubscriber(new JunctionCascadeSubscriber($this->manager));

        $this->registerAllTypes();
    }

    #[Test]
    public function deleting_project_removes_project_repo_and_workspace_project_junctions(): void
    {
        $projectStorage = $this->manager->getStorage('project');
        $prStorage = $this->manager->getStorage('project_repo');
        $wpStorage = $this->manager->getStorage('workspace_project');

        // Create project
        $project = new Project(['name' => 'Test Project', 'tenant_id' => 'default']);
        $project->enforceIsNew();
        $projectStorage->save($project);
        $projectUuid = $project->get('uuid');

        // Create junction rows
        $pr = new ProjectRepo(['project_uuid' => $projectUuid, 'repo_uuid' => 'repo-1']);
        $pr->enforceIsNew();
        $prStorage->save($pr);

        $wp = new WorkspaceProject(['workspace_uuid' => 'ws-1', 'project_uuid' => $projectUuid]);
        $wp->enforceIsNew();
        $wpStorage->save($wp);

        // Delete project
        $projectStorage->delete($this->loadAll($projectStorage));

        // Junctions should be gone
        self::assertCount(0, $this->loadAll($prStorage));
        self::assertCount(0, $this->loadAll($wpStorage));
    }

    #[Test]
    public function deleting_repo_removes_project_repo_and_workspace_repo_junctions(): void
    {
        $repoStorage = $this->manager->getStorage('repo');
        $prStorage = $this->manager->getStorage('project_repo');
        $wrStorage = $this->manager->getStorage('workspace_repo');

        // Create repo
        $repo = new Repo(['owner' => 'jonesrussell', 'name' => 'waaseyaa', 'tenant_id' => 'default']);
        $repo->enforceIsNew();
        $repoStorage->save($repo);
        $repoUuid = $repo->get('uuid');

        // Create junction rows
        $pr = new ProjectRepo(['project_uuid' => 'proj-1', 'repo_uuid' => $repoUuid]);
        $pr->enforceIsNew();
        $prStorage->save($pr);

        $wr = new WorkspaceRepo(['workspace_uuid' => 'ws-1', 'repo_uuid' => $repoUuid]);
        $wr->enforceIsNew();
        $wrStorage->save($wr);

        // Delete repo
        $repoStorage->delete($this->loadAll($repoStorage));

        // Junctions should be gone
        self::assertCount(0, $this->loadAll($prStorage));
        self::assertCount(0, $this->loadAll($wrStorage));
    }

    #[Test]
    public function deleting_workspace_removes_workspace_project_and_workspace_repo_junctions(): void
    {
        $wsStorage = $this->manager->getStorage('workspace');
        $wpStorage = $this->manager->getStorage('workspace_project');
        $wrStorage = $this->manager->getStorage('workspace_repo');

        // Create workspace
        $ws = new Workspace(['name' => 'Test Workspace', 'tenant_id' => 'default']);
        $ws->enforceIsNew();
        $wsStorage->save($ws);
        $wsUuid = $ws->get('uuid');

        // Create junction rows
        $wp = new WorkspaceProject(['workspace_uuid' => $wsUuid, 'project_uuid' => 'proj-1']);
        $wp->enforceIsNew();
        $wpStorage->save($wp);

        $wr = new WorkspaceRepo(['workspace_uuid' => $wsUuid, 'repo_uuid' => 'repo-1']);
        $wr->enforceIsNew();
        $wrStorage->save($wr);

        // Delete workspace
        $wsStorage->delete($this->loadAll($wsStorage));

        // Junctions should be gone
        self::assertCount(0, $this->loadAll($wpStorage));
        self::assertCount(0, $this->loadAll($wrStorage));
    }

    #[Test]
    public function deleting_project_does_not_delete_repos_or_workspaces(): void
    {
        $projectStorage = $this->manager->getStorage('project');
        $repoStorage = $this->manager->getStorage('repo');
        $wsStorage = $this->manager->getStorage('workspace');
        $prStorage = $this->manager->getStorage('project_repo');

        // Create repo and workspace
        $repo = new Repo(['owner' => 'jonesrussell', 'name' => 'waaseyaa', 'tenant_id' => 'default']);
        $repo->enforceIsNew();
        $repoStorage->save($repo);

        $ws = new Workspace(['name' => 'My Workspace', 'tenant_id' => 'default']);
        $ws->enforceIsNew();
        $wsStorage->save($ws);

        // Create project and link
        $project = new Project(['name' => 'Test Project', 'tenant_id' => 'default']);
        $project->enforceIsNew();
        $projectStorage->save($project);
        $projectUuid = $project->get('uuid');
        $repoUuid = $repo->get('uuid');

        $pr = new ProjectRepo(['project_uuid' => $projectUuid, 'repo_uuid' => $repoUuid]);
        $pr->enforceIsNew();
        $prStorage->save($pr);

        // Delete project
        $projectStorage->delete($this->loadAll($projectStorage));

        // Repo and workspace should still exist
        self::assertCount(1, $this->loadAll($repoStorage));
        self::assertCount(1, $this->loadAll($wsStorage));
    }

    /** @return array<int|string, EntityInterface> */
    private function loadAll(SqlEntityStorage $storage): array
    {
        return $storage->loadMultiple($storage->getQuery()->execute());
    }

    private function registerAllTypes(): void
    {
        $this->manager->registerEntityType(new EntityType(
            id: 'project',
            label: 'Project',
            class: Project::class,
            keys: ['id' => 'prid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'prid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'account_id' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->manager->registerEntityType(new EntityType(
            id: 'workspace',
            label: 'Workspace',
            class: Workspace::class,
            keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'wid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'mode' => ['type' => 'string'],
                'saved_context' => ['type' => 'text_long'],
                'account_id' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->manager->registerEntityType(new EntityType(
            id: 'repo',
            label: 'Repo',
            class: Repo::class,
            keys: ['id' => 'rid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'rid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'owner' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'full_name' => ['type' => 'string'],
                'url' => ['type' => 'string'],
                'default_branch' => ['type' => 'string'],
                'local_path' => ['type' => 'string'],
                'account_id' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->manager->registerEntityType(new EntityType(
            id: 'project_repo',
            label: 'Project Repo',
            class: ProjectRepo::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'uuid'],
            fieldDefinitions: [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'project_uuid' => ['type' => 'string', 'required' => true],
                'repo_uuid' => ['type' => 'string', 'required' => true],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->manager->registerEntityType(new EntityType(
            id: 'workspace_project',
            label: 'Workspace Project',
            class: WorkspaceProject::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'uuid'],
            fieldDefinitions: [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'workspace_uuid' => ['type' => 'string', 'required' => true],
                'project_uuid' => ['type' => 'string', 'required' => true],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->manager->registerEntityType(new EntityType(
            id: 'workspace_repo',
            label: 'Workspace Repo',
            class: WorkspaceRepo::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'uuid'],
            fieldDefinitions: [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'workspace_uuid' => ['type' => 'string', 'required' => true],
                'repo_uuid' => ['type' => 'string', 'required' => true],
                'is_active' => ['type' => 'boolean'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }
}

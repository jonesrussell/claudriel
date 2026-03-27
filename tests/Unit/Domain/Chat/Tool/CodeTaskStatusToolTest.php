<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Chat\Tool;

use Claudriel\Domain\Chat\Tool\CodeTaskStatusTool;
use Claudriel\Entity\CodeTask;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class CodeTaskStatusToolTest extends TestCase
{
    public function test_definition_has_required_fields(): void
    {
        $tool = $this->makeTool();
        $def = $tool->definition();

        self::assertSame('code_task_status', $def['name']);
        self::assertArrayHasKey('description', $def);
        self::assertSame(['task_uuid'], $def['input_schema']['required']);
    }

    public function test_execute_rejects_empty_uuid(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['task_uuid' => '']);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('required', $result['error']);
    }

    public function test_execute_returns_not_found_for_missing_task(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['task_uuid' => 'nonexistent']);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('not found', $result['error']);
    }

    public function test_execute_returns_task_data(): void
    {
        $repo = $this->makeRepo();
        $task = new CodeTask([
            'ctid' => 1,
            'uuid' => 'task-1',
            'workspace_uuid' => 'ws-1',
            'repo_uuid' => 'repo-1',
            'prompt' => 'Fix the bug',
            'status' => 'completed',
            'summary' => 'Fixed it',
            'pr_url' => 'https://github.com/test/repo/pull/1',
            'tenant_id' => 'default',
        ]);
        $repo->save($task);

        $tool = new CodeTaskStatusTool($repo);
        $result = $tool->execute(['task_uuid' => 'task-1']);

        self::assertSame('task-1', $result['uuid']);
        self::assertSame('completed', $result['status']);
        self::assertSame('Fixed it', $result['summary']);
        self::assertSame('https://github.com/test/repo/pull/1', $result['pr_url']);
    }

    private function makeTool(): CodeTaskStatusTool
    {
        return new CodeTaskStatusTool($this->makeRepo());
    }

    private function makeRepo(): EntityRepository
    {
        return new EntityRepository(
            new EntityType(
                id: 'code_task',
                label: 'Code Task',
                class: CodeTask::class,
                keys: ['id' => 'ctid', 'uuid' => 'uuid', 'label' => 'prompt'],
            ),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );
    }
}

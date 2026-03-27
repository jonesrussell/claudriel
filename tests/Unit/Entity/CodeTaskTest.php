<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Entity;

use Claudriel\Entity\CodeTask;
use PHPUnit\Framework\TestCase;

final class CodeTaskTest extends TestCase
{
    public function test_construct_defaults(): void
    {
        $task = new CodeTask(['workspace_uuid' => 'ws-1', 'repo_uuid' => 'repo-1', 'prompt' => 'Fix the bug']);

        $this->assertSame('queued', $task->get('status'));
        $this->assertSame('ws-1', $task->get('workspace_uuid'));
        $this->assertSame('repo-1', $task->get('repo_uuid'));
        $this->assertSame('Fix the bug', $task->get('prompt'));
        $this->assertNull($task->get('pr_url'));
        $this->assertNull($task->get('summary'));
        $this->assertNull($task->get('diff_preview'));
        $this->assertNull($task->get('error'));
        $this->assertNull($task->get('started_at'));
        $this->assertNull($task->get('completed_at'));
    }

    public function test_construct_with_explicit_status(): void
    {
        $task = new CodeTask(['status' => 'running']);

        $this->assertSame('running', $task->get('status'));
    }

    public function test_branch_name_generation(): void
    {
        $task = new CodeTask(['branch_name' => 'claudriel/fix-login']);

        $this->assertSame('claudriel/fix-login', $task->get('branch_name'));
    }

    public function test_branch_name_defaults_to_null(): void
    {
        $task = new CodeTask(['workspace_uuid' => 'ws-1', 'repo_uuid' => 'repo-1', 'prompt' => 'Fix bug']);
        $this->assertNull($task->get('branch_name'));
    }

    public function test_entity_type_id(): void
    {
        $task = new CodeTask;
        $this->assertSame('code_task', $task->getEntityTypeId());
    }
}

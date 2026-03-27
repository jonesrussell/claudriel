<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Chat\Tool;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Domain\Chat\Tool\RepoCloneTool;
use PHPUnit\Framework\TestCase;

final class RepoCloneToolTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';

    public function test_definition_has_required_fields(): void
    {
        $tool = $this->makeTool();
        $def = $tool->definition();

        self::assertSame('repo_clone', $def['name']);
        self::assertArrayHasKey('description', $def);
        self::assertSame(['workspace_uuid', 'repo'], $def['input_schema']['required']);
    }

    public function test_execute_rejects_empty_workspace_uuid(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['workspace_uuid' => '', 'repo' => 'owner/repo']);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('required', $result['error']);
    }

    public function test_execute_rejects_empty_repo(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['workspace_uuid' => 'ws-1', 'repo' => '']);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('required', $result['error']);
    }

    public function test_execute_rejects_invalid_repo_format(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['workspace_uuid' => 'ws-1', 'repo' => 'invalid']);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('owner/name', $result['error']);
    }

    private function makeTool(): RepoCloneTool
    {
        return new RepoCloneTool(
            'http://localhost:9999',
            'acct-1',
            'tenant-1',
            new InternalApiTokenGenerator(self::SECRET),
        );
    }
}

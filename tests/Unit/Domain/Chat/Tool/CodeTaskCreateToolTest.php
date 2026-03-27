<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Chat\Tool;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Domain\Chat\Tool\CodeTaskCreateTool;
use PHPUnit\Framework\TestCase;

final class CodeTaskCreateToolTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';

    public function test_definition_has_required_fields(): void
    {
        $tool = $this->makeTool();
        $def = $tool->definition();

        self::assertSame('code_task_create', $def['name']);
        self::assertArrayHasKey('description', $def);
        self::assertSame(['repo', 'prompt'], $def['input_schema']['required']);
    }

    public function test_execute_rejects_empty_repo(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['repo' => '', 'prompt' => 'Fix the bug']);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('required', $result['error']);
    }

    public function test_execute_rejects_empty_prompt(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['repo' => 'owner/repo', 'prompt' => '']);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('required', $result['error']);
    }

    public function test_execute_rejects_invalid_repo_format(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute(['repo' => 'not-a-valid-repo', 'prompt' => 'Fix it']);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('owner/name', $result['error']);
    }

    private function makeTool(): CodeTaskCreateTool
    {
        return new CodeTaskCreateTool(
            'http://localhost:9999',
            'acct-1',
            'tenant-1',
            new InternalApiTokenGenerator(self::SECRET),
        );
    }
}

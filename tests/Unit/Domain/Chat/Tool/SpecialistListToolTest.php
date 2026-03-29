<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Chat\Tool;

use Claudriel\Domain\Chat\Tool\SpecialistListTool;
use PHPUnit\Framework\TestCase;

final class SpecialistListToolTest extends TestCase
{
    public function test_definition_has_required_fields(): void
    {
        $tool = new SpecialistListTool('http://localhost:9999');
        $def = $tool->definition();

        self::assertSame('list_specialists', $def['name']);
        self::assertArrayHasKey('description', $def);
        self::assertArrayHasKey('input_schema', $def);
        self::assertArrayHasKey('properties', $def['input_schema']);
        self::assertArrayHasKey('query', $def['input_schema']['properties']);
        self::assertArrayHasKey('division', $def['input_schema']['properties']);
        self::assertArrayHasKey('limit', $def['input_schema']['properties']);
    }

    public function test_definition_has_no_required_params(): void
    {
        $tool = new SpecialistListTool('http://localhost:9999');
        $def = $tool->definition();

        self::assertArrayNotHasKey('required', $def['input_schema']);
    }

    public function test_execute_builds_correct_url_with_params(): void
    {
        $capturedUrl = null;
        $httpGet = static function (string $url) use (&$capturedUrl): string {
            $capturedUrl = $url;

            return json_encode(['data' => []]);
        };

        $tool = new SpecialistListTool('http://agency.test', $httpGet);
        $tool->execute(['query' => 'php', 'division' => 'engineering', 'limit' => 5]);

        self::assertNotNull($capturedUrl);
        self::assertStringContainsString('/v1/agents?', $capturedUrl);
        self::assertStringContainsString('q=php', $capturedUrl);
        self::assertStringContainsString('division=engineering', $capturedUrl);
        self::assertStringContainsString('limit=5', $capturedUrl);
    }

    public function test_execute_returns_specialists_from_api(): void
    {
        $agents = [
            ['id' => 'a1', 'name' => 'Alice', 'division' => 'eng'],
            ['id' => 'a2', 'name' => 'Bob', 'division' => 'eng'],
        ];
        $httpGet = static fn (string $url): string => json_encode(['data' => $agents]);

        $tool = new SpecialistListTool('http://agency.test', $httpGet);
        $result = $tool->execute([]);

        self::assertArrayHasKey('specialists', $result);
        self::assertArrayHasKey('total', $result);
        self::assertCount(2, $result['specialists']);
        self::assertSame(2, $result['total']);
        self::assertSame('Alice', $result['specialists'][0]['name']);
    }

    public function test_execute_returns_error_on_http_failure(): void
    {
        $httpGet = static fn (string $url): false => false;

        $tool = new SpecialistListTool('http://agency.test', $httpGet);
        $result = $tool->execute([]);

        self::assertArrayHasKey('error', $result);
        self::assertSame('Specialist service unavailable', $result['error']);
    }

    public function test_execute_defaults_limit_to_10(): void
    {
        $capturedUrl = null;
        $httpGet = static function (string $url) use (&$capturedUrl): string {
            $capturedUrl = $url;

            return json_encode(['data' => []]);
        };

        $tool = new SpecialistListTool('http://agency.test', $httpGet);
        $tool->execute([]);

        self::assertNotNull($capturedUrl);
        self::assertStringContainsString('limit=10', $capturedUrl);
    }

    public function test_execute_caps_limit_at_50(): void
    {
        $capturedUrl = null;
        $httpGet = static function (string $url) use (&$capturedUrl): string {
            $capturedUrl = $url;

            return json_encode(['data' => []]);
        };

        $tool = new SpecialistListTool('http://agency.test', $httpGet);
        $tool->execute(['limit' => 200]);

        self::assertNotNull($capturedUrl);
        self::assertStringContainsString('limit=50', $capturedUrl);
    }
}

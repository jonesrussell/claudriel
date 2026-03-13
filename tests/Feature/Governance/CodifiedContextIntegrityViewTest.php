<?php

declare(strict_types=1);

namespace Claudriel\Tests\Feature\Governance;

use Claudriel\Controller\Governance\CodifiedContextIntegrityController;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class CodifiedContextIntegrityViewTest extends TestCase
{
    public function test_html_view_renders_expected_sections(): void
    {
        $controller = new CodifiedContextIntegrityController(
            new Environment(new FilesystemLoader('/home/fsd42/dev/claudriel/templates')),
            '/home/fsd42/dev/claudriel',
        );

        $response = $controller->index();

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Codified Context Integrity Report', $response->content);
        self::assertStringContainsString('Issue Table', $response->content);
        self::assertStringContainsString('Generated at', $response->content);
    }

    public function test_json_view_returns_expected_structure(): void
    {
        $controller = new CodifiedContextIntegrityController(null, '/home/fsd42/dev/claudriel');

        $response = $controller->jsonView();
        $payload = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertArrayHasKey('generated_at', $payload);
        self::assertArrayHasKey('issues', $payload);
        self::assertArrayHasKey('classifications', $payload);
        self::assertArrayHasKey('summary', $payload);
    }
}

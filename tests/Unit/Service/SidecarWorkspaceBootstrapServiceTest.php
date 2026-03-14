<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Service;

use Claudriel\Service\SidecarWorkspaceBootstrapService;
use PHPUnit\Framework\TestCase;

final class SidecarWorkspaceBootstrapServiceTest extends TestCase
{
    public function test_bootstrap_uses_tenant_and_workspace_scoped_payload(): void
    {
        $observed = [];
        $service = new SidecarWorkspaceBootstrapService(responder: static function (string $tenantId, string $workspaceId) use (&$observed): array {
            $observed[] = [$tenantId, $workspaceId];

            return [
                'state' => 'existing',
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceId,
            ];
        });

        $result = $service->bootstrap('tenant-123', 'workspace-abc');

        self::assertSame([['tenant-123', 'workspace-abc']], $observed);
        self::assertSame('existing', $result['state']);
        self::assertSame('tenant-123', $result['tenant_id']);
        self::assertSame('workspace-abc', $result['workspace_id']);
    }
}

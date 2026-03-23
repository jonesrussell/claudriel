<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat\Tool;

use Claudriel\Domain\Chat\AgentToolInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class WorkspaceDeleteTool implements AgentToolInterface
{
    public function __construct(
        private readonly EntityRepositoryInterface $workspaceRepo,
        private readonly string $tenantId,
    ) {}

    public function definition(): array
    {
        return [
            'name' => 'workspace_delete',
            'description' => 'Delete a workspace by UUID.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'uuid' => [
                        'type' => 'string',
                        'description' => 'The workspace UUID to delete',
                    ],
                ],
                'required' => ['uuid'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $uuid = $args['uuid'] ?? '';
        if ($uuid === '') {
            return ['error' => 'Workspace UUID required'];
        }

        $results = $this->workspaceRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        $workspace = $results[0] ?? null;

        if ($workspace === null) {
            return ['error' => 'Workspace not found'];
        }

        $this->workspaceRepo->delete($workspace);

        return ['success' => true];
    }
}

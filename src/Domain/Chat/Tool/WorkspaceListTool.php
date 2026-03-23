<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat\Tool;

use Claudriel\Domain\Chat\AgentToolInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class WorkspaceListTool implements AgentToolInterface
{
    public function __construct(
        private readonly EntityRepositoryInterface $workspaceRepo,
        private readonly string $tenantId,
    ) {}

    public function definition(): array
    {
        return [
            'name' => 'workspace_list',
            'description' => 'List all workspaces.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of workspaces to return (default: 50)',
                    ],
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $limit = min((int) ($args['limit'] ?? 50), 100);

        $all = $this->workspaceRepo->findBy(['tenant_id' => $this->tenantId]);

        $items = [];
        $count = 0;
        foreach ($all as $workspace) {
            if ($count >= $limit) {
                break;
            }
            $items[] = [
                'uuid' => $workspace->get('uuid'),
                'name' => $workspace->get('name'),
                'description' => $workspace->get('description'),
                'status' => $workspace->get('status'),
                'mode' => $workspace->get('mode'),
            ];
            $count++;
        }

        return ['workspaces' => $items, 'count' => $count];
    }
}

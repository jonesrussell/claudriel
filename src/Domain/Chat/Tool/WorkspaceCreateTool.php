<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat\Tool;

use Claudriel\Domain\Chat\AgentToolInterface;
use Claudriel\Entity\Workspace;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class WorkspaceCreateTool implements AgentToolInterface
{
    public function __construct(
        private readonly EntityRepositoryInterface $workspaceRepo,
        private readonly string $tenantId,
        private readonly string $accountId,
    ) {}

    public function definition(): array
    {
        return [
            'name' => 'workspace_create',
            'description' => 'Create a new workspace. Extract a short, descriptive name from the user\'s request (prefer repo name, project name, or client name). NEVER use the full user sentence as the name. The name should be 1-3 words maximum.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Short workspace name (1-3 words).',
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Optional description of the workspace purpose.',
                    ],
                    'mode' => [
                        'type' => 'string',
                        'description' => 'Workspace mode: persistent or ephemeral (default: persistent)',
                        'enum' => ['persistent', 'ephemeral'],
                    ],
                ],
                'required' => ['name'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $name = trim((string) ($args['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 100) {
            return ['error' => 'Workspace name is required (1-100 characters)'];
        }

        $mode = $args['mode'] ?? 'persistent';
        $allowedModes = ['persistent', 'ephemeral'];
        if (! in_array($mode, $allowedModes, true)) {
            return ['error' => 'Invalid mode. Allowed: persistent, ephemeral'];
        }

        $workspace = new Workspace([
            'uuid' => $this->generateUuid(),
            'name' => $name,
            'description' => $args['description'] ?? '',
            'mode' => $mode,
            'status' => 'active',
            'account_id' => $this->accountId,
            'tenant_id' => $this->tenantId,
        ]);
        $this->workspaceRepo->save($workspace);

        return [
            'uuid' => $workspace->get('uuid'),
            'name' => $workspace->get('name'),
            'status' => 'active',
            'mode' => $mode,
            'created_at' => (new \DateTimeImmutable)->format('c'),
        ];
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xFFFF), random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0x0FFF) | 0x4000,
            random_int(0, 0x3FFF) | 0x8000,
            random_int(0, 0xFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFFF),
        );
    }
}

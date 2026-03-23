<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat\Tool;

use Claudriel\Domain\Chat\AgentToolInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class CommitmentUpdateTool implements AgentToolInterface
{
    public function __construct(
        private readonly EntityRepositoryInterface $commitmentRepo,
        private readonly string $tenantId,
    ) {}

    public function definition(): array
    {
        return [
            'name' => 'commitment_update',
            'description' => 'Update a commitment\'s status or add notes.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'uuid' => [
                        'type' => 'string',
                        'description' => 'The commitment UUID',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'New status',
                        'enum' => ['active', 'pending', 'completed', 'overdue'],
                    ],
                    'notes' => [
                        'type' => 'string',
                        'description' => 'Notes to add',
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
            return ['error' => 'Commitment UUID required'];
        }

        $results = $this->commitmentRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        $commitment = $results[0] ?? null;

        if ($commitment === null) {
            return ['error' => 'Commitment not found'];
        }

        $updatedStatus = $args['status'] ?? null;
        if (is_string($updatedStatus) && $updatedStatus !== '') {
            $commitment->set('status', $updatedStatus);
        }

        $notes = $args['notes'] ?? null;
        if (is_string($notes) && $notes !== '') {
            $commitment->set('notes', $notes);
        }

        $this->commitmentRepo->save($commitment);

        return [
            'uuid' => $commitment->get('uuid'),
            'title' => $commitment->get('title'),
            'status' => $commitment->get('status'),
            'updated' => true,
        ];
    }
}

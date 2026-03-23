<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat\Tool;

use Claudriel\Domain\Chat\AgentToolInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class CommitmentListTool implements AgentToolInterface
{
    public function __construct(
        private readonly EntityRepositoryInterface $commitmentRepo,
        private readonly string $tenantId,
    ) {}

    public function definition(): array
    {
        return [
            'name' => 'commitment_list',
            'description' => 'List the user\'s commitments, optionally filtered by status.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'description' => 'Filter: active, pending, completed, overdue',
                        'enum' => ['active', 'pending', 'completed', 'overdue'],
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Max results (default: 20)',
                    ],
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $limit = min((int) ($args['limit'] ?? 20), 100);

        $criteria = ['tenant_id' => $this->tenantId];
        $requestedStatus = $args['status'] ?? null;
        if (is_string($requestedStatus) && $requestedStatus !== '') {
            $criteria['status'] = $requestedStatus;
        }

        $all = $this->commitmentRepo->findBy($criteria);

        $items = [];
        $count = 0;
        foreach ($all as $commitment) {
            if ($count >= $limit) {
                break;
            }
            $items[] = [
                'uuid' => $commitment->get('uuid'),
                'title' => $commitment->get('title'),
                'status' => $commitment->get('status'),
                'direction' => $commitment->get('direction') ?? 'outbound',
                'due_date' => $commitment->get('due_date'),
                'person_name' => $commitment->get('person_name'),
                'notes' => $commitment->get('notes'),
            ];
            $count++;
        }

        return ['commitments' => $items, 'count' => $count];
    }
}

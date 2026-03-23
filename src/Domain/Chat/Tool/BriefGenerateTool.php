<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat\Tool;

use Claudriel\Domain\Chat\AgentToolInterface;
use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Temporal\TimeSnapshot;

final class BriefGenerateTool implements AgentToolInterface
{
    public function __construct(
        private readonly DayBriefAssembler $assembler,
        private readonly string $tenantId,
    ) {}

    public function definition(): array
    {
        return [
            'name' => 'brief_generate',
            'description' => 'Generate the user\'s daily brief with commitments, events, and context.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'since' => [
                        'type' => 'string',
                        'description' => 'ISO date for time window start (default: 24h ago)',
                    ],
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $since = $args['since'] ?? null;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $snapshot = new TimeSnapshot(
            $now,
            new \DateTimeImmutable,
            hrtime(true),
            date_default_timezone_get(),
        );

        $sinceDate = null;
        if (is_string($since) && $since !== '') {
            try {
                $sinceDate = new \DateTimeImmutable($since);
            } catch (\Throwable) {
                $sinceDate = $now->modify('-24 hours');
            }
        } else {
            $sinceDate = $now->modify('-24 hours');
        }

        return $this->assembler->assemble($this->tenantId, $sinceDate, snapshot: $snapshot);
    }
}

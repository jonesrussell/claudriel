<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat\Tool;

use Claudriel\Domain\Chat\AgentToolInterface;

final class SpecialistListTool implements AgentToolInterface
{
    private \Closure $httpGet;

    public function __construct(
        private readonly string $baseUrl,
        ?\Closure $httpGet = null,
    ) {
        $this->httpGet = $httpGet ?? static function (string $url): string|false {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "Accept: application/json\r\n",
                    'timeout' => 10,
                    'ignore_errors' => true,
                ],
            ]);

            return file_get_contents($url, false, $context);
        };
    }

    public function definition(): array
    {
        return [
            'name' => 'list_specialists',
            'description' => 'List available specialists from the agency, optionally filtered by query or division.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Search query to filter specialists by name or capability',
                    ],
                    'division' => [
                        'type' => 'string',
                        'description' => 'Filter by division name',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Max results (default: 10, max: 50)',
                    ],
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $limit = min((int) ($args['limit'] ?? 10), 50);

        $params = ['limit' => $limit];
        if (isset($args['query']) && is_string($args['query']) && $args['query'] !== '') {
            $params['q'] = $args['query'];
        }
        if (isset($args['division']) && is_string($args['division']) && $args['division'] !== '') {
            $params['division'] = $args['division'];
        }

        $url = rtrim($this->baseUrl, '/').'/v1/agents?'.http_build_query($params);

        try {
            $response = ($this->httpGet)($url);
            if ($response === false) {
                return ['error' => 'Specialist service unavailable'];
            }

            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            $specialists = $data['data'] ?? $data['agents'] ?? $data;
            if (! is_array($specialists)) {
                $specialists = [];
            }

            return [
                'specialists' => $specialists,
                'total' => count($specialists),
            ];
        } catch (\Throwable) {
            return ['error' => 'Specialist service unavailable'];
        }
    }
}

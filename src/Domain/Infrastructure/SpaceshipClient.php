<?php

declare(strict_types=1);

namespace Claudriel\Domain\Infrastructure;

/**
 * Spaceship.com API client for domain management.
 *
 * Handles DNS record management and domain status monitoring.
 * API key is stored in vault as SPACESHIP_API_KEY.
 *
 * @see https://www.spaceship.com/
 */
final class SpaceshipClient
{
    private const BASE_URL = 'https://spaceship.com/api/v1';

    public function __construct(
        private readonly string $apiKey,
    ) {}

    /**
     * List DNS records for a domain.
     *
     * @return array<int, array{type: string, name: string, value: string, ttl: int}>
     */
    public function listDnsRecords(string $domain): array
    {
        $response = $this->request('GET', "/dns/{$domain}/records");

        return $response['records'] ?? [];
    }

    /**
     * Create a DNS record.
     */
    public function createDnsRecord(string $domain, string $type, string $name, string $value, int $ttl = 3600): array
    {
        return $this->request('POST', "/dns/{$domain}/records", [
            'type' => $type,
            'name' => $name,
            'value' => $value,
            'ttl' => $ttl,
        ]);
    }

    /**
     * Update a DNS record.
     */
    public function updateDnsRecord(string $domain, string $recordId, array $data): array
    {
        return $this->request('PUT', "/dns/{$domain}/records/{$recordId}", $data);
    }

    /**
     * Delete a DNS record.
     */
    public function deleteDnsRecord(string $domain, string $recordId): void
    {
        $this->request('DELETE', "/dns/{$domain}/records/{$recordId}");
    }

    /**
     * Check domain status (registered, expiring, etc.).
     */
    public function domainStatus(string $domain): array
    {
        return $this->request('GET', "/domains/{$domain}");
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = self::BASE_URL.$path;
        $headers = [
            "Authorization: Bearer {$this->apiKey}",
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $options = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers)."\r\n",
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ];

        if ($body !== null) {
            $options['http']['content'] = json_encode($body, JSON_THROW_ON_ERROR);
        }

        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        if ($result === false) {
            throw new \RuntimeException("Spaceship API request failed: {$method} {$path}");
        }

        $decoded = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}

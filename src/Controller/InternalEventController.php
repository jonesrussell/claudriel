<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\SSR\SsrResponse;

final class InternalEventController
{
    public function __construct(
        private readonly EntityRepositoryInterface $eventRepo,
        private readonly InternalApiTokenGenerator $apiTokenGenerator,
        private readonly string $tenantId,
    ) {}

    public function search(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $keyword = $query['query'] ?? '';
        $dateFrom = $query['date_from'] ?? null;
        $dateTo = $query['date_to'] ?? null;
        $limit = min(max((int) ($query['limit'] ?? 20), 1), 100);

        $allEvents = $this->eventRepo->findBy([]);

        $results = [];
        foreach ($allEvents as $event) {
            $eventTenant = (string) ($event->get('tenant_id') ?? '');
            if ($eventTenant !== '' && $eventTenant !== $this->tenantId) {
                continue;
            }
            if ($eventTenant === '' && $this->tenantId !== 'default') {
                continue;
            }

            if ($keyword !== '') {
                $payload = json_decode((string) ($event->get('payload') ?? '{}'), true) ?? [];
                $searchable = mb_strtolower(implode(' ', [
                    (string) ($event->get('source') ?? ''),
                    (string) ($event->get('type') ?? ''),
                    (string) ($payload['subject'] ?? ''),
                    (string) ($payload['body'] ?? ''),
                    (string) ($payload['title'] ?? ''),
                ]));

                if (! str_contains($searchable, mb_strtolower($keyword))) {
                    continue;
                }
            }

            $occurred = $event->get('occurred');
            if ($dateFrom !== null && is_string($dateFrom) && $occurred !== null) {
                try {
                    if (new \DateTimeImmutable((string) $occurred) < new \DateTimeImmutable($dateFrom)) {
                        continue;
                    }
                } catch (\Throwable) {
                    // skip date filter on parse error
                }
            }

            if ($dateTo !== null && is_string($dateTo) && $occurred !== null) {
                try {
                    if (new \DateTimeImmutable((string) $occurred) > new \DateTimeImmutable($dateTo)) {
                        continue;
                    }
                } catch (\Throwable) {
                    // skip date filter on parse error
                }
            }

            $results[] = [
                'id' => (string) ($event->get('eid') ?? ''),
                'uuid' => (string) ($event->get('uuid') ?? ''),
                'source' => (string) ($event->get('source') ?? ''),
                'type' => (string) ($event->get('type') ?? ''),
                'category' => (string) ($event->get('category') ?? ''),
                'occurred' => (string) ($occurred ?? ''),
                'payload' => json_decode((string) ($event->get('payload') ?? '{}'), true) ?? [],
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $this->jsonResponse(['events' => $results, 'count' => count($results)]);
    }

    private function authenticate(mixed $httpRequest): ?string
    {
        $auth = '';
        if ($httpRequest instanceof Request) {
            $auth = $httpRequest->headers->get('Authorization', '');
        }

        if (! str_starts_with($auth, 'Bearer ')) {
            return null;
        }

        return $this->apiTokenGenerator->validate(substr($auth, 7));
    }

    private function jsonResponse(array $data): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    private function jsonError(string $message, int $statusCode): SsrResponse
    {
        return new SsrResponse(
            content: json_encode(['error' => $message], JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}

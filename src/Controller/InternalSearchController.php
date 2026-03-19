<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\SSR\SsrResponse;

final class InternalSearchController
{
    public function __construct(
        private readonly EntityRepositoryInterface $personRepo,
        private readonly EntityRepositoryInterface $commitmentRepo,
        private readonly EntityRepositoryInterface $eventRepo,
        private readonly InternalApiTokenGenerator $apiTokenGenerator,
        private readonly string $tenantId,
    ) {}

    public function searchGlobal(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $keyword = $query['query'] ?? '';
        if ($keyword === '') {
            return $this->jsonError('query parameter is required', 400);
        }

        $limit = min(max((int) ($query['limit'] ?? 10), 1), 50);
        $lowerKeyword = mb_strtolower($keyword);

        $persons = $this->searchPersons($lowerKeyword, $limit);
        $commitments = $this->searchCommitments($lowerKeyword, $limit);
        $events = $this->searchEvents($lowerKeyword, $limit);

        return $this->jsonResponse([
            'persons' => $persons,
            'commitments' => $commitments,
            'events' => $events,
        ]);
    }

    private function searchPersons(string $keyword, int $limit): array
    {
        $all = $this->personRepo->findBy(['tenant_id' => $this->tenantId]);
        $results = [];

        foreach ($all as $person) {

            $searchable = mb_strtolower(implode(' ', [
                (string) ($person->get('name') ?? ''),
                (string) ($person->get('email') ?? ''),
            ]));

            if (str_contains($searchable, $keyword)) {
                $results[] = [
                    'id' => (string) ($person->get('pid') ?? ''),
                    'uuid' => (string) ($person->get('uuid') ?? ''),
                    'name' => (string) ($person->get('name') ?? ''),
                    'email' => (string) ($person->get('email') ?? ''),
                    'tier' => (string) ($person->get('tier') ?? ''),
                ];

                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }

    private function searchCommitments(string $keyword, int $limit): array
    {
        $all = $this->commitmentRepo->findBy(['tenant_id' => $this->tenantId]);
        $results = [];

        foreach ($all as $commitment) {

            $searchable = mb_strtolower((string) ($commitment->get('title') ?? ''));

            if (str_contains($searchable, $keyword)) {
                $results[] = [
                    'id' => (string) ($commitment->get('cid') ?? ''),
                    'uuid' => (string) ($commitment->get('uuid') ?? ''),
                    'title' => (string) ($commitment->get('title') ?? ''),
                    'status' => (string) ($commitment->get('status') ?? ''),
                    'confidence' => (float) ($commitment->get('confidence') ?? 0),
                ];

                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }

    private function searchEvents(string $keyword, int $limit): array
    {
        $all = $this->eventRepo->findBy(['tenant_id' => $this->tenantId]);
        $results = [];

        foreach ($all as $event) {

            $payload = json_decode((string) ($event->get('payload') ?? '{}'), true) ?? [];
            $searchable = mb_strtolower(implode(' ', [
                (string) ($event->get('source') ?? ''),
                (string) ($event->get('type') ?? ''),
                (string) ($payload['subject'] ?? ''),
                (string) ($payload['body'] ?? ''),
                (string) ($payload['title'] ?? ''),
            ]));

            if (str_contains($searchable, $keyword)) {
                $results[] = [
                    'id' => (string) ($event->get('eid') ?? ''),
                    'uuid' => (string) ($event->get('uuid') ?? ''),
                    'source' => (string) ($event->get('source') ?? ''),
                    'type' => (string) ($event->get('type') ?? ''),
                    'category' => (string) ($event->get('category') ?? ''),
                    'occurred' => (string) ($event->get('occurred') ?? ''),
                ];

                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
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

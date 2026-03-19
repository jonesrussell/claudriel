<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\SSR\SsrResponse;

final class InternalTriageController
{
    public function __construct(
        private readonly EntityRepositoryInterface $triageRepo,
        private readonly InternalApiTokenGenerator $apiTokenGenerator,
        private readonly string $tenantId,
    ) {}

    public function listUntriaged(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $limit = min((int) ($query['limit'] ?? 20), 100);

        $all = $this->triageRepo->findBy(['tenant_id' => $this->tenantId]);

        $items = [];
        $count = 0;
        foreach ($all as $entry) {
            if ($count >= $limit) {
                break;
            }

            if ($entry->get('status') !== 'pending') {
                continue;
            }

            $items[] = [
                'uuid' => $entry->get('uuid'),
                'sender_name' => $entry->get('sender_name'),
                'sender_email' => $entry->get('sender_email'),
                'summary' => $entry->get('summary'),
                'source' => $entry->get('source'),
                'occurred_at' => $entry->get('occurred_at'),
            ];
            $count++;
        }

        return $this->jsonResponse(['entries' => $items, 'count' => $count]);
    }

    public function resolve(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return $this->jsonError('Triage entry UUID required', 400);
        }

        $results = $this->triageRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        $entry = $results[0] ?? null;

        if ($entry === null) {
            return $this->jsonError('Triage entry not found', 404);
        }

        $body = $this->getRequestBody($httpRequest);
        $newStatus = $body['status'] ?? 'resolved';

        $allowedStatuses = ['resolved', 'dismissed', 'escalated'];
        if (! in_array($newStatus, $allowedStatuses, true)) {
            return $this->jsonError('Invalid status. Allowed: '.implode(', ', $allowedStatuses), 400);
        }

        $entry->set('status', $newStatus);
        $this->triageRepo->save($entry);

        return $this->jsonResponse([
            'uuid' => $entry->get('uuid'),
            'status' => $entry->get('status'),
        ]);
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

    private function getRequestBody(mixed $httpRequest): ?array
    {
        if (! $httpRequest instanceof Request) {
            return null;
        }
        $content = $httpRequest->getContent();
        if ($content === '') {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
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

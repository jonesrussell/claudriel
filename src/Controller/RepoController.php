<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Entity\Repo;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\SSR\SsrResponse;

final class RepoController
{
    public function __construct(
        private readonly EntityRepositoryInterface $repoRepo,
        private readonly InternalApiTokenGenerator $apiTokenGenerator,
        private readonly string $tenantId,
    ) {}

    public function list(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $limit = min((int) ($query['limit'] ?? 50), 100);
        if ($limit < 1) {
            $limit = 50;
        }

        $repos = $this->repoRepo->findBy(['tenant_id' => $this->tenantId], ['created_at' => 'DESC'], $limit);

        $items = [];
        foreach ($repos as $repo) {
            $items[] = [
                'uuid' => $repo->get('uuid'),
                'owner' => $repo->get('owner'),
                'name' => $repo->get('name'),
                'full_name' => $repo->get('full_name'),
                'url' => $repo->get('url'),
                'default_branch' => $repo->get('default_branch'),
                'local_path' => $repo->get('local_path'),
                'account_id' => $repo->get('account_id'),
                'created_at' => $repo->get('created_at'),
                'updated_at' => $repo->get('updated_at'),
            ];
        }

        return $this->jsonResponse(['data' => $items, 'count' => count($items)]);
    }

    public function create(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $body = $this->getRequestBody($httpRequest);
        if ($body === null) {
            return $this->jsonError('Invalid request body', 400);
        }

        $owner = $body['owner'] ?? '';
        $name = $body['name'] ?? '';
        if ($owner === '' || $name === '') {
            return $this->jsonError('owner and name are required', 400);
        }

        $repo = new Repo([
            'owner' => $owner,
            'name' => $name,
            'url' => $body['url'] ?? null,
            'default_branch' => $body['default_branch'] ?? 'main',
            'local_path' => $body['local_path'] ?? null,
            'account_id' => $body['account_id'] ?? null,
            'tenant_id' => $this->tenantId,
        ]);
        $repo->enforceIsNew();
        $this->repoRepo->save($repo);

        return new SsrResponse(
            content: json_encode([
                'uuid' => $repo->get('uuid'),
                'owner' => $repo->get('owner'),
                'name' => $repo->get('name'),
                'full_name' => $repo->get('full_name'),
                'url' => $repo->get('url'),
                'default_branch' => $repo->get('default_branch'),
                'local_path' => $repo->get('local_path'),
                'account_id' => $repo->get('account_id'),
                'tenant_id' => $repo->get('tenant_id'),
                'created_at' => $repo->get('created_at'),
                'updated_at' => $repo->get('updated_at'),
            ], JSON_THROW_ON_ERROR),
            statusCode: 201,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    public function show(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return $this->jsonError('Repo UUID required', 400);
        }

        $results = $this->repoRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        if ($results === []) {
            return $this->jsonError('Repo not found', 404);
        }

        $repo = $results[0];

        return $this->jsonResponse([
            'uuid' => $repo->get('uuid'),
            'owner' => $repo->get('owner'),
            'name' => $repo->get('name'),
            'full_name' => $repo->get('full_name'),
            'url' => $repo->get('url'),
            'default_branch' => $repo->get('default_branch'),
            'local_path' => $repo->get('local_path'),
            'account_id' => $repo->get('account_id'),
            'tenant_id' => $repo->get('tenant_id'),
            'created_at' => $repo->get('created_at'),
            'updated_at' => $repo->get('updated_at'),
        ]);
    }

    public function update(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return $this->jsonError('Repo UUID required', 400);
        }

        $body = $this->getRequestBody($httpRequest);
        if ($body === null) {
            return $this->jsonError('Invalid request body', 400);
        }

        $results = $this->repoRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        if ($results === []) {
            return $this->jsonError('Repo not found', 404);
        }

        $repo = $results[0];

        if (isset($body['owner'])) {
            $repo->set('owner', $body['owner']);
        }
        if (isset($body['name'])) {
            $repo->set('name', $body['name']);
        }
        if (isset($body['url'])) {
            $repo->set('url', $body['url']);
        }
        if (isset($body['default_branch'])) {
            $repo->set('default_branch', $body['default_branch']);
        }
        if (isset($body['local_path'])) {
            $repo->set('local_path', $body['local_path']);
        }

        $this->repoRepo->save($repo);

        return $this->jsonResponse([
            'uuid' => $repo->get('uuid'),
            'owner' => $repo->get('owner'),
            'name' => $repo->get('name'),
            'full_name' => $repo->get('full_name'),
            'url' => $repo->get('url'),
            'default_branch' => $repo->get('default_branch'),
            'local_path' => $repo->get('local_path'),
            'account_id' => $repo->get('account_id'),
            'tenant_id' => $repo->get('tenant_id'),
            'created_at' => $repo->get('created_at'),
            'updated_at' => $repo->get('updated_at'),
        ]);
    }

    public function delete(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return $this->jsonError('Repo UUID required', 400);
        }

        $results = $this->repoRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        if ($results === []) {
            return $this->jsonError('Repo not found', 404);
        }

        $this->repoRepo->delete($results[0]);

        return new SsrResponse(
            content: '',
            statusCode: 204,
            headers: ['Content-Type' => 'application/json'],
        );
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

<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Entity\Project;
use Claudriel\Entity\ProjectRepo;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\SSR\SsrResponse;

final class ProjectController
{
    public function __construct(
        private readonly EntityRepositoryInterface $projectRepo,
        private readonly EntityRepositoryInterface $projectRepoJunctionRepo,
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

        $projects = $this->projectRepo->findBy(['tenant_id' => $this->tenantId], ['created_at' => 'DESC'], $limit);

        $items = [];
        foreach ($projects as $project) {
            $items[] = [
                'uuid' => $project->get('uuid'),
                'name' => $project->get('name'),
                'description' => $project->get('description'),
                'status' => $project->get('status'),
                'account_id' => $project->get('account_id'),
                'created_at' => $project->get('created_at'),
                'updated_at' => $project->get('updated_at'),
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

        $name = $body['name'] ?? '';
        if ($name === '') {
            return $this->jsonError('name is required', 400);
        }

        $project = new Project([
            'name' => $name,
            'description' => $body['description'] ?? '',
            'status' => $body['status'] ?? 'active',
            'account_id' => $body['account_id'] ?? null,
            'tenant_id' => $this->tenantId,
        ]);
        $project->enforceIsNew();
        $this->projectRepo->save($project);

        return new SsrResponse(
            content: json_encode([
                'uuid' => $project->get('uuid'),
                'name' => $project->get('name'),
                'description' => $project->get('description'),
                'status' => $project->get('status'),
                'account_id' => $project->get('account_id'),
                'tenant_id' => $project->get('tenant_id'),
                'created_at' => $project->get('created_at'),
                'updated_at' => $project->get('updated_at'),
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
            return $this->jsonError('Project UUID required', 400);
        }

        $results = $this->projectRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        if ($results === []) {
            return $this->jsonError('Project not found', 404);
        }

        $project = $results[0];

        return $this->jsonResponse([
            'uuid' => $project->get('uuid'),
            'name' => $project->get('name'),
            'description' => $project->get('description'),
            'status' => $project->get('status'),
            'account_id' => $project->get('account_id'),
            'tenant_id' => $project->get('tenant_id'),
            'created_at' => $project->get('created_at'),
            'updated_at' => $project->get('updated_at'),
        ]);
    }

    public function update(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return $this->jsonError('Project UUID required', 400);
        }

        $body = $this->getRequestBody($httpRequest);
        if ($body === null) {
            return $this->jsonError('Invalid request body', 400);
        }

        $results = $this->projectRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        if ($results === []) {
            return $this->jsonError('Project not found', 404);
        }

        $project = $results[0];

        if (isset($body['name'])) {
            $project->set('name', $body['name']);
        }
        if (isset($body['description'])) {
            $project->set('description', $body['description']);
        }
        if (isset($body['status'])) {
            $project->set('status', $body['status']);
        }

        $this->projectRepo->save($project);

        return $this->jsonResponse([
            'uuid' => $project->get('uuid'),
            'name' => $project->get('name'),
            'description' => $project->get('description'),
            'status' => $project->get('status'),
            'account_id' => $project->get('account_id'),
            'tenant_id' => $project->get('tenant_id'),
            'created_at' => $project->get('created_at'),
            'updated_at' => $project->get('updated_at'),
        ]);
    }

    public function delete(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return $this->jsonError('Project UUID required', 400);
        }

        $results = $this->projectRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        if ($results === []) {
            return $this->jsonError('Project not found', 404);
        }

        $this->projectRepo->delete($results[0]);

        return new SsrResponse(
            content: '',
            statusCode: 204,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    public function listRepos(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return $this->jsonError('Project UUID required', 400);
        }

        $projects = $this->projectRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        if ($projects === []) {
            return $this->jsonError('Project not found', 404);
        }

        $junctions = $this->projectRepoJunctionRepo->findBy(['project_uuid' => $uuid]);

        $repos = [];
        foreach ($junctions as $junction) {
            $repoUuid = $junction->get('repo_uuid');
            $repoResults = $this->repoRepo->findBy(['uuid' => $repoUuid, 'tenant_id' => $this->tenantId]);
            if ($repoResults !== []) {
                $repo = $repoResults[0];
                $repos[] = [
                    'uuid' => $repo->get('uuid'),
                    'owner' => $repo->get('owner'),
                    'name' => $repo->get('name'),
                    'full_name' => $repo->get('full_name'),
                    'url' => $repo->get('url'),
                    'default_branch' => $repo->get('default_branch'),
                    'local_path' => $repo->get('local_path'),
                ];
            }
        }

        return $this->jsonResponse(['data' => $repos, 'count' => count($repos)]);
    }

    public function linkRepo(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return $this->jsonError('Project UUID required', 400);
        }

        $body = $this->getRequestBody($httpRequest);
        if ($body === null) {
            return $this->jsonError('Invalid request body', 400);
        }

        $repoUuid = $body['repo_uuid'] ?? '';
        if ($repoUuid === '') {
            return $this->jsonError('repo_uuid is required', 400);
        }

        $projects = $this->projectRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        if ($projects === []) {
            return $this->jsonError('Project not found', 404);
        }

        $repos = $this->repoRepo->findBy(['uuid' => $repoUuid, 'tenant_id' => $this->tenantId]);
        if ($repos === []) {
            return $this->jsonError('Repo not found', 404);
        }

        $existing = $this->projectRepoJunctionRepo->findBy([
            'project_uuid' => $uuid,
            'repo_uuid' => $repoUuid,
        ]);
        if ($existing !== []) {
            return $this->jsonError('Link already exists', 409);
        }

        $junction = new ProjectRepo([
            'project_uuid' => $uuid,
            'repo_uuid' => $repoUuid,
        ]);
        $junction->enforceIsNew();
        $this->projectRepoJunctionRepo->save($junction);

        return new SsrResponse(
            content: json_encode([
                'uuid' => $junction->get('uuid'),
                'project_uuid' => $uuid,
                'repo_uuid' => $repoUuid,
            ], JSON_THROW_ON_ERROR),
            statusCode: 201,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    public function unlinkRepo(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = $params['uuid'] ?? '';
        $repoUuid = $params['repo_uuid'] ?? '';
        if ($uuid === '' || $repoUuid === '') {
            return $this->jsonError('Project UUID and repo UUID required', 400);
        }

        $junctions = $this->projectRepoJunctionRepo->findBy([
            'project_uuid' => $uuid,
            'repo_uuid' => $repoUuid,
        ]);
        if ($junctions === []) {
            return $this->jsonError('Link not found', 404);
        }

        $this->projectRepoJunctionRepo->delete($junctions[0]);

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

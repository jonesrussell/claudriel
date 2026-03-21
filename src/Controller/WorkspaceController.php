<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Entity\Workspace;
use Claudriel\Entity\WorkspaceProject;
use Claudriel\Entity\WorkspaceRepo;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\SSR\SsrResponse;

final class WorkspaceController
{
    public function __construct(
        private readonly EntityRepositoryInterface $workspaceRepo,
        private readonly EntityRepositoryInterface $workspaceProjectJunctionRepo,
        private readonly EntityRepositoryInterface $workspaceRepoJunctionRepo,
        private readonly EntityRepositoryInterface $projectRepo,
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

        $workspaces = $this->workspaceRepo->findBy(['tenant_id' => $this->tenantId], ['created_at' => 'DESC'], $limit);

        $items = [];
        foreach ($workspaces as $workspace) {
            $items[] = [
                'uuid' => $workspace->get('uuid'),
                'name' => $workspace->get('name'),
                'description' => $workspace->get('description'),
                'status' => $workspace->get('status'),
                'mode' => $workspace->get('mode'),
                'saved_context' => $workspace->get('saved_context'),
                'account_id' => $workspace->get('account_id'),
                'created_at' => $workspace->get('created_at'),
                'updated_at' => $workspace->get('updated_at'),
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

        $workspace = new Workspace([
            'name' => $name,
            'description' => $body['description'] ?? '',
            'status' => $body['status'] ?? 'active',
            'mode' => $body['mode'] ?? 'persistent',
            'saved_context' => $body['saved_context'] ?? null,
            'account_id' => $body['account_id'] ?? null,
            'tenant_id' => $this->tenantId,
        ]);
        $workspace->enforceIsNew();
        $this->workspaceRepo->save($workspace);

        return new SsrResponse(
            content: json_encode([
                'uuid' => $workspace->get('uuid'),
                'name' => $workspace->get('name'),
                'description' => $workspace->get('description'),
                'status' => $workspace->get('status'),
                'mode' => $workspace->get('mode'),
                'saved_context' => $workspace->get('saved_context'),
                'account_id' => $workspace->get('account_id'),
                'tenant_id' => $workspace->get('tenant_id'),
                'created_at' => $workspace->get('created_at'),
                'updated_at' => $workspace->get('updated_at'),
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
            return $this->jsonError('Workspace UUID required', 400);
        }

        $results = $this->workspaceRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        if ($results === []) {
            return $this->jsonError('Workspace not found', 404);
        }

        $workspace = $results[0];

        return $this->jsonResponse([
            'uuid' => $workspace->get('uuid'),
            'name' => $workspace->get('name'),
            'description' => $workspace->get('description'),
            'status' => $workspace->get('status'),
            'mode' => $workspace->get('mode'),
            'saved_context' => $workspace->get('saved_context'),
            'account_id' => $workspace->get('account_id'),
            'tenant_id' => $workspace->get('tenant_id'),
            'created_at' => $workspace->get('created_at'),
            'updated_at' => $workspace->get('updated_at'),
        ]);
    }

    public function update(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return $this->jsonError('Workspace UUID required', 400);
        }

        $body = $this->getRequestBody($httpRequest);
        if ($body === null) {
            return $this->jsonError('Invalid request body', 400);
        }

        $results = $this->workspaceRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        if ($results === []) {
            return $this->jsonError('Workspace not found', 404);
        }

        $workspace = $results[0];

        if (isset($body['name'])) {
            $workspace->set('name', $body['name']);
        }
        if (isset($body['description'])) {
            $workspace->set('description', $body['description']);
        }
        if (isset($body['status'])) {
            $workspace->set('status', $body['status']);
        }
        if (isset($body['mode'])) {
            $workspace->set('mode', $body['mode']);
        }
        if (isset($body['saved_context'])) {
            $workspace->set('saved_context', $body['saved_context']);
        }

        $this->workspaceRepo->save($workspace);

        return $this->jsonResponse([
            'uuid' => $workspace->get('uuid'),
            'name' => $workspace->get('name'),
            'description' => $workspace->get('description'),
            'status' => $workspace->get('status'),
            'mode' => $workspace->get('mode'),
            'saved_context' => $workspace->get('saved_context'),
            'account_id' => $workspace->get('account_id'),
            'tenant_id' => $workspace->get('tenant_id'),
            'created_at' => $workspace->get('created_at'),
            'updated_at' => $workspace->get('updated_at'),
        ]);
    }

    public function delete(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return $this->jsonError('Workspace UUID required', 400);
        }

        $results = $this->workspaceRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        if ($results === []) {
            return $this->jsonError('Workspace not found', 404);
        }

        $this->workspaceRepo->delete($results[0]);

        return new SsrResponse(
            content: '',
            statusCode: 204,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    public function listProjects(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return $this->jsonError('Workspace UUID required', 400);
        }

        $workspaces = $this->workspaceRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        if ($workspaces === []) {
            return $this->jsonError('Workspace not found', 404);
        }

        $junctions = $this->workspaceProjectJunctionRepo->findBy(['workspace_uuid' => $uuid]);

        $projects = [];
        foreach ($junctions as $junction) {
            $projectUuid = $junction->get('project_uuid');
            $projectResults = $this->projectRepo->findBy(['uuid' => $projectUuid, 'tenant_id' => $this->tenantId]);
            if ($projectResults !== []) {
                $project = $projectResults[0];
                $projects[] = [
                    'uuid' => $project->get('uuid'),
                    'name' => $project->get('name'),
                    'description' => $project->get('description'),
                    'status' => $project->get('status'),
                ];
            }
        }

        return $this->jsonResponse(['data' => $projects, 'count' => count($projects)]);
    }

    public function linkProject(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return $this->jsonError('Workspace UUID required', 400);
        }

        $body = $this->getRequestBody($httpRequest);
        if ($body === null) {
            return $this->jsonError('Invalid request body', 400);
        }

        $projectUuid = $body['project_uuid'] ?? '';
        if ($projectUuid === '') {
            return $this->jsonError('project_uuid is required', 400);
        }

        $workspaces = $this->workspaceRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        if ($workspaces === []) {
            return $this->jsonError('Workspace not found', 404);
        }

        $projects = $this->projectRepo->findBy(['uuid' => $projectUuid, 'tenant_id' => $this->tenantId]);
        if ($projects === []) {
            return $this->jsonError('Project not found', 404);
        }

        $existing = $this->workspaceProjectJunctionRepo->findBy([
            'workspace_uuid' => $uuid,
            'project_uuid' => $projectUuid,
        ]);
        if ($existing !== []) {
            return $this->jsonError('Link already exists', 409);
        }

        $junction = new WorkspaceProject([
            'workspace_uuid' => $uuid,
            'project_uuid' => $projectUuid,
        ]);
        $junction->enforceIsNew();
        $this->workspaceProjectJunctionRepo->save($junction);

        return new SsrResponse(
            content: json_encode([
                'uuid' => $junction->get('uuid'),
                'workspace_uuid' => $uuid,
                'project_uuid' => $projectUuid,
            ], JSON_THROW_ON_ERROR),
            statusCode: 201,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    public function unlinkProject(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = $params['uuid'] ?? '';
        $projectUuid = $params['project_uuid'] ?? '';
        if ($uuid === '' || $projectUuid === '') {
            return $this->jsonError('Workspace UUID and project UUID required', 400);
        }

        $junctions = $this->workspaceProjectJunctionRepo->findBy([
            'workspace_uuid' => $uuid,
            'project_uuid' => $projectUuid,
        ]);
        if ($junctions === []) {
            return $this->jsonError('Link not found', 404);
        }

        $this->workspaceProjectJunctionRepo->delete($junctions[0]);

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
            return $this->jsonError('Workspace UUID required', 400);
        }

        $workspaces = $this->workspaceRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        if ($workspaces === []) {
            return $this->jsonError('Workspace not found', 404);
        }

        $junctions = $this->workspaceRepoJunctionRepo->findBy(['workspace_uuid' => $uuid]);

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
                    'is_active' => $junction->get('is_active'),
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
            return $this->jsonError('Workspace UUID required', 400);
        }

        $body = $this->getRequestBody($httpRequest);
        if ($body === null) {
            return $this->jsonError('Invalid request body', 400);
        }

        $repoUuid = $body['repo_uuid'] ?? '';
        if ($repoUuid === '') {
            return $this->jsonError('repo_uuid is required', 400);
        }

        $workspaces = $this->workspaceRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        if ($workspaces === []) {
            return $this->jsonError('Workspace not found', 404);
        }

        $repos = $this->repoRepo->findBy(['uuid' => $repoUuid, 'tenant_id' => $this->tenantId]);
        if ($repos === []) {
            return $this->jsonError('Repo not found', 404);
        }

        $existing = $this->workspaceRepoJunctionRepo->findBy([
            'workspace_uuid' => $uuid,
            'repo_uuid' => $repoUuid,
        ]);
        if ($existing !== []) {
            return $this->jsonError('Link already exists', 409);
        }

        $junction = new WorkspaceRepo([
            'workspace_uuid' => $uuid,
            'repo_uuid' => $repoUuid,
        ]);
        $junction->enforceIsNew();
        $this->workspaceRepoJunctionRepo->save($junction);

        return new SsrResponse(
            content: json_encode([
                'uuid' => $junction->get('uuid'),
                'workspace_uuid' => $uuid,
                'repo_uuid' => $repoUuid,
                'is_active' => $junction->get('is_active'),
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
            return $this->jsonError('Workspace UUID and repo UUID required', 400);
        }

        $junctions = $this->workspaceRepoJunctionRepo->findBy([
            'workspace_uuid' => $uuid,
            'repo_uuid' => $repoUuid,
        ]);
        if ($junctions === []) {
            return $this->jsonError('Link not found', 404);
        }

        $this->workspaceRepoJunctionRepo->delete($junctions[0]);

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

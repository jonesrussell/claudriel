<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Domain\CodeTask\CodeTaskRunner;
use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\CodeTask;
use Claudriel\Entity\Repo;
use Claudriel\Entity\Workspace;
use Claudriel\Entity\WorkspaceRepo;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\SSR\SsrResponse;

final class InternalCodeTaskController
{
    public function __construct(
        private readonly EntityRepositoryInterface $codeTaskRepo,
        private readonly EntityRepositoryInterface $workspaceRepo,
        private readonly EntityRepositoryInterface $repoRepo,
        private readonly EntityRepositoryInterface $workspaceRepoRepo,
        private readonly InternalApiTokenGenerator $apiTokenGenerator,
        private readonly CodeTaskRunner $runner,
    ) {}

    public function create(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $body = $this->getRequestBody($httpRequest);
        if ($body === null) {
            return $this->jsonError('Invalid request body', 400);
        }

        $repoFullName = (string) ($body['repo'] ?? '');
        $prompt = (string) ($body['prompt'] ?? '');
        if ($repoFullName === '' || $prompt === '') {
            return $this->jsonError('repo and prompt are required', 400);
        }

        $tenantId = $this->resolveTenantId($httpRequest);

        // Find or create workspace + repo
        $accountId = $this->resolveAccountId($httpRequest);
        try {
            $workspaceUuid = $this->resolveOrCreateWorkspace($repoFullName, $tenantId, $accountId);
        } catch (\RuntimeException) {
            return $this->jsonError('Failed to set up workspace for repo', 500);
        }

        $repoUuid = $this->resolveRepoUuid($repoFullName, $tenantId);
        if ($repoUuid === null) {
            return $this->jsonError('Failed to resolve repo', 500);
        }

        $branchName = (string) ($body['branch_name'] ?? '');
        if ($branchName === '') {
            $branchName = $this->runner->generateBranchName($prompt);
        }

        $task = new CodeTask([
            'workspace_uuid' => $workspaceUuid,
            'repo_uuid' => $repoUuid,
            'prompt' => $prompt,
            'status' => 'queued',
            'branch_name' => $branchName,
            'tenant_id' => $tenantId,
        ]);
        $this->codeTaskRepo->save($task);

        $taskUuid = (string) $task->get('uuid');

        // Dispatch background command
        $projectRoot = $_ENV['CLAUDRIEL_ROOT'] ?? getenv('CLAUDRIEL_ROOT') ?: dirname(__DIR__, 2);
        $consolePath = $projectRoot.'/bin/console';
        $cmd = sprintf(
            'php %s claudriel:code-task:run %s > /dev/null 2>&1 &',
            escapeshellarg($consolePath),
            escapeshellarg($taskUuid),
        );
        exec($cmd);

        return $this->jsonResponse([
            'task_uuid' => $taskUuid,
            'status' => 'queued',
            'branch_name' => $branchName,
        ]);
    }

    public function status(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = (string) ($params['uuid'] ?? '');
        if ($uuid === '') {
            return $this->jsonError('Task UUID required', 400);
        }

        $tenantId = $this->resolveTenantId($httpRequest);
        $tasks = $this->codeTaskRepo->findBy(['uuid' => $uuid]);
        if ($tasks === []) {
            return $this->jsonError('Code task not found', 404);
        }

        $task = $tasks[0];
        if (! $task instanceof CodeTask) {
            return $this->jsonError('Code task not found', 404);
        }

        $taskTenant = (string) ($task->get('tenant_id') ?? '');
        if ($taskTenant !== '' && $taskTenant !== $tenantId) {
            return $this->jsonError('Code task not found', 404);
        }

        return $this->jsonResponse([
            'uuid' => $task->get('uuid'),
            'status' => $task->get('status'),
            'branch_name' => $task->get('branch_name'),
            'pr_url' => $task->get('pr_url'),
            'summary' => $task->get('summary'),
            'diff_preview' => $task->get('diff_preview'),
            'error' => $task->get('error'),
            'started_at' => $task->get('started_at'),
            'completed_at' => $task->get('completed_at'),
        ]);
    }

    private function resolveOrCreateWorkspace(string $repoFullName, string $tenantId, ?string $accountId = null): string
    {
        // Check if we have a repo entity for this full name
        $repos = $this->repoRepo->findBy(['full_name' => $repoFullName, 'tenant_id' => $tenantId]);
        if ($repos !== []) {
            $repo = $repos[0];
            if ($repo instanceof Repo) {
                // Find linked workspace
                $links = $this->workspaceRepoRepo->findBy(['repo_uuid' => $repo->get('uuid')]);
                foreach ($links as $link) {
                    if ($link instanceof WorkspaceRepo) {
                        return (string) $link->get('workspace_uuid');
                    }
                }
            }
        }

        // Create workspace for this repo
        $workspace = new Workspace([
            'name' => $repoFullName,
            'description' => 'Auto-created for code tasks on '.$repoFullName,
            'account_id' => $accountId,
            'tenant_id' => $tenantId,
            'status' => 'active',
        ]);
        $this->workspaceRepo->save($workspace);
        $wsUuid = (string) $workspace->get('uuid');

        // Clone the repo
        $gitManager = new GitRepositoryManager;
        $repoUrl = 'https://github.com/'.$repoFullName.'.git';
        $localPath = $gitManager->buildWorkspaceRepoPath($wsUuid);
        $gitManager->clone($repoUrl, $localPath);

        // Create repo entity
        $parts = explode('/', $repoFullName, 2);
        $repoEntity = new Repo([
            'owner' => $parts[0],
            'name' => $parts[1] ?? '',
            'full_name' => $repoFullName,
            'default_branch' => 'main',
            'local_path' => $localPath,
            'tenant_id' => $tenantId,
        ]);
        $this->repoRepo->save($repoEntity);

        // Link workspace to repo
        $link = new WorkspaceRepo([
            'workspace_uuid' => $wsUuid,
            'repo_uuid' => (string) $repoEntity->get('uuid'),
            'is_active' => true,
        ]);
        $this->workspaceRepoRepo->save($link);

        return $wsUuid;
    }

    private function resolveRepoUuid(string $repoFullName, string $tenantId): ?string
    {
        $repos = $this->repoRepo->findBy(['full_name' => $repoFullName, 'tenant_id' => $tenantId]);
        if ($repos === []) {
            return null;
        }

        $repo = $repos[0];

        return $repo instanceof Repo ? (string) $repo->get('uuid') : null;
    }

    private function resolveAccountId(mixed $httpRequest): ?string
    {
        if ($httpRequest instanceof Request) {
            $accountId = $httpRequest->headers->get('X-Account-Id', '');
            if ($accountId !== '') {
                return $accountId;
            }
        }

        return null;
    }

    private function resolveTenantId(mixed $httpRequest): string
    {
        if ($httpRequest instanceof Request) {
            $headerTenant = $httpRequest->headers->get('X-Tenant-Id', '');
            if ($headerTenant !== '') {
                return $headerTenant;
            }
        }

        return 'default';
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

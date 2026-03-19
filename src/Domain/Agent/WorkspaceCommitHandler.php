<?php

declare(strict_types=1);

namespace Claudriel\Domain\Agent;

use Claudriel\Domain\Git\GitOperator;
use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class WorkspaceCommitHandler
{
    public function __construct(
        private readonly EntityRepositoryInterface $workspaceRepo,
        private readonly GitOperator $gitOperator,
    ) {}

    /**
     * @return array{commit_hash: string, workspace_uuid: string}
     */
    public function commit(string $workspaceUuid, string $message): array
    {
        $context = WorkspaceExecutionContext::forWorkspace($workspaceUuid, $this->workspaceRepo);

        if ($context->repoPath === null || ! is_dir($context->repoPath)) {
            throw new \RuntimeException(sprintf('Workspace %s has no valid repo_path', $workspaceUuid));
        }

        $commitHash = $this->gitOperator->commit($context->repoPath, $message);
        $this->updateLastCommitHash($workspaceUuid, $commitHash);

        return [
            'commit_hash' => $commitHash,
            'workspace_uuid' => $workspaceUuid,
        ];
    }

    /**
     * @return array{commit_hash: string, workspace_uuid: string, pushed: true}
     */
    public function commitAndPush(string $workspaceUuid, string $message): array
    {
        $context = WorkspaceExecutionContext::forWorkspace($workspaceUuid, $this->workspaceRepo);

        if ($context->repoPath === null || ! is_dir($context->repoPath)) {
            throw new \RuntimeException(sprintf('Workspace %s has no valid repo_path', $workspaceUuid));
        }

        $commitHash = $this->gitOperator->commit($context->repoPath, $message);
        $this->gitOperator->push($context->repoPath, $context->branch);
        $this->updateLastCommitHash($workspaceUuid, $commitHash);

        return [
            'commit_hash' => $commitHash,
            'workspace_uuid' => $workspaceUuid,
            'pushed' => true,
        ];
    }

    private function updateLastCommitHash(string $workspaceUuid, string $commitHash): void
    {
        /** @var ContentEntityInterface[] $workspaces */
        $workspaces = $this->workspaceRepo->findBy([]);
        foreach ($workspaces as $workspace) {
            if ((string) ($workspace->get('uuid') ?? '') === $workspaceUuid) {
                $workspace->set('last_commit_hash', $commitHash);
                $this->workspaceRepo->save($workspace);

                return;
            }
        }
    }
}

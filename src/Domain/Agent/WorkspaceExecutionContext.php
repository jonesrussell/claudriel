<?php

declare(strict_types=1);

namespace Claudriel\Domain\Agent;

use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class WorkspaceExecutionContext
{
    private function __construct(
        public readonly string $workspaceUuid,
        public readonly ?string $repoPath,
        public readonly string $branch,
        public readonly ?string $projectUuid,
    ) {}

    public static function forWorkspace(string $workspaceUuid, EntityRepositoryInterface $workspaceRepo): self
    {
        /** @var ContentEntityInterface[] $workspaces */
        $workspaces = $workspaceRepo->findBy([]);
        $workspace = null;
        foreach ($workspaces as $candidate) {
            if ((string) ($candidate->get('uuid') ?? '') === $workspaceUuid) {
                $workspace = $candidate;
                break;
            }
        }

        if ($workspace === null) {
            throw new \RuntimeException(sprintf('Workspace not found: %s', $workspaceUuid));
        }

        return new self(
            workspaceUuid: $workspaceUuid,
            repoPath: $workspace->get('repo_path'),
            branch: (string) ($workspace->get('branch') ?? 'main'),
            projectUuid: $workspace->get('project_id'),
        );
    }

    public function toPromptContext(): string
    {
        $lines = [
            '## Workspace Execution Context',
            sprintf('Workspace: %s', $this->workspaceUuid),
            sprintf('Branch: %s', $this->branch),
        ];

        if ($this->repoPath !== null) {
            $lines[] = sprintf('Repository: %s', $this->repoPath);
        }

        if ($this->projectUuid !== null) {
            $lines[] = sprintf('Project: %s', $this->projectUuid);
        }

        return implode("\n", $lines);
    }
}

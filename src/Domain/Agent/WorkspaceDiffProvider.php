<?php

declare(strict_types=1);

namespace Claudriel\Domain\Agent;

use Claudriel\Domain\Git\GitOperator;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class WorkspaceDiffProvider
{
    public function __construct(
        private readonly EntityRepositoryInterface $workspaceRepo,
        private readonly GitOperator $gitOperator,
    ) {}

    public function getDiff(string $workspaceUuid): ?string
    {
        $context = WorkspaceExecutionContext::forWorkspace($workspaceUuid, $this->workspaceRepo);

        if ($context->repoPath === null || ! is_dir($context->repoPath)) {
            return null;
        }

        try {
            $diff = $this->gitOperator->diff($context->repoPath);

            return trim($diff) !== '' ? $diff : null;
        } catch (\RuntimeException) {
            return null;
        }
    }
}

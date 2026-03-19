<?php

declare(strict_types=1);

namespace Claudriel\Domain\Git;

use Claudriel\Entity\Workspace;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * Checks all workspaces with connected repositories for git drift.
 *
 * Returns a list of drifted workspaces with their drift details,
 * suitable for integration into DayBrief or temporal notifications.
 */
final class DriftAlertService
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly DriftDetector $driftDetector,
    ) {}

    /**
     * Check all workspaces with repositories for drift.
     *
     * @return array<int, array{workspace_uuid: string, workspace_name: string, drift: array{is_drifted: bool, commits_behind: int, commits_ahead: int, last_fetched_at: string}}>
     */
    public function checkAllWorkspaces(): array
    {
        $storage = $this->entityTypeManager->getStorage('workspace');
        $all = $storage->loadMultiple();

        $drifted = [];

        foreach ($all as $entity) {
            if (! $entity instanceof Workspace) {
                continue;
            }

            $repoPath = trim((string) ($entity->get('repo_path') ?? ''));
            $branch = trim((string) ($entity->get('branch') ?? 'main'));

            if ($repoPath === '' || ! is_dir($repoPath.'/.git')) {
                continue;
            }

            try {
                $driftResult = $this->driftDetector->detectDrift($repoPath, $branch);
            } catch (\RuntimeException) {
                continue;
            }

            if ($driftResult->isDrifted) {
                $drifted[] = [
                    'workspace_uuid' => (string) $entity->get('uuid'),
                    'workspace_name' => (string) $entity->get('name'),
                    'drift' => $driftResult->toArray(),
                ];
            }
        }

        return $drifted;
    }
}

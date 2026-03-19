<?php

declare(strict_types=1);

namespace Claudriel\Domain\Agent;

use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class ProjectContextProvider
{
    public function __construct(
        private readonly EntityRepositoryInterface $projectRepo,
        private readonly EntityRepositoryInterface $workspaceRepo,
        private readonly EntityRepositoryInterface $eventRepo,
    ) {}

    /**
     * @return array{uuid: string, name: string, description: string, status: string, settings: array<string, mixed>, workspaces: list<array{uuid: string, name: string, repo_path: ?string, branch: string, status: string}>, recent_events: list<array{type: string, source: string, occurred: string}>}
     */
    public function getContext(string $projectUuid): array
    {
        /** @var ContentEntityInterface[] $projects */
        $projects = $this->projectRepo->findBy([]);
        $project = null;
        foreach ($projects as $candidate) {
            if ((string) ($candidate->get('uuid') ?? '') === $projectUuid) {
                $project = $candidate;
                break;
            }
        }

        if ($project === null) {
            throw new \RuntimeException(sprintf('Project not found: %s', $projectUuid));
        }

        $settings = json_decode((string) ($project->get('settings') ?? '{}'), true);

        /** @var ContentEntityInterface[] $allWorkspaces */
        $allWorkspaces = $this->workspaceRepo->findBy([]);
        $linkedWorkspaces = [];
        foreach ($allWorkspaces as $ws) {
            if ((string) ($ws->get('project_id') ?? '') === $projectUuid) {
                $linkedWorkspaces[] = [
                    'uuid' => (string) ($ws->get('uuid') ?? ''),
                    'name' => (string) ($ws->get('name') ?? ''),
                    'repo_path' => $ws->get('repo_path'),
                    'branch' => (string) ($ws->get('branch') ?? 'main'),
                    'status' => (string) ($ws->get('status') ?? 'active'),
                ];
            }
        }

        /** @var ContentEntityInterface[] $allEvents */
        $allEvents = $this->eventRepo->findBy([]);
        $cutoff = new \DateTimeImmutable('-7 days');
        $recentEvents = [];
        foreach ($allEvents as $event) {
            $wsId = (string) ($event->get('workspace_id') ?? '');
            $wsUuids = array_column($linkedWorkspaces, 'uuid');
            if (! in_array($wsId, $wsUuids, true)) {
                continue;
            }

            $occurred = $event->get('occurred');
            if (is_string($occurred) && $occurred !== '') {
                try {
                    $dt = new \DateTimeImmutable($occurred);
                    if ($dt < $cutoff) {
                        continue;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }

            $recentEvents[] = [
                'type' => (string) ($event->get('type') ?? ''),
                'source' => (string) ($event->get('source') ?? ''),
                'occurred' => (string) ($occurred ?? ''),
            ];
        }

        usort($recentEvents, fn (array $a, array $b): int => $b['occurred'] <=> $a['occurred']);

        return [
            'uuid' => $projectUuid,
            'name' => (string) ($project->get('name') ?? ''),
            'description' => (string) ($project->get('description') ?? ''),
            'status' => (string) ($project->get('status') ?? 'active'),
            'settings' => is_array($settings) ? $settings : [],
            'workspaces' => $linkedWorkspaces,
            'recent_events' => array_slice($recentEvents, 0, 20),
        ];
    }
}

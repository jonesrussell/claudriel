<?php

declare(strict_types=1);

namespace Claudriel\Domain\Workspace;

use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\Workspace;
use Waaseyaa\Entity\EntityTypeManager;

final class WorkspaceLifecycleManager
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly GitRepositoryManager $gitRepositoryManager,
    ) {}

    /**
     * Create a workspace with optional git repository cloning.
     *
     * @param  array<string,mixed>  $data
     */
    public function create(array $data): Workspace
    {
        $mode = $data['mode'] ?? 'persistent';
        if (! in_array($mode, ['persistent', 'ephemeral'], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid workspace mode: %s', $mode));
        }

        $data['mode'] = $mode;
        $data['status'] = $data['status'] ?? 'active';

        $workspace = new Workspace($data);
        $storage = $this->entityTypeManager->getStorage('workspace');
        $storage->save($workspace);

        $repoUrl = trim((string) ($data['repo_url'] ?? ''));
        if ($repoUrl !== '') {
            $uuid = (string) $workspace->get('uuid');
            $localPath = $this->gitRepositoryManager->buildWorkspaceRepoPath($uuid);
            $branch = trim((string) ($data['branch'] ?? 'main'));

            $this->gitRepositoryManager->clone($repoUrl, $localPath, $branch);

            $workspace->set('repo_path', $localPath);
            $workspace->set('last_commit_hash', $this->gitRepositoryManager->getLatestCommit($localPath));
            $storage->save($workspace);
        }

        return $workspace;
    }

    /**
     * Archive a workspace (sets status to archived).
     */
    public function archive(string $uuid): void
    {
        $workspace = $this->loadByUuid($uuid);
        $workspace->set('status', 'archived');
        $this->entityTypeManager->getStorage('workspace')->save($workspace);
    }

    /**
     * Restore an archived workspace (sets status to active).
     */
    public function restore(string $uuid): void
    {
        $workspace = $this->loadByUuid($uuid);

        if ($workspace->get('status') !== 'archived') {
            throw new \RuntimeException(sprintf('Workspace %s is not archived (current status: %s)', $uuid, (string) $workspace->get('status')));
        }

        $workspace->set('status', 'active');
        $this->entityTypeManager->getStorage('workspace')->save($workspace);
    }

    /**
     * Destroy an ephemeral workspace. Refuses to destroy persistent workspaces.
     * Cleans up local git repository if present.
     */
    public function destroy(string $uuid): void
    {
        $workspace = $this->loadByUuid($uuid);

        if ($workspace->get('mode') !== 'ephemeral') {
            throw new \RuntimeException(sprintf('Cannot destroy persistent workspace %s. Archive it instead.', $uuid));
        }

        $repoPath = trim((string) ($workspace->get('repo_path') ?? ''));
        if ($repoPath !== '' && is_dir($repoPath)) {
            $this->removeDirectory($repoPath);
        }

        $this->entityTypeManager->getStorage('workspace')->delete([$workspace]);
    }

    /**
     * Compute current status from git state.
     *
     * @return 'active'|'archived'|'drifted'|'syncing'
     */
    public function computeStatus(string $uuid): string
    {
        $workspace = $this->loadByUuid($uuid);
        $currentStatus = (string) $workspace->get('status');

        if ($currentStatus === 'archived') {
            return 'archived';
        }

        if ($currentStatus === 'syncing') {
            return 'syncing';
        }

        if ($this->isDrifted($workspace)) {
            return 'drifted';
        }

        return 'active';
    }

    /**
     * Check if local workspace is behind remote.
     */
    public function isDrifted(Workspace $workspace): bool
    {
        $repoPath = trim((string) ($workspace->get('repo_path') ?? ''));
        if ($repoPath === '' || ! is_dir($repoPath.'/.git')) {
            return false;
        }

        try {
            $localHash = $this->gitRepositoryManager->getLatestCommit($repoPath);
            $storedHash = trim((string) ($workspace->get('last_commit_hash') ?? ''));

            if ($storedHash === '' || $localHash === $storedHash) {
                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Auto-sync a persistent workspace by pulling latest changes.
     *
     * Only works for persistent mode workspaces with a connected repository.
     * Returns true if a sync (git pull) was performed.
     */
    public function autoSync(string $uuid): bool
    {
        $workspace = $this->loadByUuid($uuid);

        if ($workspace->get('mode') !== 'persistent') {
            return false;
        }

        $repoPath = trim((string) ($workspace->get('repo_path') ?? ''));
        if ($repoPath === '' || ! is_dir($repoPath.'/.git')) {
            return false;
        }

        if ($this->isSyncing($workspace)) {
            return false;
        }

        try {
            $this->gitRepositoryManager->pull($repoPath);
            $newHash = $this->gitRepositoryManager->getLatestCommit($repoPath);
            $workspace->set('last_commit_hash', $newHash);
            $this->entityTypeManager->getStorage('workspace')->save($workspace);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Rebuild an ephemeral workspace by destroying and re-cloning.
     *
     * Only works for ephemeral mode workspaces with a connected repository.
     * Triggered when drift exceeds threshold or manually.
     */
    public function rebuild(string $uuid): void
    {
        $workspace = $this->loadByUuid($uuid);

        if ($workspace->get('mode') !== 'ephemeral') {
            throw new \RuntimeException(sprintf('Cannot rebuild persistent workspace %s. Use autoSync instead.', $uuid));
        }

        $repoUrl = trim((string) ($workspace->get('repo_url') ?? ''));
        if ($repoUrl === '') {
            throw new \RuntimeException(sprintf('Workspace %s has no repository URL configured.', $uuid));
        }

        $branch = trim((string) ($workspace->get('branch') ?? 'main'));
        $repoPath = trim((string) ($workspace->get('repo_path') ?? ''));

        if ($repoPath === '') {
            $repoPath = $this->gitRepositoryManager->buildWorkspaceRepoPath($uuid);
        }

        // Remove existing clone
        if (is_dir($repoPath)) {
            $this->removeDirectory($repoPath);
        }

        // Re-clone
        $this->gitRepositoryManager->clone($repoUrl, $repoPath, $branch);

        $newHash = $this->gitRepositoryManager->getLatestCommit($repoPath);
        $workspace->set('repo_path', $repoPath);
        $workspace->set('last_commit_hash', $newHash);
        $this->entityTypeManager->getStorage('workspace')->save($workspace);
    }

    /**
     * Check if a git operation is in progress (lock file exists).
     */
    public function isSyncing(Workspace $workspace): bool
    {
        $repoPath = trim((string) ($workspace->get('repo_path') ?? ''));
        if ($repoPath === '') {
            return false;
        }

        return file_exists($repoPath.'/.git/index.lock');
    }

    private function loadByUuid(string $uuid): Workspace
    {
        $storage = $this->entityTypeManager->getStorage('workspace');
        $all = $storage->loadMultiple();

        foreach ($all as $entity) {
            if ($entity instanceof Workspace && $entity->get('uuid') === $uuid) {
                return $entity;
            }
        }

        throw new \RuntimeException(sprintf('Workspace not found: %s', $uuid));
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($path);
    }
}

<?php

declare(strict_types=1);

namespace Claudriel\Domain\Git;

/**
 * Detects git-level drift between a local repository and its remote.
 *
 * This is distinct from the commitment DriftDetector in Support\DriftDetector,
 * which checks for stale commitments. This class checks for git commit divergence.
 */
final class DriftDetector
{
    /** @var callable(string): array{exit_code:int,output:string} */
    private readonly mixed $runner;

    public function __construct(?callable $runner = null)
    {
        $this->runner = $runner ?? $this->defaultRunner(...);
    }

    /**
     * Detect drift between local HEAD and the remote tracking branch.
     *
     * Runs `git fetch` first, then compares local vs remote using rev-list.
     */
    public function detectDrift(string $repoPath, string $branch = 'main'): DriftResult
    {
        $this->assertRepoPath($repoPath);

        // Fetch latest from remote (non-fatal if it fails, e.g. offline)
        $fetchedAt = new \DateTimeImmutable;
        try {
            $this->run(sprintf(
                'git -C %s fetch origin %s --quiet',
                escapeshellarg($repoPath),
                escapeshellarg($branch),
            ));
        } catch (\RuntimeException) {
            // Fetch failed (offline, auth issue, etc.) — still check local state
        }

        // Commits the local branch is behind the remote
        $behind = 0;
        try {
            $behindOutput = trim($this->run(sprintf(
                'git -C %s rev-list HEAD..origin/%s --count',
                escapeshellarg($repoPath),
                escapeshellarg($branch),
            )));
            $behind = (int) $behindOutput;
        } catch (\RuntimeException) {
            // Remote branch may not exist locally yet
        }

        // Commits the local branch is ahead of the remote
        $ahead = 0;
        try {
            $aheadOutput = trim($this->run(sprintf(
                'git -C %s rev-list origin/%s..HEAD --count',
                escapeshellarg($repoPath),
                escapeshellarg($branch),
            )));
            $ahead = (int) $aheadOutput;
        } catch (\RuntimeException) {
            // Remote branch may not exist locally yet
        }

        return new DriftResult(
            isDrifted: $behind > 0 || $ahead > 0,
            commitsBehind: $behind,
            commitsAhead: $ahead,
            lastFetchedAt: $fetchedAt,
        );
    }

    private function assertRepoPath(string $repoPath): void
    {
        if ($repoPath === '' || ! is_dir($repoPath.'/.git')) {
            throw new \RuntimeException(sprintf('Git repository not found at: %s', $repoPath));
        }
    }

    private function run(string $command): string
    {
        $result = ($this->runner)($command);
        $exitCode = (int) ($result['exit_code'] ?? 1);
        $output = (string) ($result['output'] ?? '');

        if ($exitCode !== 0) {
            throw new \RuntimeException(trim($output) !== '' ? trim($output) : sprintf('Command failed: %s', $command));
        }

        return $output;
    }

    /**
     * @return array{exit_code:int,output:string}
     */
    private function defaultRunner(string $command): array
    {
        $marker = '__CLAUDRIEL_DRIFT_EXIT_CODE__';
        $fullCommand = $command.' 2>&1; printf "\n'.$marker.'%s" "$?"';
        $output = shell_exec($fullCommand);

        if ($output === null) {
            return ['exit_code' => 1, 'output' => 'shell_exec returned null'];
        }

        $pos = strrpos($output, $marker);
        if ($pos === false) {
            return ['exit_code' => 1, 'output' => trim($output)];
        }

        return [
            'exit_code' => (int) trim(substr($output, $pos + strlen($marker))),
            'output' => substr($output, 0, $pos),
        ];
    }
}

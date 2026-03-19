<?php

declare(strict_types=1);

namespace Claudriel\Domain\Git;

final class DriftResult
{
    public function __construct(
        public readonly bool $isDrifted,
        public readonly int $commitsBehind,
        public readonly int $commitsAhead,
        public readonly \DateTimeImmutable $lastFetchedAt,
    ) {}

    /**
     * @return array{is_drifted: bool, commits_behind: int, commits_ahead: int, last_fetched_at: string}
     */
    public function toArray(): array
    {
        return [
            'is_drifted' => $this->isDrifted,
            'commits_behind' => $this->commitsBehind,
            'commits_ahead' => $this->commitsAhead,
            'last_fetched_at' => $this->lastFetchedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}

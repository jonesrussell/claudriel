<?php

declare(strict_types=1);

namespace Claudriel\Temporal;

final class TemporalAwarenessEngine
{
    public function __construct(
        private readonly int $overrunGraceSeconds = 1800,
    ) {}

    /**
     * @param  list<array{title: mixed, start_time: mixed, end_time: mixed, source?: mixed}>  $schedule
     * @return array{
     *   current_block: ?array{title: string, start_time: string, end_time: string, source: string},
     *   next_block: ?array{title: string, start_time: string, end_time: string, source: string},
     *   gaps: list<array{starts_at: string, ends_at: string, duration_minutes: int, between: array{from: string, to: string}}>,
     *   overruns: list<array{title: string, ended_at: string, overrun_minutes: int}>
     * }
     */
    public function analyze(array $schedule, TimeSnapshot $snapshot): array
    {
        $entries = $this->normalizeSchedule($schedule);
        $now = $snapshot->local();
        $currentBlock = null;
        $nextBlock = null;
        $gaps = [];
        $overruns = [];

        foreach ($entries as $index => $entry) {
            $start = new \DateTimeImmutable($entry['start_time']);
            $end = new \DateTimeImmutable($entry['end_time']);

            if ($start <= $now && $end > $now) {
                $currentBlock = $entry;
            }

            if ($start > $now && $nextBlock === null) {
                $nextBlock = $entry;
            }

            if ($index === 0) {
                continue;
            }

            $previous = $entries[$index - 1];
            $previousEnd = new \DateTimeImmutable($previous['end_time']);
            if ($start > $previousEnd) {
                $gapSeconds = $start->getTimestamp() - $previousEnd->getTimestamp();
                $gaps[] = [
                    'starts_at' => $previousEnd->format(\DateTimeInterface::ATOM),
                    'ends_at' => $start->format(\DateTimeInterface::ATOM),
                    'duration_minutes' => (int) floor($gapSeconds / 60),
                    'between' => [
                        'from' => $previous['title'],
                        'to' => $entry['title'],
                    ],
                ];
            }
        }

        if ($currentBlock === null) {
            $previousBlock = $this->findPreviousBlock($entries, $now);
            if ($previousBlock !== null) {
                $previousEnd = new \DateTimeImmutable($previousBlock['end_time']);
                $overrunSeconds = $now->getTimestamp() - $previousEnd->getTimestamp();
                if ($overrunSeconds > 0 && $overrunSeconds <= $this->overrunGraceSeconds) {
                    $overruns[] = [
                        'title' => $previousBlock['title'],
                        'ended_at' => $previousEnd->format(\DateTimeInterface::ATOM),
                        'overrun_minutes' => (int) floor($overrunSeconds / 60),
                    ];
                }
            }
        }

        return [
            'current_block' => $currentBlock,
            'next_block' => $nextBlock,
            'gaps' => $gaps,
            'overruns' => $overruns,
        ];
    }

    /**
     * @param  list<array{title: mixed, start_time: mixed, end_time: mixed, source?: mixed}>  $schedule
     * @return list<array{title: string, start_time: string, end_time: string, source: string}>
     */
    private function normalizeSchedule(array $schedule): array
    {
        $normalized = [];

        foreach ($schedule as $item) {
            if (! is_string($item['title'] ?? null) || ! is_string($item['start_time'] ?? null) || ! is_string($item['end_time'] ?? null)) {
                continue;
            }

            try {
                $start = new \DateTimeImmutable($item['start_time']);
                $end = new \DateTimeImmutable($item['end_time']);
            } catch (\Throwable) {
                continue;
            }

            if ($end <= $start) {
                continue;
            }

            $normalized[] = [
                'title' => $item['title'],
                'start_time' => $start->format(\DateTimeInterface::ATOM),
                'end_time' => $end->format(\DateTimeInterface::ATOM),
                'source' => is_string($item['source'] ?? null) ? $item['source'] : 'unknown',
            ];
        }

        usort($normalized, static fn (array $left, array $right): int => strcmp($left['start_time'], $right['start_time']));

        return $normalized;
    }

    /**
     * @param  list<array{title: string, start_time: string, end_time: string, source: string}>  $entries
     * @return ?array{title: string, start_time: string, end_time: string, source: string}
     */
    private function findPreviousBlock(array $entries, \DateTimeImmutable $now): ?array
    {
        $previous = null;

        foreach ($entries as $entry) {
            $end = new \DateTimeImmutable($entry['end_time']);
            if ($end <= $now) {
                $previous = $entry;
            }
        }

        return $previous;
    }
}

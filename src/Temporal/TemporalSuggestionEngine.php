<?php

declare(strict_types=1);

namespace Claudriel\Temporal;

final class TemporalSuggestionEngine
{
    /**
     * @param  array{
     *   current_block: ?array{title: string, start_time: string, end_time: string, source: string},
     *   next_block: ?array{title: string, start_time: string, end_time: string, source: string},
     *   gaps: list<array{starts_at: string, ends_at: string, duration_minutes: int, between: array{from: string, to: string}}>,
     *   overruns: list<array{title: string, ended_at: string, overrun_minutes: int}>
     * }  $awareness
     * @return list<array{type: string, title: string, summary: string}>
     */
    public function suggest(array $awareness, TimeSnapshot $snapshot): array
    {
        $suggestions = [];
        $now = $snapshot->local();
        $current = $awareness['current_block'] ?? null;
        $next = $awareness['next_block'] ?? null;
        $gaps = $awareness['gaps'];
        $overruns = $awareness['overruns'];

        if (is_array($current)) {
            $currentEnd = new \DateTimeImmutable($current['end_time']);
            $minutesRemaining = (int) floor(($currentEnd->getTimestamp() - $now->getTimestamp()) / 60);
            if ($minutesRemaining <= 15 && $minutesRemaining >= 0) {
                $suggestions[] = [
                    'type' => 'wrap_up',
                    'title' => 'Wrap up current block',
                    'summary' => sprintf('Current block "%s" ends in %d minutes.', $current['title'], $minutesRemaining),
                ];
            }
        }

        if (is_array($next)) {
            $nextStart = new \DateTimeImmutable($next['start_time']);
            $minutesUntil = (int) floor(($nextStart->getTimestamp() - $now->getTimestamp()) / 60);
            if ($minutesUntil >= 0 && $minutesUntil <= 30) {
                $suggestions[] = [
                    'type' => 'prep',
                    'title' => 'Prepare for next block',
                    'summary' => sprintf('"%s" starts in %d minutes.', $next['title'], $minutesUntil),
                ];
            }
        }

        foreach ($gaps as $gap) {
            if ($gap['duration_minutes'] < 30) {
                continue;
            }

            $gapStart = new \DateTimeImmutable($gap['starts_at']);
            $gapEnd = new \DateTimeImmutable($gap['ends_at']);
            if ($gapStart <= $now && $gapEnd > $now) {
                $suggestions[] = [
                    'type' => 'shift',
                    'title' => 'Use the open gap intentionally',
                    'summary' => sprintf(
                        'You have a %d minute gap between "%s" and "%s".',
                        $gap['duration_minutes'],
                        $gap['between']['from'],
                        $gap['between']['to'],
                    ),
                ];
                break;
            }
        }

        foreach ($overruns as $overrun) {
            if ($overrun['overrun_minutes'] < 5) {
                continue;
            }

            $suggestions[] = [
                'type' => 'shift',
                'title' => 'Resolve the schedule overrun',
                'summary' => sprintf(
                    '"%s" has overrun by %d minutes.',
                    $overrun['title'],
                    $overrun['overrun_minutes'],
                ),
            ];
            break;
        }

        return array_values(array_unique($suggestions, SORT_REGULAR));
    }
}

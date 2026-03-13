<?php

declare(strict_types=1);

namespace Claudriel\Temporal;

final class RelativeScheduleQueryService
{
    /**
     * @param  list<array{title: mixed, start_time: mixed, end_time: mixed, source?: mixed}>  $schedule
     * @return array{
     *   schedule: list<array{title: string, start_time: string, end_time: string, source: string}>,
     *   schedule_summary: string
     * }
     */
    public function filter(array $schedule, TimeSnapshot $snapshot): array
    {
        $now = $snapshot->local();
        $filtered = [];

        foreach ($schedule as $item) {
            if (! is_string($item['title'] ?? null) || ! is_string($item['start_time'] ?? null) || ! is_string($item['end_time'] ?? null)) {
                continue;
            }

            try {
                $end = new \DateTimeImmutable($item['end_time']);
                $start = new \DateTimeImmutable($item['start_time']);
            } catch (\Throwable) {
                continue;
            }

            if ($end <= $now) {
                continue;
            }

            $filtered[] = [
                'title' => $item['title'],
                'start_time' => $start->format(\DateTimeInterface::ATOM),
                'end_time' => $end->format(\DateTimeInterface::ATOM),
                'source' => is_string($item['source'] ?? null) ? $item['source'] : 'unknown',
            ];
        }

        usort($filtered, static fn (array $left, array $right): int => strcmp($left['start_time'], $right['start_time']));

        return [
            'schedule' => $filtered,
            'schedule_summary' => $filtered === [] ? 'Your day is clear' : '',
        ];
    }
}

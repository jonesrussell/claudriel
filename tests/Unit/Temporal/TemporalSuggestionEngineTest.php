<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Temporal;

use Claudriel\Temporal\TemporalSuggestionEngine;
use Claudriel\Temporal\TimeSnapshot;
use PHPUnit\Framework\TestCase;

final class TemporalSuggestionEngineTest extends TestCase
{
    public function test_generates_prep_wrap_up_and_gap_suggestions(): void
    {
        $engine = new TemporalSuggestionEngine;
        $snapshot = new TimeSnapshot(
            new \DateTimeImmutable('2026-03-14T14:20:00+00:00'),
            new \DateTimeImmutable('2026-03-14T10:20:00-04:00'),
            1000,
            'America/Toronto',
        );

        $suggestions = $engine->suggest([
            'current_block' => [
                'title' => 'Standup',
                'start_time' => '2026-03-14T09:30:00-04:00',
                'end_time' => '2026-03-14T10:30:00-04:00',
                'source' => 'google-calendar',
            ],
            'next_block' => [
                'title' => 'Client Call',
                'start_time' => '2026-03-14T10:40:00-04:00',
                'end_time' => '2026-03-14T11:30:00-04:00',
                'source' => 'google-calendar',
            ],
            'gaps' => [[
                'starts_at' => '2026-03-14T10:30:00-04:00',
                'ends_at' => '2026-03-14T11:15:00-04:00',
                'duration_minutes' => 45,
                'between' => [
                    'from' => 'Standup',
                    'to' => 'Client Call',
                ],
            ]],
            'overruns' => [],
        ], $snapshot);

        self::assertSame(['wrap_up', 'prep'], array_column($suggestions, 'type'));
    }

    public function test_generates_shift_suggestion_for_meaningful_overrun(): void
    {
        $engine = new TemporalSuggestionEngine;
        $snapshot = new TimeSnapshot(
            new \DateTimeImmutable('2026-03-14T15:15:00+00:00'),
            new \DateTimeImmutable('2026-03-14T11:15:00-04:00'),
            1000,
            'America/Toronto',
        );

        $suggestions = $engine->suggest([
            'current_block' => null,
            'next_block' => null,
            'gaps' => [],
            'overruns' => [[
                'title' => 'Design Review',
                'ended_at' => '2026-03-14T11:00:00-04:00',
                'overrun_minutes' => 15,
            ]],
        ], $snapshot);

        self::assertCount(1, $suggestions);
        self::assertSame('shift', $suggestions[0]['type']);
        self::assertStringContainsString('Design Review', $suggestions[0]['summary']);
    }

    public function test_suppresses_irrelevant_suggestions(): void
    {
        $engine = new TemporalSuggestionEngine;
        $snapshot = new TimeSnapshot(
            new \DateTimeImmutable('2026-03-14T14:20:00+00:00'),
            new \DateTimeImmutable('2026-03-14T10:20:00-04:00'),
            1000,
            'America/Toronto',
        );

        $suggestions = $engine->suggest([
            'current_block' => null,
            'next_block' => [
                'title' => 'Evening Review',
                'start_time' => '2026-03-14T17:00:00-04:00',
                'end_time' => '2026-03-14T18:00:00-04:00',
                'source' => 'google-calendar',
            ],
            'gaps' => [],
            'overruns' => [[
                'title' => 'Quick Sync',
                'ended_at' => '2026-03-14T10:15:00-04:00',
                'overrun_minutes' => 2,
            ]],
        ], $snapshot);

        self::assertSame([], $suggestions);
    }
}

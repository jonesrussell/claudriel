<?php

declare(strict_types=1);

namespace Tests\Unit\Temporal;

use Claudriel\Temporal\TemporalAwarenessEngine;
use Claudriel\Temporal\TimeSnapshot;
use PHPUnit\Framework\TestCase;

final class TemporalAwarenessEngineTest extends TestCase
{
    public function test_detects_current_block_next_block_and_gaps(): void
    {
        $engine = new TemporalAwarenessEngine;
        $snapshot = new TimeSnapshot(
            new \DateTimeImmutable('2026-03-14T14:15:00+00:00'),
            new \DateTimeImmutable('2026-03-14T10:15:00-04:00'),
            1000,
            'America/Toronto',
        );

        $analysis = $engine->analyze([
            [
                'title' => 'Morning Standup',
                'start_time' => '2026-03-14T09:00:00-04:00',
                'end_time' => '2026-03-14T10:30:00-04:00',
                'source' => 'google-calendar',
            ],
            [
                'title' => 'Client Call',
                'start_time' => '2026-03-14T11:00:00-04:00',
                'end_time' => '2026-03-14T12:00:00-04:00',
                'source' => 'google-calendar',
            ],
        ], $snapshot);

        self::assertSame('Morning Standup', $analysis['current_block']['title']);
        self::assertSame('Client Call', $analysis['next_block']['title']);
        self::assertCount(1, $analysis['gaps']);
        self::assertSame(30, $analysis['gaps'][0]['duration_minutes']);
    }

    public function test_detects_recent_overrun_when_now_just_passed_event_end(): void
    {
        $engine = new TemporalAwarenessEngine(overrunGraceSeconds: 1800);
        $snapshot = new TimeSnapshot(
            new \DateTimeImmutable('2026-03-14T15:10:00+00:00'),
            new \DateTimeImmutable('2026-03-14T11:10:00-04:00'),
            1000,
            'America/Toronto',
        );

        $analysis = $engine->analyze([
            [
                'title' => 'Design Review',
                'start_time' => '2026-03-14T10:00:00-04:00',
                'end_time' => '2026-03-14T11:00:00-04:00',
                'source' => 'google-calendar',
            ],
        ], $snapshot);

        self::assertNull($analysis['current_block']);
        self::assertCount(1, $analysis['overruns']);
        self::assertSame('Design Review', $analysis['overruns'][0]['title']);
        self::assertSame(10, $analysis['overruns'][0]['overrun_minutes']);
    }
}

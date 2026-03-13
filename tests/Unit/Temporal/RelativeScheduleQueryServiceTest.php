<?php

declare(strict_types=1);

namespace Tests\Unit\Temporal;

use Claudriel\Temporal\RelativeScheduleQueryService;
use Claudriel\Temporal\TimeSnapshot;
use PHPUnit\Framework\TestCase;

final class RelativeScheduleQueryServiceTest extends TestCase
{
    public function test_filters_schedule_relative_to_now_using_end_time(): void
    {
        $service = new RelativeScheduleQueryService;
        $snapshot = new TimeSnapshot(
            new \DateTimeImmutable('2026-03-14T14:15:00+00:00'),
            new \DateTimeImmutable('2026-03-14T10:15:00-04:00'),
            1000,
            'America/Toronto',
        );

        $result = $service->filter([
            [
                'title' => 'Past Block',
                'start_time' => '2026-03-14T08:00:00-04:00',
                'end_time' => '2026-03-14T09:00:00-04:00',
                'source' => 'google-calendar',
            ],
            [
                'title' => 'Current Block',
                'start_time' => '2026-03-14T09:30:00-04:00',
                'end_time' => '2026-03-14T10:30:00-04:00',
                'source' => 'google-calendar',
            ],
            [
                'title' => 'Next Block',
                'start_time' => '2026-03-14T11:00:00-04:00',
                'end_time' => '2026-03-14T12:00:00-04:00',
                'source' => 'google-calendar',
            ],
        ], $snapshot);

        self::assertSame(['Current Block', 'Next Block'], array_column($result['schedule'], 'title'));
        self::assertSame('', $result['schedule_summary']);
    }

    public function test_returns_clear_day_message_when_no_future_events_remain(): void
    {
        $service = new RelativeScheduleQueryService;
        $snapshot = new TimeSnapshot(
            new \DateTimeImmutable('2026-03-14T20:15:00+00:00'),
            new \DateTimeImmutable('2026-03-14T16:15:00-04:00'),
            1000,
            'America/Toronto',
        );

        $result = $service->filter([
            [
                'title' => 'Morning Standup',
                'start_time' => '2026-03-14T09:00:00-04:00',
                'end_time' => '2026-03-14T09:30:00-04:00',
                'source' => 'google-calendar',
            ],
        ], $snapshot);

        self::assertSame([], $result['schedule']);
        self::assertSame('Your day is clear', $result['schedule_summary']);
    }
}

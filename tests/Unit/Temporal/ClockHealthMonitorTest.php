<?php

declare(strict_types=1);

namespace Tests\Unit\Temporal;

use Claudriel\Temporal\AtomicTimeService;
use Claudriel\Temporal\Clock\MonotonicClockInterface;
use Claudriel\Temporal\Clock\WallClockInterface;
use Claudriel\Temporal\ClockHealthMonitor;
use Claudriel\Temporal\ClockSyncProbeInterface;
use Claudriel\Temporal\ClockSyncStatus;
use Claudriel\Temporal\RequestTimeSnapshotStore;
use PHPUnit\Framework\TestCase;

final class ClockHealthMonitorTest extends TestCase
{
    public function test_reports_healthy_clock_when_sync_and_drift_are_within_threshold(): void
    {
        $monitor = new ClockHealthMonitor(
            timeService: new AtomicTimeService(
                wallClock: new FixedWallClock('2026-03-14T12:00:00+00:00'),
                monotonicClock: new FixedMonotonicClock(1000),
                snapshotStore: new RequestTimeSnapshotStore,
            ),
            syncProbe: new FixedClockSyncProbe(new ClockSyncStatus('timedatectl', true, ['ntp_synchronized' => 'true'])),
            referenceClock: new FixedWallClock('2026-03-14T12:00:03+00:00'),
        );

        $health = $monitor->assess('ntp');

        self::assertSame('healthy', $health['state']);
        self::assertTrue($health['safe_for_temporal_reasoning']);
        self::assertSame(3.0, $health['drift_seconds']);
        self::assertSame('none', $health['fallback_mode']);
    }

    public function test_reports_unsafe_clock_when_probe_is_unsynchronized(): void
    {
        $monitor = new ClockHealthMonitor(
            timeService: new AtomicTimeService(
                wallClock: new FixedWallClock('2026-03-14T12:00:00+00:00'),
                monotonicClock: new FixedMonotonicClock(1000),
                snapshotStore: new RequestTimeSnapshotStore,
            ),
            syncProbe: new FixedClockSyncProbe(new ClockSyncStatus('os', false, ['fallback' => 'No sync provider available'])),
            referenceClock: new FixedWallClock('2026-03-14T12:00:00+00:00'),
        );

        $health = $monitor->assess('os');

        self::assertSame('unsafe', $health['state']);
        self::assertFalse($health['safe_for_temporal_reasoning']);
        self::assertSame('wall-clock-only', $health['fallback_mode']);
        self::assertSame(30, $health['retry_after_seconds']);
    }

    public function test_reports_unsafe_clock_when_drift_exceeds_threshold(): void
    {
        $monitor = new ClockHealthMonitor(
            timeService: new AtomicTimeService(
                wallClock: new FixedWallClock('2026-03-14T12:00:00+00:00'),
                monotonicClock: new FixedMonotonicClock(1000),
                snapshotStore: new RequestTimeSnapshotStore,
            ),
            syncProbe: new FixedClockSyncProbe(new ClockSyncStatus('timedatectl', true)),
            referenceClock: new FixedWallClock('2026-03-14T12:00:12+00:00'),
            unsafeDriftThresholdSeconds: 5,
            retryAfterSeconds: 45,
        );

        $health = $monitor->assess('ntp');

        self::assertSame('unsafe', $health['state']);
        self::assertSame(12.0, $health['drift_seconds']);
        self::assertSame(45, $health['retry_after_seconds']);
    }
}

final class FixedClockSyncProbe implements ClockSyncProbeInterface
{
    public function __construct(private readonly ClockSyncStatus $status) {}

    public function read(): ClockSyncStatus
    {
        return $this->status;
    }
}

final class FixedWallClock implements WallClockInterface
{
    public function __construct(private readonly string $timestamp) {}

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->timestamp);
    }
}

final class FixedMonotonicClock implements MonotonicClockInterface
{
    public function __construct(private readonly int $timestamp) {}

    public function now(): int
    {
        return $this->timestamp;
    }
}

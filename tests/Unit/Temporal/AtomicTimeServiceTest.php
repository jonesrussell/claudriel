<?php

declare(strict_types=1);

namespace Tests\Unit\Temporal;

use Claudriel\Temporal\AtomicTimeService;
use Claudriel\Temporal\Clock\MonotonicClockInterface;
use Claudriel\Temporal\Clock\WallClockInterface;
use Claudriel\Temporal\RequestTimeSnapshotStore;
use PHPUnit\Framework\TestCase;

final class AtomicTimeServiceTest extends TestCase
{
    public function test_exposes_monotonic_and_wall_clock_time(): void
    {
        $service = new AtomicTimeService(
            wallClock: new SequenceWallClock([
                new \DateTimeImmutable('2026-03-14T12:00:00+00:00'),
            ]),
            monotonicClock: new SequenceMonotonicClock([1000]),
            snapshotStore: new RequestTimeSnapshotStore,
            defaultTimezone: 'America/Toronto',
        );

        self::assertSame(1000, $service->monotonicNow());
        self::assertSame('2026-03-14T08:00:00-04:00', $service->wallNow()->format(\DateTimeInterface::ATOM));
    }

    public function test_now_snapshot_is_deterministic_within_scope(): void
    {
        $service = new AtomicTimeService(
            wallClock: new SequenceWallClock([
                new \DateTimeImmutable('2026-03-14T12:00:00+00:00'),
                new \DateTimeImmutable('2026-03-14T12:00:05+00:00'),
            ]),
            monotonicClock: new SequenceMonotonicClock([1000, 2000]),
            snapshotStore: new RequestTimeSnapshotStore,
            defaultTimezone: 'America/Toronto',
        );

        $first = $service->now('request-123');
        $second = $service->now('request-123');

        self::assertSame($first->toArray(), $second->toArray());
        self::assertSame('2026-03-14T12:00:00+00:00', $first->utc()->format(\DateTimeInterface::ATOM));
        self::assertSame('America/Toronto', $first->timezone());
    }

    public function test_now_snapshot_can_be_recaptured_after_scope_reset(): void
    {
        $service = new AtomicTimeService(
            wallClock: new SequenceWallClock([
                new \DateTimeImmutable('2026-03-14T12:00:00+00:00'),
                new \DateTimeImmutable('2026-03-14T12:00:05+00:00'),
            ]),
            monotonicClock: new SequenceMonotonicClock([1000, 2000]),
            snapshotStore: new RequestTimeSnapshotStore,
            defaultTimezone: 'UTC',
        );

        $first = $service->now('request-123');
        $service->resetScope('request-123');
        $second = $service->now('request-123');

        self::assertNotSame($first->toArray(), $second->toArray());
        self::assertSame(2000, $second->monotonicNanoseconds());
        self::assertSame('2026-03-14T12:00:05+00:00', $second->utc()->format(\DateTimeInterface::ATOM));
    }

    public function test_snapshot_includes_utc_and_local_representations(): void
    {
        $service = new AtomicTimeService(
            wallClock: new SequenceWallClock([
                new \DateTimeImmutable('2026-06-01T15:30:00+00:00'),
            ]),
            monotonicClock: new SequenceMonotonicClock([123456]),
            snapshotStore: new RequestTimeSnapshotStore,
        );

        $snapshot = $service->now(timezone: new \DateTimeZone('America/Los_Angeles'));

        self::assertSame('2026-06-01T15:30:00+00:00', $snapshot->utc()->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-06-01T08:30:00-07:00', $snapshot->local()->format(\DateTimeInterface::ATOM));
        self::assertSame([
            'utc' => '2026-06-01T15:30:00+00:00',
            'local' => '2026-06-01T08:30:00-07:00',
            'timezone' => 'America/Los_Angeles',
            'monotonic_ns' => 123456,
        ], $snapshot->toArray());
    }
}

final class SequenceWallClock implements WallClockInterface
{
    /** @param list<\DateTimeImmutable> $sequence */
    public function __construct(private array $sequence) {}

    public function now(): \DateTimeImmutable
    {
        if ($this->sequence === []) {
            throw new \RuntimeException('Wall clock sequence exhausted.');
        }

        return array_shift($this->sequence);
    }
}

final class SequenceMonotonicClock implements MonotonicClockInterface
{
    /** @param list<int> $sequence */
    public function __construct(private array $sequence) {}

    public function now(): int
    {
        if ($this->sequence === []) {
            throw new \RuntimeException('Monotonic clock sequence exhausted.');
        }

        return array_shift($this->sequence);
    }
}

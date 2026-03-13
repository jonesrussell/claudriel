<?php

declare(strict_types=1);

namespace Claudriel\Temporal;

use Claudriel\Temporal\Clock\MonotonicClockInterface;
use Claudriel\Temporal\Clock\SystemMonotonicClock;
use Claudriel\Temporal\Clock\SystemWallClock;
use Claudriel\Temporal\Clock\WallClockInterface;

final class AtomicTimeService
{
    private readonly \DateTimeZone $defaultTimezone;

    public function __construct(
        private readonly ?WallClockInterface $wallClock = null,
        private readonly ?MonotonicClockInterface $monotonicClock = null,
        private readonly ?RequestTimeSnapshotStore $snapshotStore = null,
        string $defaultTimezone = 'UTC',
    ) {
        $this->defaultTimezone = new \DateTimeZone($defaultTimezone);
    }

    public function monotonicNow(): int
    {
        return $this->monotonicClock()->now();
    }

    public function wallNow(?\DateTimeZone $timezone = null): \DateTimeImmutable
    {
        return $this->wallClock()->now()->setTimezone($timezone ?? $this->defaultTimezone);
    }

    public function now(?string $scopeKey = null, ?\DateTimeZone $timezone = null): TimeSnapshot
    {
        $targetTimezone = $timezone ?? $this->defaultTimezone;

        if ($scopeKey === null) {
            return $this->captureSnapshot($targetTimezone);
        }

        return $this->snapshotStore()->remember(
            $this->snapshotScopeKey($scopeKey, $targetTimezone),
            fn (): TimeSnapshot => $this->captureSnapshot($targetTimezone),
        );
    }

    public function resetScope(?string $scopeKey = null, ?\DateTimeZone $timezone = null): void
    {
        if ($scopeKey === null) {
            $this->snapshotStore()->reset();

            return;
        }

        $this->snapshotStore()->forget($this->snapshotScopeKey($scopeKey, $timezone ?? $this->defaultTimezone));
    }

    private function captureSnapshot(\DateTimeZone $timezone): TimeSnapshot
    {
        $utc = $this->wallClock()->now()->setTimezone(new \DateTimeZone('UTC'));

        return new TimeSnapshot(
            capturedAtUtc: $utc,
            capturedAtLocal: $utc->setTimezone($timezone),
            monotonicNanoseconds: $this->monotonicClock()->now(),
            timezone: $timezone->getName(),
        );
    }

    private function snapshotScopeKey(string $scopeKey, \DateTimeZone $timezone): string
    {
        return $scopeKey.'|'.$timezone->getName();
    }

    private function wallClock(): WallClockInterface
    {
        return $this->wallClock ?? new SystemWallClock;
    }

    private function monotonicClock(): MonotonicClockInterface
    {
        return $this->monotonicClock ?? new SystemMonotonicClock;
    }

    private function snapshotStore(): RequestTimeSnapshotStore
    {
        return $this->snapshotStore ?? new RequestTimeSnapshotStore;
    }
}

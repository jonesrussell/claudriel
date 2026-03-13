<?php

declare(strict_types=1);

namespace Claudriel\Temporal;

use Claudriel\Temporal\Clock\WallClockInterface;

final class ClockHealthMonitor
{
    public function __construct(
        private readonly AtomicTimeService $timeService,
        private readonly ClockSyncProbeInterface $syncProbe,
        private readonly WallClockInterface $referenceClock,
        private readonly int $unsafeDriftThresholdSeconds = 5,
        private readonly int $retryAfterSeconds = 30,
    ) {}

    /**
     * @return array{
     *   provider: string,
     *   synchronized: bool,
     *   reference_source: string,
     *   drift_seconds: float,
     *   threshold_seconds: int,
     *   state: string,
     *   safe_for_temporal_reasoning: bool,
     *   retry_after_seconds: int,
     *   fallback_mode: string,
     *   metadata: array<string, scalar|null>
     * }
     */
    public function assess(string $referenceSource = 'reference-clock'): array
    {
        $sync = $this->syncProbe->read();
        $appNow = $this->timeService->wallNow(new \DateTimeZone('UTC'));
        $referenceNow = $this->referenceClock->now()->setTimezone(new \DateTimeZone('UTC'));
        $driftSeconds = (float) abs($referenceNow->getTimestamp() - $appNow->getTimestamp());
        $unsafe = ! $sync->synchronized() || $driftSeconds > $this->unsafeDriftThresholdSeconds;

        return [
            'provider' => $sync->provider(),
            'synchronized' => $sync->synchronized(),
            'reference_source' => $referenceSource,
            'drift_seconds' => $driftSeconds,
            'threshold_seconds' => $this->unsafeDriftThresholdSeconds,
            'state' => $unsafe ? 'unsafe' : 'healthy',
            'safe_for_temporal_reasoning' => ! $unsafe,
            'retry_after_seconds' => $this->retryAfterSeconds,
            'fallback_mode' => $unsafe ? 'wall-clock-only' : 'none',
            'metadata' => $sync->metadata(),
        ];
    }
}

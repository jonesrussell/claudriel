<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Temporal\Agent;

use Claudriel\Temporal\Agent\TemporalAgentContextBuilder;
use Claudriel\Temporal\TimeSnapshot;
use PHPUnit\Framework\TestCase;

final class TemporalAgentContextBuilderTest extends TestCase
{
    public function test_build_derives_canonical_context_from_snapshot_clock_health_and_schedule(): void
    {
        $snapshot = $this->buildSnapshot('2026-03-13T14:10:00+00:00');
        $context = (new TemporalAgentContextBuilder)->build(
            tenantId: 'tenant-123',
            workspaceUuid: 'workspace-a',
            snapshot: $snapshot,
            clockHealth: $this->clockHealth(),
            schedule: [
                [
                    'title' => 'Standup',
                    'start_time' => '2026-03-13T09:30:00-04:00',
                    'end_time' => '2026-03-13T10:30:00-04:00',
                    'source' => 'google-calendar',
                ],
                [
                    'title' => 'Planning',
                    'start_time' => '2026-03-13T11:00:00-04:00',
                    'end_time' => '2026-03-13T12:00:00-04:00',
                    'source' => 'google-calendar',
                ],
            ],
        )->toArray();

        self::assertSame('tenant-123', $context['tenant_id']);
        self::assertSame('workspace-a', $context['workspace_uuid']);
        self::assertSame('America/Toronto', $context['timezone_context']['timezone']);
        self::assertSame('time_snapshot', $context['timezone_context']['source']);
        self::assertSame('Standup', $context['temporal_awareness']['current_block']['title']);
        self::assertSame('Planning', $context['temporal_awareness']['next_block']['title']);
        self::assertFalse($context['schedule_metadata']['has_clear_day']);
    }

    public function test_build_prefers_explicit_awareness_schedule_semantics_and_timezone_context(): void
    {
        $snapshot = $this->buildSnapshot('2026-03-13T14:10:00+00:00');
        $context = (new TemporalAgentContextBuilder)->build(
            tenantId: 'tenant-123',
            workspaceUuid: null,
            snapshot: $snapshot,
            clockHealth: $this->clockHealth(),
            schedule: [],
            temporalAwareness: [
                'current_block' => null,
                'next_block' => null,
                'gaps' => [],
                'overruns' => [[
                    'title' => 'Planning',
                    'ended_at' => '2026-03-13T10:00:00-04:00',
                    'overrun_minutes' => 10,
                ]],
            ],
            relativeSchedule: [
                'schedule' => [],
                'schedule_summary' => 'Your day is clear',
            ],
            timezoneContext: [
                'timezone' => 'America/New_York',
                'source' => 'workspace',
            ],
        )->toArray();

        self::assertSame('America/New_York', $context['timezone_context']['timezone']);
        self::assertSame('workspace', $context['timezone_context']['source']);
        self::assertTrue($context['schedule_metadata']['has_clear_day']);
        self::assertSame('Planning', $context['temporal_awareness']['overruns'][0]['title']);
    }

    public function test_build_marks_empty_schedule_as_clear_day(): void
    {
        $context = (new TemporalAgentContextBuilder)->build(
            tenantId: 'tenant-123',
            workspaceUuid: null,
            snapshot: $this->buildSnapshot('2026-03-13T14:10:00+00:00'),
            clockHealth: $this->clockHealth(),
            schedule: [],
        )->toArray();

        self::assertSame([], $context['schedule_metadata']['schedule']);
        self::assertSame('Your day is clear', $context['schedule_metadata']['schedule_summary']);
        self::assertTrue($context['schedule_metadata']['has_clear_day']);
    }

    private function buildSnapshot(string $utcTime): TimeSnapshot
    {
        $utc = new \DateTimeImmutable($utcTime);

        return new TimeSnapshot(
            $utc,
            $utc->setTimezone(new \DateTimeZone('America/Toronto')),
            42,
            'America/Toronto',
        );
    }

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
    private function clockHealth(): array
    {
        return [
            'provider' => 'timedatectl',
            'synchronized' => true,
            'reference_source' => 'system-wall-clock',
            'drift_seconds' => 0.0,
            'threshold_seconds' => 5,
            'state' => 'healthy',
            'safe_for_temporal_reasoning' => true,
            'retry_after_seconds' => 30,
            'fallback_mode' => 'none',
            'metadata' => [],
        ];
    }
}

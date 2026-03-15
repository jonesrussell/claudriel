<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Temporal\Agent;

use Claudriel\Temporal\Agent\TemporalAgentContext;
use Claudriel\Temporal\Agent\TemporalAgentEvaluationLedger;
use Claudriel\Temporal\Agent\TemporalAgentOrchestrator;
use Claudriel\Temporal\Agent\TemporalAgentRegistry;
use Claudriel\Temporal\Agent\UpcomingBlockPrepAgent;
use Claudriel\Temporal\TimeSnapshot;
use PHPUnit\Framework\TestCase;

final class UpcomingBlockPrepAgentTest extends TestCase
{
    public function test_emits_prep_nudge_inside_lead_window(): void
    {
        $decision = (new UpcomingBlockPrepAgent(leadWindowMinutes: 30))->evaluate($this->buildContext(
            nowLocal: '2026-03-13T10:30:00-04:00',
            nextBlockStart: '2026-03-13T10:50:00-04:00',
            gapBeforeNextBlockMinutes: 10,
        ))->toArray();

        self::assertSame('emitted', $decision['state']);
        self::assertSame('next_block_prep_window', $decision['reason_code']);
        self::assertSame('Prepare for next block', $decision['title']);
        self::assertSame('"Planning" starts in 20 minutes at 10:50 AM. You have a 10-minute gap before it.', $decision['summary']);
        self::assertSame(10, $decision['metadata']['gap_before_minutes']);
        self::assertSame('2026-03-13T10:50:00-04:00', $decision['metadata']['next_block_starts_at']);
        self::assertSame(10, $decision['actions'][0]['payload']['gap_before_minutes']);
    }

    public function test_suppresses_when_clear_day_or_no_next_block(): void
    {
        $clearDay = (new UpcomingBlockPrepAgent)->evaluate($this->buildContext(
            nowLocal: '2026-03-13T10:30:00-04:00',
            nextBlockStart: null,
            clearDay: true,
        ))->toArray();
        $noNextBlock = (new UpcomingBlockPrepAgent)->evaluate($this->buildContext(
            nowLocal: '2026-03-13T10:30:00-04:00',
            nextBlockStart: null,
        ))->toArray();

        self::assertSame('suppressed', $clearDay['state']);
        self::assertSame('clear_day', $clearDay['reason_code']);
        self::assertSame('suppressed', $noNextBlock['state']);
        self::assertSame('no_next_block', $noNextBlock['reason_code']);
    }

    public function test_suppresses_when_next_block_is_outside_prep_window(): void
    {
        $decision = (new UpcomingBlockPrepAgent(leadWindowMinutes: 30))->evaluate($this->buildContext(
            nowLocal: '2026-03-13T10:00:00-04:00',
            nextBlockStart: '2026-03-13T11:00:00-04:00',
        ))->toArray();

        self::assertSame('suppressed', $decision['state']);
        self::assertSame('outside_prep_window', $decision['reason_code']);
    }

    public function test_suppresses_when_prep_window_has_elapsed(): void
    {
        $decision = (new UpcomingBlockPrepAgent(leadWindowMinutes: 30))->evaluate($this->buildContext(
            nowLocal: '2026-03-13T11:05:00-04:00',
            nextBlockStart: '2026-03-13T11:00:00-04:00',
        ))->toArray();

        self::assertSame('suppressed', $decision['state']);
        self::assertSame('prep_window_elapsed', $decision['reason_code']);
        self::assertSame(-5, $decision['metadata']['minutes_until']);
    }

    public function test_duplicate_prep_nudges_are_suppressed_in_same_window_by_orchestrator(): void
    {
        $context = $this->buildContext(
            nowLocal: '2026-03-13T10:30:00-04:00',
            nextBlockStart: '2026-03-13T10:50:00-04:00',
        );
        $orchestrator = new TemporalAgentOrchestrator(
            new TemporalAgentRegistry([new UpcomingBlockPrepAgent(leadWindowMinutes: 30)]),
            new TemporalAgentEvaluationLedger,
        );

        $first = $orchestrator->evaluate($context)->toArray();
        $second = $orchestrator->evaluate($context)->toArray();

        self::assertSame('emitted', $first['decisions'][0]['state']);
        self::assertSame('suppressed', $second['decisions'][0]['state']);
        self::assertSame('duplicate_within_window', $second['decisions'][0]['reason_code']);
    }

    private function buildContext(
        string $nowLocal,
        ?string $nextBlockStart,
        bool $clearDay = false,
        int $gapBeforeNextBlockMinutes = 0,
    ): TemporalAgentContext {
        $local = new \DateTimeImmutable($nowLocal);
        $utc = $local->setTimezone(new \DateTimeZone('UTC'));
        $gaps = [];

        if ($nextBlockStart !== null && $gapBeforeNextBlockMinutes > 0) {
            $nextBlock = new \DateTimeImmutable($nextBlockStart);
            $gaps[] = [
                'starts_at' => $nextBlock->modify(sprintf('-%d minutes', $gapBeforeNextBlockMinutes))->format(\DateTimeInterface::ATOM),
                'ends_at' => $nextBlock->format(\DateTimeInterface::ATOM),
                'duration_minutes' => $gapBeforeNextBlockMinutes,
                'between' => [
                    'from' => 'Deep Work',
                    'to' => 'Planning',
                ],
            ];
        }

        return new TemporalAgentContext(
            tenantId: 'tenant-123',
            workspaceUuid: 'workspace-a',
            timeSnapshot: new TimeSnapshot(
                $utc,
                $local,
                42,
                'America/Toronto',
            ),
            temporalAwareness: [
                'current_block' => null,
                'next_block' => $nextBlockStart !== null ? [
                    'title' => 'Planning',
                    'start_time' => $nextBlockStart,
                    'end_time' => (new \DateTimeImmutable($nextBlockStart))->modify('+1 hour')->format(\DateTimeInterface::ATOM),
                    'source' => 'google-calendar',
                ] : null,
                'gaps' => $gaps,
                'overruns' => [],
            ],
            clockHealth: [
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
            ],
            scheduleMetadata: [
                'schedule' => [],
                'schedule_summary' => $clearDay ? 'Your day is clear' : '',
                'has_clear_day' => $clearDay,
            ],
            timezoneContext: [
                'timezone' => 'America/Toronto',
                'source' => 'workspace',
            ],
        );
    }
}

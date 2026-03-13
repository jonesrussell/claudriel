<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Agent;

final class UpcomingBlockPrepAgent implements TemporalAgentInterface
{
    public function __construct(
        private readonly int $leadWindowMinutes = 30,
        private readonly TemporalAgentSuppressionPolicy $suppressionPolicy = new TemporalAgentSuppressionPolicy,
    ) {
        if ($this->leadWindowMinutes <= 0) {
            throw new \InvalidArgumentException('Prep lead windows must be greater than zero minutes.');
        }
    }

    public function name(): string
    {
        return 'upcoming-block-prep';
    }

    public function evaluate(TemporalAgentContext $context): TemporalAgentDecision
    {
        if ($context->scheduleMetadata()['has_clear_day']) {
            return TemporalAgentDecision::suppress(
                agentName: $this->name(),
                kind: 'nudge',
                reasonCode: 'clear_day',
                context: $context,
                suppressionPolicy: $this->suppressionPolicy,
            );
        }

        $nextBlock = $context->temporalAwareness()['next_block'] ?? null;
        if (! is_array($nextBlock)) {
            return TemporalAgentDecision::suppress(
                agentName: $this->name(),
                kind: 'nudge',
                reasonCode: 'no_next_block',
                context: $context,
                suppressionPolicy: $this->suppressionPolicy,
            );
        }

        $nextBlockStart = new \DateTimeImmutable($nextBlock['start_time']);
        $minutesUntil = (int) floor(
            ($nextBlockStart->getTimestamp() - $context->timeSnapshot()->local()->getTimestamp()) / 60,
        );

        if ($minutesUntil < 0) {
            return TemporalAgentDecision::suppress(
                agentName: $this->name(),
                kind: 'nudge',
                reasonCode: 'prep_window_elapsed',
                context: $context,
                suppressionPolicy: $this->suppressionPolicy,
                metadata: ['minutes_until' => $minutesUntil],
            );
        }

        if ($minutesUntil > $this->leadWindowMinutes) {
            return TemporalAgentDecision::suppress(
                agentName: $this->name(),
                kind: 'nudge',
                reasonCode: 'outside_prep_window',
                context: $context,
                suppressionPolicy: $this->suppressionPolicy,
                metadata: ['minutes_until' => $minutesUntil],
            );
        }

        $gapBeforeNextBlock = $this->gapBeforeNextBlock($context->temporalAwareness()['gaps'], $nextBlock);

        return TemporalAgentDecision::emit(
            agentName: $this->name(),
            kind: 'nudge',
            title: 'Prepare for next block',
            summary: $this->buildSummary($nextBlock['title'], $nextBlockStart, $minutesUntil, $gapBeforeNextBlock),
            reasonCode: 'next_block_prep_window',
            context: $context,
            suppressionPolicy: $this->suppressionPolicy,
            actions: [
                new TemporalAgentAction('open_chat', 'Prep in chat', [
                    'prompt' => sprintf('Prep me for %s at %s.', $nextBlock['title'], $nextBlockStart->format('g:i A')),
                    'event_title' => $nextBlock['title'],
                    'event_start_time' => $nextBlock['start_time'],
                    'gap_before_minutes' => $gapBeforeNextBlock['duration_minutes'] ?? null,
                ]),
            ],
            metadata: [
                'minutes_until' => $minutesUntil,
                'next_block_title' => $nextBlock['title'],
                'next_block_starts_at' => $nextBlockStart->format(\DateTimeInterface::ATOM),
                'gap_before_minutes' => $gapBeforeNextBlock['duration_minutes'] ?? null,
            ],
        );
    }

    /**
     * @param  list<array{starts_at: string, ends_at: string, duration_minutes: int, between: array{from: string, to: string}}>  $gaps
     * @param  array{title: string, start_time: string, end_time: string, source: string}  $nextBlock
     * @return ?array{starts_at: string, ends_at: string, duration_minutes: int, between: array{from: string, to: string}}
     */
    private function gapBeforeNextBlock(array $gaps, array $nextBlock): ?array
    {
        foreach ($gaps as $gap) {
            if (
                $gap['between']['to'] === $nextBlock['title']
                && $gap['ends_at'] === $nextBlock['start_time']
            ) {
                return $gap;
            }
        }

        return null;
    }

    /**
     * @param  ?array{starts_at: string, ends_at: string, duration_minutes: int, between: array{from: string, to: string}}  $gapBeforeNextBlock
     */
    private function buildSummary(
        string $nextBlockTitle,
        \DateTimeImmutable $nextBlockStart,
        int $minutesUntil,
        ?array $gapBeforeNextBlock,
    ): string {
        $summary = sprintf(
            '"%s" starts in %d minutes at %s.',
            $nextBlockTitle,
            $minutesUntil,
            $nextBlockStart->format('g:i A'),
        );

        if ($gapBeforeNextBlock === null || $gapBeforeNextBlock['duration_minutes'] <= 0) {
            return $summary;
        }

        return sprintf(
            '%s You have a %d-minute gap before it.',
            $summary,
            $gapBeforeNextBlock['duration_minutes'],
        );
    }
}

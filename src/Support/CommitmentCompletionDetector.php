<?php

declare(strict_types=1);

namespace Claudriel\Support;

use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class CommitmentCompletionDetector
{
    public function __construct(
        private readonly EntityRepositoryInterface $commitmentRepo,
        private readonly EntityRepositoryInterface $eventRepo,
    ) {}

    /** @return list<ContentEntityInterface> Commitments that appear completed */
    public function detectCompleted(string $tenantId): array
    {
        /** @var ContentEntityInterface[] $allCommitments */
        $allCommitments = $this->commitmentRepo->findBy([]);
        $inboundPending = array_filter(
            $allCommitments,
            static fn (ContentEntityInterface $c) =>
                $c->get('direction') === 'inbound'
                && $c->get('status') === 'pending'
                && $c->get('tenant_id') === $tenantId,
        );

        /** @var ContentEntityInterface[] $allEvents */
        $allEvents = $this->eventRepo->findBy([]);
        $received = array_filter($allEvents, static fn (ContentEntityInterface $e) =>
            $e->get('type') === 'message.received' && $e->get('tenant_id') === $tenantId);

        // Build map: thread_id => latest occurred timestamp
        $latestReplyByThread = [];
        foreach ($received as $event) {
            $payload = json_decode($event->get('payload') ?? '{}', true);
            $threadId = $payload['thread_id'] ?? null;
            if ($threadId === null) {
                continue;
            }
            $occurred = $event->get('occurred') ?? '';
            if (! isset($latestReplyByThread[$threadId]) || $occurred > $latestReplyByThread[$threadId]) {
                $latestReplyByThread[$threadId] = $occurred;
            }
        }

        $completed = [];
        foreach ($inboundPending as $commitment) {
            $sourceEventId = $commitment->get('source_event_id');
            if ($sourceEventId === null) {
                continue;
            }

            // Find source event
            $sourceEvent = null;
            foreach ($allEvents as $event) {
                if ((string) $event->id() === (string) $sourceEventId) {
                    $sourceEvent = $event;
                    break;
                }
            }
            if ($sourceEvent === null) {
                continue;
            }

            $sourcePayload = json_decode($sourceEvent->get('payload') ?? '{}', true);
            $threadId = $sourcePayload['thread_id'] ?? null;
            if ($threadId === null) {
                continue;
            }

            $sourceOccurred = $sourceEvent->get('occurred') ?? '';
            if (isset($latestReplyByThread[$threadId]) && $latestReplyByThread[$threadId] > $sourceOccurred) {
                $completed[] = $commitment;
            }
        }

        return $completed;
    }
}

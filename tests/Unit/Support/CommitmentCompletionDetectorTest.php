<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Support;

use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Claudriel\Support\CommitmentCompletionDetector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class CommitmentCompletionDetectorTest extends TestCase
{
    private function makeCommitmentRepo(): EntityRepository
    {
        return new EntityRepository(
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );
    }

    private function makeEventRepo(): EntityRepository
    {
        return new EntityRepository(
            new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid', 'content_hash' => 'content_hash']),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );
    }

    public function test_detects_completion_when_thread_has_follow_up(): void
    {
        $commitmentRepo = $this->makeCommitmentRepo();
        $eventRepo = $this->makeEventRepo();

        $sourceEvent = new McEvent([
            'eid' => 1,
            'type' => 'message.received',
            'payload' => json_encode(['thread_id' => 'thread-1']),
            'occurred' => '2026-03-19 10:00:00',
            'tenant_id' => 'user-1',
        ]);
        $eventRepo->save($sourceEvent);

        $commitment = new Commitment([
            'cid' => 1,
            'title' => 'Awaiting reply',
            'direction' => 'inbound',
            'status' => 'pending',
            'source_event_id' => 1,
            'tenant_id' => 'user-1',
        ]);
        $commitmentRepo->save($commitment);

        $replyEvent = new McEvent([
            'eid' => 2,
            'type' => 'message.received',
            'payload' => json_encode(['thread_id' => 'thread-1']),
            'occurred' => '2026-03-19 11:00:00',
            'tenant_id' => 'user-1',
        ]);
        $eventRepo->save($replyEvent);

        $detector = new CommitmentCompletionDetector($commitmentRepo, $eventRepo);
        $completed = $detector->detectCompleted('user-1');

        self::assertCount(1, $completed);
        self::assertSame('Awaiting reply', $completed[0]->get('title'));
    }

    public function test_ignores_outbound_commitments(): void
    {
        $commitmentRepo = $this->makeCommitmentRepo();
        $eventRepo = $this->makeEventRepo();

        $sourceEvent = new McEvent([
            'eid' => 1,
            'type' => 'message.received',
            'payload' => json_encode(['thread_id' => 'thread-1']),
            'occurred' => '2026-03-19 10:00:00',
            'tenant_id' => 'user-1',
        ]);
        $eventRepo->save($sourceEvent);

        $commitment = new Commitment([
            'cid' => 1,
            'title' => 'Outbound task',
            'direction' => 'outbound',
            'status' => 'pending',
            'source_event_id' => 1,
            'tenant_id' => 'user-1',
        ]);
        $commitmentRepo->save($commitment);

        $replyEvent = new McEvent([
            'eid' => 2,
            'type' => 'message.received',
            'payload' => json_encode(['thread_id' => 'thread-1']),
            'occurred' => '2026-03-19 11:00:00',
            'tenant_id' => 'user-1',
        ]);
        $eventRepo->save($replyEvent);

        $detector = new CommitmentCompletionDetector($commitmentRepo, $eventRepo);
        $completed = $detector->detectCompleted('user-1');

        self::assertCount(0, $completed);
    }
}

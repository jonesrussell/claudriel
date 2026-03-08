<?php

declare(strict_types=1);

namespace MyClaudia\Tests\Unit\Ingestion;

use MyClaudia\Entity\Commitment;
use MyClaudia\Entity\McEvent;
use MyClaudia\Ingestion\CommitmentHandler;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class CommitmentHandlerTest extends TestCase
{
    public function testPersistsHighConfidenceCommitments(): void
    {
        $repo = new EntityRepository(
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver(),
            new EventDispatcher(),
        );
        $handler    = new CommitmentHandler($repo);
        $event      = new McEvent(['source' => 'gmail', 'type' => 'message.received', 'payload' => '{}']);
        $candidates = [
            ['title' => 'Send report', 'confidence' => 0.92],
            ['title' => 'Maybe attend', 'confidence' => 0.4],
        ];

        $handler->handle($candidates, $event, personId: 'person-1', tenantId: 'user-1');

        $commitments = $repo->findBy([]);
        self::assertCount(1, $commitments);
        self::assertSame('Send report', $commitments[0]->get('title'));
        self::assertSame(0.92, $commitments[0]->get('confidence'));
        self::assertSame('pending', $commitments[0]->get('status'));
    }
}

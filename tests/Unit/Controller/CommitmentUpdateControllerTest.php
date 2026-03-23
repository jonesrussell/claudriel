<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\CommitmentUpdateController;
use Claudriel\Entity\Commitment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\SSR\SsrResponse;

final class CommitmentUpdateControllerTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;

    private CommitmentUpdateController $controller;

    protected function setUp(): void
    {
        $db = DBALDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher;
        $type = new EntityType(
            id: 'commitment',
            label: 'Commitment',
            class: Commitment::class,
            keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title'],
        );

        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            function ($definition) use ($db, $dispatcher): SqlEntityStorage {
                (new SqlSchemaHandler($definition, $db))->ensureTable();

                return new SqlEntityStorage($definition, $db, $dispatcher);
            },
        );
        $this->entityTypeManager->registerEntityType($type);

        $this->controller = new CommitmentUpdateController($this->entityTypeManager);
    }

    private function saveCommitment(string $uuid, string $workflowState = 'pending'): void
    {
        $c = new Commitment(['title' => 'Test', 'workflow_state' => $workflowState, 'uuid' => $uuid]);
        $this->entityTypeManager->getStorage('commitment')->save($c);
    }

    private function call(string $uuid, string $body): SsrResponse
    {
        $httpRequest = Request::create('/commitments/'.$uuid, 'PATCH', [], [], [], [], $body);

        return $this->controller->update(
            params: ['uuid' => $uuid],
            query: [],
            account: null,
            httpRequest: $httpRequest,
        );
    }

    public function test_update_workflow_state_to_completed(): void
    {
        $uuid = 'bbbbbbbb-0001-0001-0001-bbbbbbbbbbbb';
        $this->saveCommitment($uuid, 'active');

        $response = $this->call($uuid, json_encode(['workflow_state' => 'completed']));

        self::assertSame(200, $response->statusCode);
        $body = json_decode($response->content, true);
        self::assertSame('completed', $body['workflow_state']);
        self::assertSame('completed', $body['status']);
        self::assertSame($uuid, $body['uuid']);
    }

    public function test_legacy_status_field_accepted(): void
    {
        $uuid = 'bbbbbbbb-0001-0001-0001-bbbbbbbbbbbc';
        $this->saveCommitment($uuid, 'active');

        $response = $this->call($uuid, json_encode(['status' => 'completed']));

        self::assertSame(200, $response->statusCode);
        $body = json_decode($response->content, true);
        self::assertSame('completed', $body['workflow_state']);
    }

    public function test_rejects_invalid_transition(): void
    {
        $uuid = 'bbbbbbbb-0001-0001-0001-bbbbbbbbbbbd';
        $this->saveCommitment($uuid, 'pending');

        // pending -> completed is not a valid transition (must go through active).
        $response = $this->call($uuid, json_encode(['workflow_state' => 'completed']));
        self::assertSame(422, $response->statusCode);
    }

    public function test_returns404_for_unknown_uuid(): void
    {
        $response = $this->call('no-such-uuid', json_encode(['workflow_state' => 'completed']));
        self::assertSame(404, $response->statusCode);
    }

    public function test_returns422_for_invalid_status(): void
    {
        $uuid = 'bbbbbbbb-0002-0002-0002-bbbbbbbbbbbb';
        $this->saveCommitment($uuid);

        $response = $this->call($uuid, json_encode(['workflow_state' => 'exploded']));
        self::assertSame(422, $response->statusCode);
    }

    public function test_confidence_guard_blocks_low_confidence_activation(): void
    {
        $uuid = 'bbbbbbbb-0003-0003-0003-bbbbbbbbbbbb';
        $c = new Commitment(['title' => 'Low confidence', 'workflow_state' => 'pending', 'uuid' => $uuid, 'confidence' => 0.4]);
        $this->entityTypeManager->getStorage('commitment')->save($c);

        $response = $this->call($uuid, json_encode(['workflow_state' => 'active']));
        self::assertSame(422, $response->statusCode);
    }
}

<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Entity;

use Claudriel\Entity\Commitment;
use PHPUnit\Framework\TestCase;

final class CommitmentTest extends TestCase
{
    public function test_entity_type_id(): void
    {
        $c = new Commitment(['title' => 'Send report', 'status' => 'pending', 'confidence' => 0.9]);
        self::assertSame('commitment', $c->getEntityTypeId());
    }

    public function test_default_status(): void
    {
        $c = new Commitment(['title' => 'Follow up']);
        self::assertSame('pending', $c->get('status'));
    }

    public function test_default_workflow_state(): void
    {
        $c = new Commitment(['title' => 'Follow up']);
        self::assertSame('pending', $c->get('workflow_state'));
    }

    public function test_workflow_state_syncs_to_status(): void
    {
        $c = new Commitment(['title' => 'Task', 'workflow_state' => 'active']);
        self::assertSame('active', $c->get('workflow_state'));
        self::assertSame('active', $c->get('status'));
    }

    public function test_status_syncs_to_workflow_state(): void
    {
        $c = new Commitment(['title' => 'Task', 'status' => 'completed']);
        self::assertSame('completed', $c->get('workflow_state'));
        self::assertSame('completed', $c->get('status'));
    }

    public function test_confidence(): void
    {
        $c = new Commitment(['title' => 'Review PR', 'confidence' => 0.75]);
        self::assertSame(0.75, $c->get('confidence'));
    }

    public function test_commitment_defaults_to_outbound_direction(): void
    {
        $c = new Commitment(['title' => 'Follow up']);
        self::assertSame('outbound', $c->get('direction'));
    }

    public function test_commitment_accepts_inbound_direction(): void
    {
        $c = new Commitment(['title' => 'Waiting on reply', 'direction' => 'inbound']);
        self::assertSame('inbound', $c->get('direction'));
    }
}

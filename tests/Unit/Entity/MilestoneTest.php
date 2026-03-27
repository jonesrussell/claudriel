<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Entity;

use Claudriel\Entity\Milestone;
use PHPUnit\Framework\TestCase;

final class MilestoneTest extends TestCase
{
    public function test_entity_type_id(): void
    {
        $milestone = new Milestone(['name' => 'v3.0 Release']);
        self::assertSame('milestone', $milestone->getEntityTypeId());
    }

    public function test_name_can_be_set(): void
    {
        $milestone = new Milestone(['name' => 'v3.0 Release']);
        self::assertSame('v3.0 Release', $milestone->get('name'));
    }

    public function test_description_defaults_to_empty(): void
    {
        $milestone = new Milestone;
        self::assertSame('', $milestone->get('description'));
    }

    public function test_status_defaults_to_active(): void
    {
        $milestone = new Milestone;
        self::assertSame('active', $milestone->get('status'));
    }

    public function test_target_date_defaults_to_null(): void
    {
        $milestone = new Milestone;
        self::assertNull($milestone->get('target_date'));
    }

    public function test_target_date_can_be_set(): void
    {
        $milestone = new Milestone(['target_date' => '2026-06-01']);
        self::assertSame('2026-06-01', $milestone->get('target_date'));
    }
}

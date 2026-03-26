<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Entity;

use Claudriel\Entity\MergeAuditLog;
use Claudriel\Entity\MergeCandidate;
use PHPUnit\Framework\TestCase;

final class MergeEntitiesTest extends TestCase
{
    public function test_merge_candidate_defaults_status_to_pending(): void
    {
        $entity = new MergeCandidate(['uuid' => 'mc-1']);

        self::assertSame('pending', $entity->get('status'));
    }

    public function test_merge_audit_log_defaults_snapshots_to_null(): void
    {
        $entity = new MergeAuditLog(['uuid' => 'ma-1']);

        self::assertNull($entity->get('source_snapshot'));
        self::assertNull($entity->get('target_snapshot'));
        self::assertNull($entity->get('result_snapshot'));
    }
}

<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Entity\MemoryAccessEvent;
use Claudriel\Entity\MergeAuditLog;
use Claudriel\Entity\MergeCandidate;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class MemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'memory_access_event',
            label: 'Memory Access Event',
            class: MemoryAccessEvent::class,
            keys: ['id' => 'maeid', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'maeid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'entity_type' => ['type' => 'string'],
                'entity_uuid' => ['type' => 'string'],
                'tool_name' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'accessed_at' => ['type' => 'timestamp'],
                'metadata' => ['type' => 'text_long'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'merge_candidate',
            label: 'Merge Candidate',
            class: MergeCandidate::class,
            keys: ['id' => 'mcid', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'mcid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'source_entity_type' => ['type' => 'string', 'required' => true],
                'source_entity_uuid' => ['type' => 'string', 'required' => true],
                'target_entity_type' => ['type' => 'string', 'required' => true],
                'target_entity_uuid' => ['type' => 'string', 'required' => true],
                'similarity_score' => ['type' => 'float'],
                'match_reasons' => ['type' => 'text_long'],
                'status' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'merge_audit_log',
            label: 'Merge Audit Log',
            class: MergeAuditLog::class,
            keys: ['id' => 'maid', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'maid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'merge_candidate_uuid' => ['type' => 'string', 'required' => true],
                'action' => ['type' => 'string', 'required' => true],
                'source_snapshot' => ['type' => 'text_long'],
                'target_snapshot' => ['type' => 'text_long'],
                'result_snapshot' => ['type' => 'text_long'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }
}

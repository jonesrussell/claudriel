<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Entity\ScheduleEntry;
use Claudriel\Entity\TriageEntry;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class DayBriefServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'schedule_entry',
            label: 'Schedule Entry',
            class: ScheduleEntry::class,
            keys: ['id' => 'seid', 'uuid' => 'uuid', 'label' => 'title'],
            fieldDefinitions: [
                'seid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'title' => ['type' => 'string', 'required' => true],
                'starts_at' => ['type' => 'datetime', 'required' => true],
                'ends_at' => ['type' => 'datetime'],
                'notes' => ['type' => 'string'],
                'source' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'external_id' => ['type' => 'string'],
                'calendar_id' => ['type' => 'string'],
                'recurring_series_id' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'raw_payload' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'triage_entry',
            label: 'Triage Entry',
            class: TriageEntry::class,
            keys: ['id' => 'teid', 'uuid' => 'uuid', 'label' => 'sender_name'],
            fieldDefinitions: [
                'teid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'sender_name' => ['type' => 'string', 'required' => true],
                'sender_email' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'source' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'occurred_at' => ['type' => 'datetime'],
                'external_id' => ['type' => 'string'],
                'content_hash' => ['type' => 'string'],
                'raw_payload' => ['type' => 'text_long'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }
}

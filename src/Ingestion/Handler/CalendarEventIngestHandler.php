<?php

declare(strict_types=1);

namespace Claudriel\Ingestion\Handler;

use Claudriel\Entity\McEvent;
use Claudriel\Entity\ScheduleEntry;
use Claudriel\Ingestion\EventCategorizer;
use Claudriel\Ingestion\IngestHandlerInterface;
use Claudriel\Support\ContentHasher;
use Claudriel\Support\SchedulePayloadNormalizer;
use Waaseyaa\Entity\EntityTypeManagerInterface;

final class CalendarEventIngestHandler implements IngestHandlerInterface
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EventCategorizer $categorizer = new EventCategorizer,
        private readonly SchedulePayloadNormalizer $normalizer = new SchedulePayloadNormalizer,
    ) {}

    public function supports(string $type): bool
    {
        return $type === 'calendar.event';
    }

    /**
     * @param  array{source: string, type: string, payload: array<string, mixed>, timestamp?: string, tenant_id?: mixed, trace_id?: mixed}  $data
     */
    public function handle(array $data): array
    {
        $payload = $data['payload'];
        $category = $this->categorizer->categorize($data['source'], $data['type'], $payload);
        $storage = $this->entityTypeManager->getStorage('mc_event');
        $contentHash = ContentHasher::hash(array_merge($payload, [
            'source' => $data['source'],
            'type' => $data['type'],
        ]));

        $existing = $storage->getQuery()->condition('content_hash', $contentHash)->execute();
        if ($existing !== []) {
            $event = $storage->load(reset($existing));
            $scheduleUuid = null;
            if ($category === 'schedule') {
                $schedule = $this->upsertScheduleEntry(
                    $payload,
                    $data['source'],
                    $data['tenant_id'] ?? null,
                    $data['timestamp'] ?? null,
                );
                $scheduleUuid = $schedule?->uuid();
            }

            return [
                'status' => 'duplicate',
                'entity_type' => 'mc_event',
                'uuid' => $event?->uuid(),
                'schedule_uuid' => $scheduleUuid,
            ];
        }

        $event = new McEvent([
            'source' => $data['source'],
            'type' => $data['type'],
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'content_hash' => $contentHash,
            'category' => $category,
            'tenant_id' => $data['tenant_id'] ?? null,
            'trace_id' => $data['trace_id'] ?? null,
            'occurred' => $data['timestamp'] ?? date('Y-m-d H:i:s'),
        ]);
        $storage->save($event);

        $scheduleUuid = null;
        if ($category === 'schedule') {
            $schedule = $this->upsertScheduleEntry(
                $payload,
                $data['source'],
                $data['tenant_id'] ?? null,
                $data['timestamp'] ?? null,
            );
            $scheduleUuid = $schedule?->uuid();
        }

        return [
            'status' => 'created',
            'entity_type' => 'mc_event',
            'uuid' => $event->uuid(),
            'schedule_uuid' => $scheduleUuid,
        ];
    }

    private function upsertScheduleEntry(array $payload, string $source, ?string $tenantId, ?string $occurredAt): ?ScheduleEntry
    {
        $normalized = $this->normalizer->normalize($payload + ['source' => $source], $occurredAt);
        if ($normalized === null) {
            return null;
        }

        $scheduleStorage = $this->entityTypeManager->getStorage('schedule_entry');
        $entry = $this->findExistingEntry(
            $normalized['external_id'],
            $normalized['calendar_id'],
            $tenantId,
            $normalized['title'],
            $normalized['start_time'],
            $normalized['end_time'],
        );

        $entry ??= new ScheduleEntry;
        $entry->set('title', $normalized['title']);
        $entry->set('source', $source);
        $entry->set('calendar_id', $normalized['calendar_id']);
        $entry->set('external_id', $normalized['external_id']);
        $entry->set('recurring_series_id', $normalized['recurring_series_id']);
        $entry->set('starts_at', $normalized['start_time']);
        $entry->set('ends_at', $normalized['end_time']);
        $entry->set('organizer_name', $normalized['organizer_name']);
        $entry->set('organizer_email', $normalized['organizer_email']);
        $entry->set('notes', $normalized['notes']);
        $entry->set('tenant_id', $tenantId);
        $entry->set('raw_payload', json_encode($payload, JSON_THROW_ON_ERROR));

        $scheduleStorage->save($entry);

        return $entry;
    }

    private function findExistingEntry(?string $externalId, ?string $calendarId, ?string $tenantId, string $title, string $startsAt, string $endsAt): ?ScheduleEntry
    {
        $scheduleStorage = $this->entityTypeManager->getStorage('schedule_entry');

        if ($externalId !== null) {
            $ids = $scheduleStorage->getQuery()->condition('external_id', $externalId)->execute();
            if ($ids !== []) {
                $existing = $scheduleStorage->load(reset($ids));

                return $existing instanceof ScheduleEntry ? $existing : null;
            }
        }

        $query = $scheduleStorage->getQuery();
        $query->condition('title', $title);
        $query->condition('starts_at', $startsAt);
        $query->condition('ends_at', $endsAt);
        if ($calendarId !== null) {
            $query->condition('calendar_id', $calendarId);
        }
        if ($tenantId !== null) {
            $query->condition('tenant_id', $tenantId);
        }

        $ids = $query->execute();
        if ($ids === []) {
            return null;
        }

        $existing = $scheduleStorage->load(reset($ids));

        return $existing instanceof ScheduleEntry ? $existing : null;
    }
}

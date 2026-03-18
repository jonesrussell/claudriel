<?php

declare(strict_types=1);

namespace Claudriel\Domain\Schedule;

use Claudriel\Entity\ScheduleEntry;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * Scope-aware update/delete for recurring schedule entries.
 *
 * Restores domain logic from the deleted ScheduleApiController:
 * - scope=occurrence: operates on a single entry
 * - scope=series: operates on all entries sharing the same recurring_series_id
 *
 * For delete with scope=occurrence on recurring entries, soft-deletes
 * by setting status=cancelled instead of hard-deleting.
 */
final class ScheduleSeriesResolver
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolveUpdate(string $id, array $input, string $scope = 'occurrence'): array
    {
        $entry = $this->findByUuid($id);
        if ($entry === null) {
            throw new \RuntimeException("Schedule entry not found: {$id}");
        }

        $targets = $this->resolveTargets($entry, $scope);

        foreach ($targets as $target) {
            foreach ($input as $field => $value) {
                if ($field === 'scope') {
                    continue;
                }
                $target->set($field, $value);
            }
            $this->entityTypeManager->getStorage('schedule_entry')->save($target);
        }

        return $this->serialize($entry);
    }

    /**
     * @return array{deleted: bool, scope: string, affected_count: int}
     */
    public function resolveDelete(string $id, string $scope = 'occurrence'): array
    {
        $entry = $this->findByUuid($id);
        if ($entry === null) {
            throw new \RuntimeException("Schedule entry not found: {$id}");
        }

        $storage = $this->entityTypeManager->getStorage('schedule_entry');

        // Soft-delete recurring occurrences (set status=cancelled)
        if (($entry->get('recurring_series_id') ?? null) !== null && $scope !== 'series') {
            $entry->set('status', 'cancelled');
            $storage->save($entry);

            return ['deleted' => true, 'scope' => 'occurrence', 'affected_count' => 1];
        }

        // Hard-delete: single entry or full series
        $targets = $this->resolveTargets($entry, $scope);
        $storage->delete($targets);

        return ['deleted' => true, 'scope' => $scope, 'affected_count' => count($targets)];
    }

    private function findByUuid(string $uuid): ?ScheduleEntry
    {
        if ($uuid === '') {
            return null;
        }

        $storage = $this->entityTypeManager->getStorage('schedule_entry');
        $ids = $storage->getQuery()->condition('uuid', $uuid)->execute();
        if ($ids === []) {
            return null;
        }

        $entry = $storage->load(reset($ids));

        return $entry instanceof ScheduleEntry ? $entry : null;
    }

    /**
     * @return list<ScheduleEntry>
     */
    private function resolveTargets(ScheduleEntry $entry, string $scope): array
    {
        if ($scope !== 'series') {
            return [$entry];
        }

        $seriesId = $entry->get('recurring_series_id');
        if (! is_string($seriesId) || $seriesId === '') {
            return [$entry];
        }

        $storage = $this->entityTypeManager->getStorage('schedule_entry');
        $ids = $storage->getQuery()->condition('recurring_series_id', $seriesId)->execute();
        $entries = $storage->loadMultiple($ids);

        return array_values(array_filter(
            $entries,
            fn ($candidate): bool => $candidate instanceof ScheduleEntry,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ScheduleEntry $entry): array
    {
        return [
            'uuid' => $entry->get('uuid'),
            'title' => $entry->get('title'),
            'starts_at' => $entry->get('starts_at'),
            'ends_at' => $entry->get('ends_at'),
            'notes' => $entry->get('notes') ?? '',
            'source' => $entry->get('source') ?? 'manual',
            'status' => $entry->get('status') ?? 'active',
            'external_id' => $entry->get('external_id'),
            'calendar_id' => $entry->get('calendar_id'),
            'recurring_series_id' => $entry->get('recurring_series_id'),
            'tenant_id' => $entry->get('tenant_id'),
            'created_at' => $entry->get('created_at'),
            'updated_at' => $entry->get('updated_at'),
        ];
    }
}

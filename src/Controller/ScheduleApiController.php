<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Entity\ScheduleEntry;
use Claudriel\Routing\RequestScopeViolation;
use Claudriel\Routing\TenantWorkspaceResolver;
use Claudriel\Temporal\RelativeScheduleQueryService;
use Claudriel\Temporal\TemporalContextFactory;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class ScheduleApiController
{
    private const VALID_STATUSES = ['active', 'cancelled'];

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        mixed $twig = null,
    ) {
        unset($twig);
    }

    public function list(array $params = [], array $query = [], mixed $account = null): SsrResponse
    {
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        $scope = $resolver->resolve($query, $account);
        $snapshot = (new TemporalContextFactory($this->entityTypeManager))->snapshotForInteraction(
            scopeKey: 'schedule-api:'.(is_string($query['request_id'] ?? null) ? $query['request_id'] : 'list'),
            tenantId: $scope->tenantId,
            workspaceUuid: $scope->workspaceId(),
            account: $account,
            requestTimezone: is_string($query['timezone'] ?? null) ? $query['timezone'] : null,
        );
        $entries = array_values(array_filter(
            $this->loadAll(),
            function (ScheduleEntry $entry) use ($query, $resolver, $scope): bool {
                if (! $resolver->tenantMatches($entry, $scope->tenantId)) {
                    return false;
                }
                if (($entry->get('status') ?? 'active') !== 'active') {
                    return false;
                }

                $date = $query['date'] ?? 'today';
                $startsAt = (string) ($entry->get('starts_at') ?? '');
                if ($date === 'today') {
                    return str_starts_with($startsAt, (new \DateTimeImmutable('today'))->format('Y-m-d'));
                }

                if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                    return str_starts_with($startsAt, $date);
                }

                return true;
            },
        ));

        usort($entries, fn (ScheduleEntry $a, ScheduleEntry $b): int => ((string) $a->get('starts_at')) <=> ((string) $b->get('starts_at')));
        $relative = (new RelativeScheduleQueryService)->filter(
            array_map(fn (ScheduleEntry $entry): array => [
                'title' => (string) ($entry->get('title') ?? ''),
                'start_time' => (string) ($entry->get('starts_at') ?? ''),
                'end_time' => (string) ($entry->get('ends_at') ?? ''),
                'source' => (string) ($entry->get('source') ?? 'manual'),
            ], $entries),
            $snapshot,
        );

        return $this->json([
            'schedule' => $relative['schedule'],
            'schedule_summary' => $relative['schedule_summary'],
            'time_snapshot' => $snapshot->toArray(),
        ]);
    }

    public function create(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $body = json_decode($httpRequest?->getContent() ?? '', true) ?? [];
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        try {
            $scope = $resolver->resolve($query, $account, $httpRequest, $body);
            $resolver->assertPayloadTenantMatchesContext($body, $scope->tenantId);
        } catch (RequestScopeViolation $exception) {
            return $this->json(['error' => $exception->getMessage()], $exception->statusCode());
        }

        [$title, $startsAt] = $this->validateRequiredFields($body);
        if ($title === null || $startsAt === null) {
            return $this->json(['error' => 'Fields "title" and "starts_at" are required.'], 422);
        }

        $entry = new ScheduleEntry([
            'title' => $title,
            'starts_at' => $startsAt,
            'ends_at' => $this->normalizeDateTime($body['ends_at'] ?? null) ?? $this->defaultEnd($startsAt),
            'source' => 'manual',
            'notes' => is_string($body['notes'] ?? null) ? trim($body['notes']) : '',
            'status' => 'active',
            'tenant_id' => $scope->tenantId,
            'recurring_series_id' => is_string($body['recurring_series_id'] ?? null) ? $body['recurring_series_id'] : null,
        ]);

        $this->entityTypeManager->getStorage('schedule_entry')->save($entry);

        return $this->json(['schedule' => $this->serialize($entry)], 201);
    }

    public function show(array $params = [], array $query = [], mixed $account = null): SsrResponse
    {
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        $scope = $resolver->resolve($query, $account);
        $entry = $this->findByUuid((string) ($params['uuid'] ?? ''), $scope->tenantId);
        if ($entry === null) {
            return $this->json(['error' => 'Schedule entry not found.'], 404);
        }

        return $this->json(['schedule' => $this->serialize($entry)]);
    }

    public function update(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $body = json_decode($httpRequest?->getContent() ?? '', true) ?? [];
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        try {
            $requestScope = $resolver->resolve($query, $account, $httpRequest, $body);
            $resolver->assertPayloadTenantMatchesContext($body, $requestScope->tenantId);
        } catch (RequestScopeViolation $exception) {
            return $this->json(['error' => $exception->getMessage()], $exception->statusCode());
        }

        $entry = $this->findByUuid((string) ($params['uuid'] ?? ''), $requestScope->tenantId);
        if ($entry === null) {
            return $this->json(['error' => 'Schedule entry not found.'], 404);
        }

        $updateScope = $this->normalizeScope($query['scope'] ?? $body['scope'] ?? null);
        $targets = $this->resolveTargets($entry, $updateScope, $requestScope->tenantId);

        foreach (['title', 'notes', 'recurring_series_id'] as $field) {
            if (! array_key_exists($field, $body)) {
                continue;
            }

            if ($field === 'title') {
                $title = is_string($body['title']) ? trim($body['title']) : '';
                if ($title === '') {
                    return $this->json(['error' => 'Field "title" cannot be empty.'], 422);
                }
                foreach ($targets as $target) {
                    $target->set('title', $title);
                }

                continue;
            }

            foreach ($targets as $target) {
                $target->set($field, $body[$field]);
            }
        }

        if (array_key_exists('status', $body)) {
            $status = $this->normalizeStatus($body['status']);
            if ($status === null) {
                return $this->json(['error' => 'Field "status" is invalid.'], 422);
            }
            foreach ($targets as $target) {
                $target->set('status', $status);
            }
        }

        foreach (['starts_at', 'ends_at'] as $field) {
            if (! array_key_exists($field, $body)) {
                continue;
            }

            $normalized = $this->normalizeDateTime($body[$field]);
            if ($normalized === null) {
                return $this->json(['error' => sprintf('Field "%s" must be a valid datetime.', $field)], 422);
            }
            foreach ($targets as $target) {
                $target->set($field, $normalized);
            }
        }

        foreach ($targets as $target) {
            $this->entityTypeManager->getStorage('schedule_entry')->save($target);
        }

        return $this->json([
            'schedule' => $this->serialize($entry),
            'scope' => $updateScope,
            'affected_count' => count($targets),
        ]);
    }

    public function delete(array $params = [], array $query = [], mixed $account = null): SsrResponse
    {
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        $requestScope = $resolver->resolve($query, $account);
        $entry = $this->findByUuid((string) ($params['uuid'] ?? ''), $requestScope->tenantId);
        if ($entry === null) {
            return $this->json(['error' => 'Schedule entry not found.'], 404);
        }

        $scope = $this->normalizeScope($query['scope'] ?? null);
        $storage = $this->entityTypeManager->getStorage('schedule_entry');

        if (($entry->get('recurring_series_id') ?? null) !== null && $scope !== 'series') {
            $entry->set('status', 'cancelled');
            $storage->save($entry);

            return $this->json([
                'deleted' => true,
                'scope' => 'occurrence',
                'affected_count' => 1,
            ]);
        }

        $targets = $this->resolveTargets($entry, $scope, $requestScope->tenantId);
        $storage->delete($targets);

        return $this->json([
            'deleted' => true,
            'scope' => $scope,
            'affected_count' => count($targets),
        ]);
    }

    /**
     * @return list<ScheduleEntry>
     */
    private function loadAll(): array
    {
        $storage = $this->entityTypeManager->getStorage('schedule_entry');
        $entries = $storage->loadMultiple($storage->getQuery()->execute());

        return array_values(array_filter($entries, fn ($entry): bool => $entry instanceof ScheduleEntry));
    }

    private function findByUuid(string $uuid, string $tenantId): ?ScheduleEntry
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

        return $entry instanceof ScheduleEntry && (new TenantWorkspaceResolver($this->entityTypeManager))->tenantMatches($entry, $tenantId) ? $entry : null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: ?string, 1: ?string}
     */
    private function validateRequiredFields(array $body): array
    {
        $title = is_string($body['title'] ?? null) ? trim($body['title']) : '';
        $startsAt = $this->normalizeDateTime($body['starts_at'] ?? null);

        return [$title !== '' ? $title : null, $startsAt];
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    private function defaultEnd(string $startsAt): string
    {
        return (new \DateTimeImmutable($startsAt))->modify('+30 minutes')->format(\DateTimeInterface::ATOM);
    }

    private function normalizeStatus(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return in_array($value, self::VALID_STATUSES, true) ? $value : null;
    }

    private function normalizeScope(mixed $value): string
    {
        if ($value === 'series') {
            return 'series';
        }

        return 'occurrence';
    }

    /**
     * @return list<ScheduleEntry>
     */
    private function resolveTargets(ScheduleEntry $entry, string $scope, string $tenantId): array
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
            fn ($candidate): bool => $candidate instanceof ScheduleEntry && (new TenantWorkspaceResolver($this->entityTypeManager))->tenantMatches($candidate, $tenantId),
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
            'is_recurring' => is_string($entry->get('recurring_series_id')) && $entry->get('recurring_series_id') !== '',
            'tenant_id' => $entry->get('tenant_id'),
            'created_at' => $entry->get('created_at'),
            'updated_at' => $entry->get('updated_at'),
        ];
    }

    private function json(mixed $data, int $statusCode = 200): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}

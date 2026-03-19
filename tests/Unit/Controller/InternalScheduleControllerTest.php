<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\InternalScheduleController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Entity\ScheduleEntry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class InternalScheduleControllerTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';

    private const TENANT = 'test-tenant';

    private InternalApiTokenGenerator $tokenGenerator;

    private EntityRepository $repo;

    protected function setUp(): void
    {
        $this->tokenGenerator = new InternalApiTokenGenerator(self::SECRET);
        $this->repo = new EntityRepository(
            new EntityType(id: 'schedule_entry', label: 'Schedule Entry', class: ScheduleEntry::class, keys: ['id' => 'seid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );
    }

    public function test_rejects_unauthenticated(): void
    {
        $controller = $this->controller();
        $request = Request::create('/api/internal/schedule/query');

        $response = $controller->query(httpRequest: $request);

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('Unauthorized', $response->content);
    }

    public function test_query_returns_tenant_scoped(): void
    {
        $this->seedEntry('se-1', 'Meeting A', '2026-03-20T09:00:00', self::TENANT);
        $this->seedEntry('se-2', 'Meeting B', '2026-03-20T10:00:00', 'other-tenant');

        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/schedule/query');

        $response = $controller->query(httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertCount(1, $data['entries']);
        self::assertSame('se-1', $data['entries'][0]['uuid']);
    }

    public function test_query_filters_by_date_range(): void
    {
        $this->seedEntry('se-1', 'Early', '2026-03-15T09:00:00', self::TENANT);
        $this->seedEntry('se-2', 'In Range', '2026-03-20T09:00:00', self::TENANT);
        $this->seedEntry('se-3', 'Late', '2026-03-25T09:00:00', self::TENANT);

        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/schedule/query');

        $response = $controller->query(
            query: ['date_from' => '2026-03-18', 'date_to' => '2026-03-22'],
            httpRequest: $request,
        );

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertCount(1, $data['entries']);
        self::assertSame('se-2', $data['entries'][0]['uuid']);
    }

    public function test_query_respects_limit(): void
    {
        $this->seedEntry('se-1', 'A', '2026-03-20T09:00:00', self::TENANT);
        $this->seedEntry('se-2', 'B', '2026-03-20T10:00:00', self::TENANT);
        $this->seedEntry('se-3', 'C', '2026-03-20T11:00:00', self::TENANT);

        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/schedule/query');

        $response = $controller->query(query: ['limit' => '2'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertCount(2, $data['entries']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function controller(): InternalScheduleController
    {
        return new InternalScheduleController($this->repo, $this->tokenGenerator, self::TENANT);
    }

    private function authenticatedRequest(string $uri): Request
    {
        $token = $this->tokenGenerator->generate('acct-123');
        $request = Request::create($uri);
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }

    private int $nextId = 1;

    private function seedEntry(string $uuid, string $title, string $startsAt, string $tenantId): void
    {
        $entry = new ScheduleEntry([
            'seid' => $this->nextId++,
            'uuid' => $uuid,
            'title' => $title,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt,
            'tenant_id' => $tenantId,
        ]);
        $this->repo->save($entry);
    }
}

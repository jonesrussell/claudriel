<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\InternalTriageController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Entity\TriageEntry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class InternalTriageControllerTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';

    private const TENANT = 'test-tenant';

    private InternalApiTokenGenerator $tokenGenerator;

    private EntityRepository $repo;

    protected function setUp(): void
    {
        $this->tokenGenerator = new InternalApiTokenGenerator(self::SECRET);
        $this->repo = new EntityRepository(
            new EntityType(id: 'triage_entry', label: 'Triage Entry', class: TriageEntry::class, keys: ['id' => 'teid', 'uuid' => 'uuid', 'label' => 'sender_name']),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );
    }

    public function test_rejects_unauthenticated(): void
    {
        $controller = $this->controller();
        $request = Request::create('/api/internal/triage/list');

        $response = $controller->listUntriaged(httpRequest: $request);

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('Unauthorized', $response->content);
    }

    public function test_list_returns_pending_only(): void
    {
        $this->seedEntry('te-1', 'Alice', 'pending', self::TENANT);
        $this->seedEntry('te-2', 'Bob', 'resolved', self::TENANT);
        $this->seedEntry('te-3', 'Charlie', 'pending', self::TENANT);

        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/triage/list');

        $response = $controller->listUntriaged(httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertCount(2, $data['entries']);
        $uuids = array_column($data['entries'], 'uuid');
        self::assertContains('te-1', $uuids);
        self::assertContains('te-3', $uuids);
    }

    public function test_list_tenant_scoped(): void
    {
        $this->seedEntry('te-1', 'Alice', 'pending', self::TENANT);
        $this->seedEntry('te-2', 'Bob', 'pending', 'other-tenant');

        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/triage/list');

        $response = $controller->listUntriaged(httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertCount(1, $data['entries']);
        self::assertSame('te-1', $data['entries'][0]['uuid']);
    }

    public function test_resolve_changes_status(): void
    {
        $this->seedEntry('te-1', 'Alice', 'pending', self::TENANT);

        $controller = $this->controller();
        $request = $this->authenticatedPostRequest('/api/internal/triage/te-1/resolve', ['status' => 'resolved']);

        $response = $controller->resolve(params: ['uuid' => 'te-1'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertSame('resolved', $data['status']);
    }

    public function test_resolve_returns_404_for_missing(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedPostRequest('/api/internal/triage/nonexistent/resolve', ['status' => 'resolved']);

        $response = $controller->resolve(params: ['uuid' => 'nonexistent'], httpRequest: $request);

        self::assertSame(404, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function controller(): InternalTriageController
    {
        return new InternalTriageController($this->repo, $this->tokenGenerator, self::TENANT);
    }

    private function authenticatedRequest(string $uri): Request
    {
        $token = $this->tokenGenerator->generate('acct-123');
        $request = Request::create($uri);
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }

    private function authenticatedPostRequest(string $uri, array $body): Request
    {
        $token = $this->tokenGenerator->generate('acct-123');
        $request = Request::create($uri, 'POST', content: json_encode($body, JSON_THROW_ON_ERROR));
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }

    private int $nextId = 1;

    private function seedEntry(string $uuid, string $senderName, string $itemStatus, string $tenantId): void
    {
        $entry = new TriageEntry([
            'teid' => $this->nextId++,
            'uuid' => $uuid,
            'sender_name' => $senderName,
            'sender_email' => $senderName.'@example.com',
            'summary' => 'Test summary',
            'status' => $itemStatus,
            'occurred_at' => '2026-03-19T10:00:00',
            'tenant_id' => $tenantId,
        ]);
        $this->repo->save($entry);
    }
}

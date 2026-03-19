<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\InternalEventController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Entity\McEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class InternalEventControllerTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';

    private InternalApiTokenGenerator $tokenGenerator;

    private EntityRepository $repo;

    protected function setUp(): void
    {
        $this->tokenGenerator = new InternalApiTokenGenerator(self::SECRET);
        $this->repo = new EntityRepository(
            new EntityType(
                id: 'mc_event',
                label: 'Event',
                class: McEvent::class,
                keys: ['id' => 'eid', 'uuid' => 'uuid', 'content_hash' => 'content_hash'],
            ),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );
    }

    // -----------------------------------------------------------------------
    // Authentication
    // -----------------------------------------------------------------------

    public function test_rejects_unauthenticated(): void
    {
        $controller = $this->makeController();
        $request = Request::create('/api/internal/events/search');

        $response = $controller->search(httpRequest: $request);

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('Unauthorized', $response->content);
    }

    // -----------------------------------------------------------------------
    // search
    // -----------------------------------------------------------------------

    public function test_search_by_keyword(): void
    {
        $this->repo->save(new McEvent([
            'eid' => 'evt-1',
            'source' => 'gmail',
            'type' => 'email',
            'tenant_id' => 't1',
            'occurred' => '2026-03-19T10:00:00+00:00',
            'payload' => json_encode(['subject' => 'Meeting with Alice', 'body' => 'Discuss roadmap']),
        ]));
        $this->repo->save(new McEvent([
            'eid' => 'evt-2',
            'source' => 'gmail',
            'type' => 'email',
            'tenant_id' => 't1',
            'occurred' => '2026-03-19T11:00:00+00:00',
            'payload' => json_encode(['subject' => 'Lunch plans', 'body' => 'Pizza at noon']),
        ]));

        $controller = $this->makeController('t1');
        $request = $this->authenticatedRequest('/api/internal/events/search', 'acct-1');

        $response = $controller->search(query: ['query' => 'roadmap'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertSame(1, $data['count']);
        self::assertStringContainsString('Meeting with Alice', $data['events'][0]['payload']['subject']);
    }

    public function test_search_tenant_scoped(): void
    {
        $this->repo->save(new McEvent([
            'eid' => 'evt-t1',
            'source' => 'gmail',
            'type' => 'email',
            'tenant_id' => 't1',
            'occurred' => '2026-03-19T10:00:00+00:00',
            'payload' => json_encode(['subject' => 'T1 event']),
        ]));
        $this->repo->save(new McEvent([
            'eid' => 'evt-t2',
            'source' => 'gmail',
            'type' => 'email',
            'tenant_id' => 't2',
            'occurred' => '2026-03-19T10:00:00+00:00',
            'payload' => json_encode(['subject' => 'T2 event']),
        ]));

        $controller = $this->makeController('t1');
        $request = $this->authenticatedRequest('/api/internal/events/search', 'acct-1');

        $response = $controller->search(query: ['query' => 'event'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertSame(1, $data['count']);
        self::assertStringContainsString('T1', $data['events'][0]['payload']['subject']);
    }

    public function test_search_respects_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->repo->save(new McEvent([
                'eid' => "evt-limit-{$i}",
                'source' => 'gmail',
                'type' => 'email',
                'tenant_id' => 't1',
                'occurred' => '2026-03-19T10:00:00+00:00',
                'payload' => json_encode(['subject' => "Event {$i}"]),
            ]));
        }

        $controller = $this->makeController('t1');
        $request = $this->authenticatedRequest('/api/internal/events/search', 'acct-1');

        $response = $controller->search(query: ['query' => 'Event', 'limit' => '2'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertSame(2, $data['count']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeController(string $tenantId = 'default'): InternalEventController
    {
        return new InternalEventController(
            $this->repo,
            $this->tokenGenerator,
            $tenantId,
        );
    }

    private function authenticatedRequest(string $uri, string $accountId): Request
    {
        $token = $this->tokenGenerator->generate($accountId);
        $request = Request::create($uri);
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }
}

<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\InternalSearchController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class InternalSearchControllerTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';

    private InternalApiTokenGenerator $tokenGenerator;

    private EntityRepository $personRepo;

    private EntityRepository $commitmentRepo;

    private EntityRepository $eventRepo;

    protected function setUp(): void
    {
        $this->tokenGenerator = new InternalApiTokenGenerator(self::SECRET);

        $this->personRepo = new EntityRepository(
            new EntityType(
                id: 'person',
                label: 'Person',
                class: Person::class,
                keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name'],
            ),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );

        $this->commitmentRepo = new EntityRepository(
            new EntityType(
                id: 'commitment',
                label: 'Commitment',
                class: Commitment::class,
                keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title'],
            ),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );

        $this->eventRepo = new EntityRepository(
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
        $request = Request::create('/api/internal/search/global');

        $response = $controller->searchGlobal(httpRequest: $request);

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('Unauthorized', $response->content);
    }

    // -----------------------------------------------------------------------
    // searchGlobal
    // -----------------------------------------------------------------------

    public function test_search_returns_cross_entity_results(): void
    {
        $this->personRepo->save(new Person(['name' => 'Alice Smith', 'email' => 'alice@example.com', 'tenant_id' => 't1']));
        $this->commitmentRepo->save(new Commitment(['title' => 'Send report to Alice', 'tenant_id' => 't1', 'status' => 'active']));
        $this->eventRepo->save(new McEvent([
            'source' => 'gmail',
            'type' => 'email',
            'tenant_id' => 't1',
            'occurred' => '2026-03-19T10:00:00+00:00',
            'payload' => json_encode(['subject' => 'Meeting with Alice']),
        ]));

        $controller = $this->makeController('t1');
        $request = $this->authenticatedRequest('/api/internal/search/global', 'acct-1');

        $response = $controller->searchGlobal(query: ['query' => 'alice'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertCount(1, $data['persons']);
        self::assertCount(1, $data['commitments']);
        self::assertCount(1, $data['events']);
    }

    public function test_search_tenant_scoped(): void
    {
        $this->personRepo->save(new Person(['pid' => 'p-t1', 'name' => 'Alice T1', 'email' => 'alice@t1.com', 'tenant_id' => 't1']));
        $this->personRepo->save(new Person(['pid' => 'p-t2', 'name' => 'Alice T2', 'email' => 'alice@t2.com', 'tenant_id' => 't2']));

        $controller = $this->makeController('t1');
        $request = $this->authenticatedRequest('/api/internal/search/global', 'acct-1');

        $response = $controller->searchGlobal(query: ['query' => 'alice'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertCount(1, $data['persons']);
        self::assertSame('Alice T1', $data['persons'][0]['name']);
    }

    public function test_search_requires_query(): void
    {
        $controller = $this->makeController();
        $request = $this->authenticatedRequest('/api/internal/search/global', 'acct-1');

        $response = $controller->searchGlobal(query: [], httpRequest: $request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('query parameter is required', $response->content);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeController(string $tenantId = 'default'): InternalSearchController
    {
        return new InternalSearchController(
            $this->personRepo,
            $this->commitmentRepo,
            $this->eventRepo,
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

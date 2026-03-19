<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\InternalBriefController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Claudriel\Support\DriftDetector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class InternalBriefControllerTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';

    private InternalApiTokenGenerator $tokenGenerator;

    private EntityRepository $eventRepo;

    private EntityRepository $commitmentRepo;

    protected function setUp(): void
    {
        $this->tokenGenerator = new InternalApiTokenGenerator(self::SECRET);

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
    }

    // -----------------------------------------------------------------------
    // Authentication
    // -----------------------------------------------------------------------

    public function test_rejects_unauthenticated(): void
    {
        $controller = $this->makeController();
        $request = Request::create('/api/internal/brief/generate', 'POST');

        $response = $controller->generate(httpRequest: $request);

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('Unauthorized', $response->content);
    }

    // -----------------------------------------------------------------------
    // generate
    // -----------------------------------------------------------------------

    public function test_generate_returns_brief(): void
    {
        $controller = $this->makeController();
        $request = $this->authenticatedPostRequest('/api/internal/brief/generate', 'acct-1');

        $response = $controller->generate(httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertIsArray($data);
        self::assertArrayHasKey('commitments', $data);
        self::assertArrayHasKey('generated_at', $data);
    }

    public function test_generate_accepts_since_param(): void
    {
        $controller = $this->makeController();
        $request = $this->authenticatedPostRequest(
            '/api/internal/brief/generate',
            'acct-1',
            ['since' => '2026-03-01T00:00:00+00:00'],
        );

        $response = $controller->generate(httpRequest: $request);

        self::assertSame(200, $response->statusCode);
    }

    public function test_generate_rejects_invalid_since(): void
    {
        $controller = $this->makeController();
        $request = $this->authenticatedPostRequest(
            '/api/internal/brief/generate',
            'acct-1',
            ['since' => 'not-a-date'],
        );

        $response = $controller->generate(httpRequest: $request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('Invalid since date', $response->content);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeController(string $tenantId = 'default'): InternalBriefController
    {
        $assembler = new DayBriefAssembler(
            $this->eventRepo,
            $this->commitmentRepo,
            new DriftDetector($this->commitmentRepo),
        );

        return new InternalBriefController(
            $assembler,
            $this->tokenGenerator,
            $tenantId,
        );
    }

    private function authenticatedPostRequest(string $uri, string $accountId, array $body = []): Request
    {
        $token = $this->tokenGenerator->generate($accountId);
        $content = $body !== [] ? json_encode($body, JSON_THROW_ON_ERROR) : '';
        $request = Request::create($uri, 'POST', content: $content);
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }
}

<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\InternalJudgmentRuleController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Entity\JudgmentRule;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class InternalJudgmentRuleControllerTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';

    private InternalApiTokenGenerator $tokenGenerator;

    private EntityRepository $repo;

    protected function setUp(): void
    {
        $this->tokenGenerator = new InternalApiTokenGenerator(self::SECRET);
        $this->repo = new EntityRepository(
            new EntityType(
                id: 'judgment_rule',
                label: 'Judgment Rule',
                class: JudgmentRule::class,
                keys: ['id' => 'jrid', 'uuid' => 'uuid', 'label' => 'rule_text'],
            ),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );
    }

    public function test_rejects_unauthenticated_request(): void
    {
        $controller = $this->makeController();
        $request = Request::create('/api/internal/rules/active');

        $response = $controller->listActive(httpRequest: $request);

        self::assertSame(401, $response->statusCode);
    }

    public function test_list_active_returns_empty_array(): void
    {
        $controller = $this->makeController();
        $request = $this->authenticatedRequest('/api/internal/rules/active', 'acct-1');

        $response = $controller->listActive(httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertSame([], $data['rules']);
    }

    public function test_list_active_returns_tenant_scoped_rules(): void
    {
        // Save a rule for tenant "t1"
        $this->repo->save(new JudgmentRule([
            'jrid' => 1,
            'rule_text' => 'Always CC boss',
            'context' => 'Sending emails',
            'tenant_id' => 't1',
            'status' => 'active',
        ]));
        // Save a rule for different tenant "t2"
        $this->repo->save(new JudgmentRule([
            'jrid' => 2,
            'rule_text' => 'Other tenant rule',
            'tenant_id' => 't2',
            'status' => 'active',
        ]));

        $controller = $this->makeController('t1');
        $request = $this->authenticatedRequest('/api/internal/rules/active', 'acct-1');

        $response = $controller->listActive(httpRequest: $request);

        $data = json_decode($response->content, true);
        self::assertCount(1, $data['rules']);
        self::assertSame('Always CC boss', $data['rules'][0]['rule_text']);
    }

    public function test_suggest_creates_rule(): void
    {
        $controller = $this->makeController('t1');
        $request = $this->authenticatedRequest('/api/internal/rules/suggest', 'acct-1', 'POST', [
            'rule_text' => 'Use formal tone with clients',
            'context' => 'Email communication',
            'confidence' => 0.8,
        ]);

        $response = $controller->suggest(httpRequest: $request);

        self::assertSame(201, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertSame('Use formal tone with clients', $data['rule']['rule_text']);
        self::assertSame('agent_suggested', $data['rule']['source']);
    }

    public function test_suggest_rejects_empty_rule_text(): void
    {
        $controller = $this->makeController('t1');
        $request = $this->authenticatedRequest('/api/internal/rules/suggest', 'acct-1', 'POST', [
            'rule_text' => '',
            'context' => 'test',
        ]);

        $response = $controller->suggest(httpRequest: $request);

        self::assertSame(400, $response->statusCode);
    }

    public function test_suggest_rejects_rule_text_over_500_chars(): void
    {
        $controller = $this->makeController('t1');
        $request = $this->authenticatedRequest('/api/internal/rules/suggest', 'acct-1', 'POST', [
            'rule_text' => str_repeat('a', 501),
            'context' => 'test',
        ]);

        $response = $controller->suggest(httpRequest: $request);

        self::assertSame(400, $response->statusCode);
    }

    public function test_suggest_rejects_when_100_rules_exist(): void
    {
        for ($i = 1; $i <= 100; $i++) {
            $this->repo->save(new JudgmentRule([
                'jrid' => $i,
                'rule_text' => "Rule {$i}",
                'tenant_id' => 't1',
                'status' => 'active',
            ]));
        }

        $controller = $this->makeController('t1');
        $request = $this->authenticatedRequest('/api/internal/rules/suggest', 'acct-1', 'POST', [
            'rule_text' => 'One too many',
            'context' => 'test',
        ]);

        $response = $controller->suggest(httpRequest: $request);

        self::assertSame(429, $response->statusCode);
        self::assertStringContainsString('100', $response->content);
    }

    public function test_suggest_strips_html_and_control_chars(): void
    {
        $controller = $this->makeController('t1');
        $request = $this->authenticatedRequest('/api/internal/rules/suggest', 'acct-1', 'POST', [
            'rule_text' => '<script>alert("xss")</script>Always use formal tone',
            'context' => "Context with\x00null bytes",
        ]);

        $response = $controller->suggest(httpRequest: $request);

        self::assertSame(201, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertStringNotContainsString('<script>', $data['rule']['rule_text']);
        self::assertStringNotContainsString("\x00", $data['rule']['context'] ?? '');
    }

    private function makeController(string $tenantId = 'default'): InternalJudgmentRuleController
    {
        return new InternalJudgmentRuleController(
            $this->repo,
            $this->tokenGenerator,
            $tenantId,
        );
    }

    private function authenticatedRequest(string $uri, string $accountId, string $method = 'GET', ?array $body = null): Request
    {
        $token = $this->tokenGenerator->generate($accountId);
        $content = $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null;
        $request = Request::create($uri, $method, content: $content ?? '');
        $request->headers->set('Authorization', 'Bearer '.$token);
        if ($content !== null) {
            $request->headers->set('Content-Type', 'application/json');
        }

        return $request;
    }
}

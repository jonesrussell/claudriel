<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Entity\JudgmentRule;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\SSR\SsrResponse;

final class InternalJudgmentRuleController
{
    private const MAX_RULE_TEXT_LENGTH = 500;

    private const MAX_CONTEXT_LENGTH = 1000;

    private const MAX_RULES_PER_TENANT = 100;

    public function __construct(
        private readonly EntityRepositoryInterface $ruleRepo,
        private readonly InternalApiTokenGenerator $apiTokenGenerator,
        private readonly string $tenantId = 'default',
    ) {}

    public function listActive(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $rules = $this->ruleRepo->findBy(['tenant_id' => $this->tenantId, 'status' => 'active']);

        $items = [];
        foreach ($rules as $rule) {
            $items[] = [
                'uuid' => $rule->get('uuid'),
                'rule_text' => $rule->get('rule_text'),
                'context' => $rule->get('context') ?? '',
                'source' => $rule->get('source'),
                'confidence' => $rule->get('confidence'),
                'application_count' => $rule->get('application_count'),
            ];
        }

        // Sort by application_count desc, then confidence desc
        usort($items, static function (array $a, array $b): int {
            $cmp = ($b['application_count'] ?? 0) <=> ($a['application_count'] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }

            return ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0);
        });

        return $this->jsonResponse(['rules' => $items]);
    }

    public function suggest(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $body = $this->getRequestBody($httpRequest);
        if ($body === null) {
            return $this->jsonError('Invalid request body', 400);
        }

        $ruleText = trim((string) ($body['rule_text'] ?? ''));
        $context = trim((string) ($body['context'] ?? ''));
        $confidence = (float) ($body['confidence'] ?? 0.7);

        if ($ruleText === '') {
            return $this->jsonError('rule_text is required', 400);
        }

        if (mb_strlen($ruleText) > self::MAX_RULE_TEXT_LENGTH) {
            return $this->jsonError('rule_text must be '.self::MAX_RULE_TEXT_LENGTH.' characters or fewer', 400);
        }

        if (mb_strlen($context) > self::MAX_CONTEXT_LENGTH) {
            return $this->jsonError('context must be '.self::MAX_CONTEXT_LENGTH.' characters or fewer', 400);
        }

        $confidence = max(0.0, min(1.0, $confidence));

        // Enforce max rules per tenant
        $existingCount = $this->ruleRepo->count(['tenant_id' => $this->tenantId, 'status' => 'active']);
        if ($existingCount >= self::MAX_RULES_PER_TENANT) {
            return $this->jsonError('Maximum '.self::MAX_RULES_PER_TENANT.' rules per tenant reached', 429);
        }

        // Sanitize: strip HTML tags and control characters
        $ruleText = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', strip_tags($ruleText)) ?? $ruleText;
        $context = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', strip_tags($context)) ?? $context;

        $rule = new JudgmentRule([
            'rule_text' => $ruleText,
            'context' => $context,
            'source' => 'agent_suggested',
            'confidence' => $confidence,
            'tenant_id' => $this->tenantId,
            'status' => 'active',
        ]);

        $this->ruleRepo->save($rule);

        return new SsrResponse(
            content: json_encode(['rule' => [
                'uuid' => $rule->get('uuid'),
                'rule_text' => $rule->get('rule_text'),
                'context' => $rule->get('context'),
                'source' => $rule->get('source'),
                'confidence' => $rule->get('confidence'),
            ]], JSON_THROW_ON_ERROR),
            statusCode: 201,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    private function authenticate(mixed $httpRequest): ?string
    {
        $auth = '';
        if ($httpRequest instanceof Request) {
            $auth = $httpRequest->headers->get('Authorization', '');
        }

        if (! str_starts_with($auth, 'Bearer ')) {
            return null;
        }

        return $this->apiTokenGenerator->validate(substr($auth, 7));
    }

    private function getRequestBody(mixed $httpRequest): ?array
    {
        if (! $httpRequest instanceof Request) {
            return null;
        }
        $content = $httpRequest->getContent();
        if ($content === '') {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    private function jsonResponse(array $data): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    private function jsonError(string $message, int $statusCode): SsrResponse
    {
        return new SsrResponse(
            content: json_encode(['error' => $message], JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}

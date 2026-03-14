<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Entity\Tenant;
use Claudriel\Support\AdminAccess;
use Claudriel\Support\AdminCatalog;
use Claudriel\Support\AuthenticatedAccountSessionResolver;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class AdminSessionController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function state(array $params = [], array $query = [], mixed $account = null): SsrResponse
    {
        $resolvedAccount = $this->resolveAccount($account);
        if (! $resolvedAccount instanceof AuthenticatedAccount) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        if (! AdminAccess::allows($resolvedAccount)) {
            return $this->json(['error' => 'Admin access is required.'], 403);
        }

        $tenantId = (string) ($resolvedAccount->getTenantId() ?? '');

        return $this->json([
            'account' => [
                'uuid' => $resolvedAccount->getUuid(),
                'email' => $resolvedAccount->getEmail(),
                'tenant_id' => $tenantId,
                'roles' => $resolvedAccount->getRoles(),
            ],
            'tenant' => $this->serializeTenant($tenantId),
            'entity_types' => AdminCatalog::entityTypes($this->entityTypeManager),
        ]);
    }

    public function logout(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }

        unset($_SESSION['claudriel_account_uuid']);
        session_regenerate_id(true);

        return $this->json(['logged_out' => true], 200);
    }

    private function resolveAccount(mixed $account): ?AuthenticatedAccount
    {
        if ($account instanceof AuthenticatedAccount) {
            return $account;
        }

        return (new AuthenticatedAccountSessionResolver($this->entityTypeManager))->resolve();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeTenant(string $tenantId): ?array
    {
        if ($tenantId === '') {
            return null;
        }

        $ids = $this->entityTypeManager->getStorage('tenant')->getQuery()
            ->condition('uuid', $tenantId)
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        $tenant = $this->entityTypeManager->getStorage('tenant')->load(reset($ids));
        if (! $tenant instanceof Tenant) {
            return null;
        }

        $metadata = $tenant->get('metadata');

        return [
            'uuid' => $tenant->get('uuid'),
            'name' => $tenant->get('name'),
            'default_workspace_uuid' => is_array($metadata) ? ($metadata['default_workspace_uuid'] ?? null) : null,
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

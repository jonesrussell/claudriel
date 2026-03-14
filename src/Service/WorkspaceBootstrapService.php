<?php

declare(strict_types=1);

namespace Claudriel\Service;

use Claudriel\Entity\Tenant;
use Claudriel\Entity\Workspace;
use Waaseyaa\Entity\EntityTypeManager;

final class WorkspaceBootstrapService
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function bootstrapDefaultWorkspace(Tenant $tenant): Workspace
    {
        $metadata = $tenant->get('metadata');
        $defaultWorkspaceUuid = is_array($metadata) ? ($metadata['default_workspace_uuid'] ?? null) : null;
        if (is_string($defaultWorkspaceUuid) && $defaultWorkspaceUuid !== '') {
            $existing = $this->findWorkspaceByUuidForTenant($defaultWorkspaceUuid, (string) $tenant->get('uuid'));
            if ($existing instanceof Workspace) {
                return $existing;
            }
        }

        $existing = $this->findExistingDefaultWorkspace((string) $tenant->get('uuid'));
        if ($existing instanceof Workspace) {
            $tenantMetadata = is_array($metadata) ? $metadata : [];
            $tenantMetadata['default_workspace_uuid'] = $existing->get('uuid');
            $tenant->set('metadata', $tenantMetadata);
            $this->entityTypeManager->getStorage('tenant')->save($tenant);

            return $existing;
        }

        $workspace = new Workspace([
            'name' => 'Main Workspace',
            'description' => 'Default workspace created during public account onboarding.',
            'tenant_id' => $tenant->get('uuid'),
            'metadata' => json_encode([
                'bootstrap_kind' => 'default',
                'bootstrap_source' => 'public_signup',
                'surfaces' => ['dashboard', 'brief', 'chat'],
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->entityTypeManager->getStorage('workspace')->save($workspace);

        $tenantMetadata = is_array($metadata) ? $metadata : [];
        $tenantMetadata['default_workspace_uuid'] = $workspace->get('uuid');
        $tenant->set('metadata', $tenantMetadata);
        $this->entityTypeManager->getStorage('tenant')->save($tenant);

        return $workspace;
    }

    public function findWorkspaceByUuidForTenant(string $uuid, string $tenantId): ?Workspace
    {
        $ids = $this->entityTypeManager->getStorage('workspace')->getQuery()
            ->condition('uuid', $uuid)
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        $workspace = $this->entityTypeManager->getStorage('workspace')->load(reset($ids));
        if (! $workspace instanceof Workspace) {
            return null;
        }

        return (string) $workspace->get('tenant_id') === $tenantId ? $workspace : null;
    }

    private function findExistingDefaultWorkspace(string $tenantId): ?Workspace
    {
        $storage = $this->entityTypeManager->getStorage('workspace');
        $ids = $storage->getQuery()->condition('tenant_id', $tenantId)->execute();
        $workspaces = $storage->loadMultiple($ids);

        foreach ($workspaces as $workspace) {
            if (! $workspace instanceof Workspace) {
                continue;
            }

            $metadata = $workspace->get('metadata');
            if (is_string($metadata)) {
                $metadata = json_decode($metadata, true);
            }

            if (is_array($metadata) && ($metadata['bootstrap_kind'] ?? null) === 'default') {
                return $workspace;
            }
        }

        return null;
    }
}

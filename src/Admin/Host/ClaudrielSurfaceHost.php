<?php

declare(strict_types=1);

namespace Claudriel\Admin\Host;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Entity\Tenant;
use Claudriel\Support\AdminAccess;
use Claudriel\Support\AuthenticatedAccountSessionResolver;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\AdminSurface\Catalog\CatalogBuilder;
use Waaseyaa\AdminSurface\Host\AbstractAdminSurfaceHost;
use Waaseyaa\AdminSurface\Host\AdminSurfaceResultData;
use Waaseyaa\AdminSurface\Host\AdminSurfaceSessionData;
use Waaseyaa\AdminSurface\Query\SurfaceQuery;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class ClaudrielSurfaceHost extends AbstractAdminSurfaceHost
{
    /** @var \Closure(): EntityTypeManager */
    private \Closure $entityTypeManagerFactory;

    private ?EntityTypeManager $resolvedEtm = null;

    /**
     * @param  \Closure(): EntityTypeManager  $entityTypeManagerFactory
     */
    public function __construct(\Closure $entityTypeManagerFactory)
    {
        $this->entityTypeManagerFactory = $entityTypeManagerFactory;
    }

    private function etm(): EntityTypeManager
    {
        return $this->resolvedEtm ??= ($this->entityTypeManagerFactory)();
    }

    public function resolveSession(Request $request): ?AdminSurfaceSessionData
    {
        $etm = $this->etm();
        $resolver = new AuthenticatedAccountSessionResolver($etm);
        $account = $resolver->resolve();

        if (! $account instanceof AuthenticatedAccount) {
            return null;
        }

        if (! AdminAccess::allows($account)) {
            return null;
        }

        return new AdminSurfaceSessionData(
            accountId: $account->getUuid(),
            accountName: $account->getEmail(),
            roles: $account->getRoles(),
            policies: [],
            email: $account->getEmail(),
            tenantId: (string) ($account->getTenantId() ?? ''),
            tenantName: '',
        );
    }

    public function buildCatalog(AdminSurfaceSessionData $session): CatalogBuilder
    {
        $catalog = new CatalogBuilder;

        $catalog->defineEntity('workspace', 'Workspace')->group('structure')
            ->description('Isolated contexts for clients or domains');
        $catalog->defineEntity('project', 'Project')->group('structure')
            ->description('Track ongoing work and link to repos');
        $catalog->defineEntity('repo', 'Repo')->group('structure')
            ->description('Git repositories linked to projects and workspaces');
        $catalog->defineEntity('person', 'Person')->group('people')
            ->description('Contacts, clients, and collaborators');
        $catalog->defineEntity('commitment', 'Commitment')->group('workflows')
            ->description('Promises made and received');
        $catalog->defineEntity('schedule_entry', 'Schedule Entry')->group('workflows')
            ->description('Meetings and time-blocked events');
        $catalog->defineEntity('triage_entry', 'Triage Entry')->group('workflows')
            ->description('Inbox items awaiting a decision');
        $catalog->defineEntity('pipeline_config', 'Pipeline Config')->group('workflows')
            ->description('Lead pipeline settings per workspace');
        $catalog->defineEntity('prospect', 'Prospect')->group('workflows')
            ->description('Leads moving through the pipeline');
        $catalog->defineEntity('filtered_prospect', 'Filtered Prospect')->group('workflows')
            ->description('Leads rejected by the filter step');
        $catalog->defineEntity('prospect_attachment', 'Prospect Attachment')->group('workflows')
            ->description('Files attached to prospects');
        $catalog->defineEntity('prospect_audit', 'Prospect Audit')->group('workflows')
            ->description('Audit trail for prospect changes');

        return $catalog;
    }

    public function list(string $type, SurfaceQuery|array $query = []): AdminSurfaceResultData
    {
        return AdminSurfaceResultData::error(501, 'Not Implemented', 'Entity listing is handled by GraphQL');
    }

    public function get(string $type, string $id): AdminSurfaceResultData
    {
        return AdminSurfaceResultData::error(501, 'Not Implemented', 'Entity retrieval is handled by GraphQL');
    }

    public function action(string $type, string $action, array $payload = []): AdminSurfaceResultData
    {
        return AdminSurfaceResultData::error(501, 'Not Implemented', "Action '$action' is not supported");
    }

    /**
     * Legacy /admin/session endpoint for the frontend SPA.
     *
     * Returns the format the frontend expects: account, tenant, entity_types.
     * Will be removed once the frontend migrates to /admin/surface/* endpoints.
     */
    public function handleLegacySession(): SsrResponse
    {
        $session = $this->resolveSession(Request::createFromGlobals());
        if ($session === null) {
            return $this->jsonResponse(['error' => 'Not authenticated.'], 401);
        }

        $etm = $this->etm();
        $sessionData = $session->toArray();
        $catalog = $this->buildCatalog($session)->build();

        $entityTypes = array_map(function (array $entry) use ($etm): array {
            $typeId = $entry['id'];
            $definition = $etm->hasDefinition($typeId)
                ? $etm->getDefinition($typeId)
                : null;

            return [
                'id' => $typeId,
                'label' => $entry['label'],
                'description' => $entry['description'] ?? null,
                'keys' => $definition instanceof EntityType ? $definition->getKeys() : ['id' => 'id'],
                'group' => $entry['group'] ?? 'other',
                'disabled' => false,
            ];
        }, $catalog);

        $tenantId = $sessionData['tenant']['id'] ?? '';
        $tenantPayload = null;
        if ($tenantId !== '') {
            $tenantPayload = $this->serializeTenant($etm, $tenantId);
        }

        return $this->jsonResponse([
            'account' => [
                'uuid' => $sessionData['account']['id'],
                'email' => $sessionData['account']['email'] ?? $sessionData['account']['name'],
                'tenant_id' => $tenantId,
                'roles' => $sessionData['account']['roles'],
            ],
            'tenant' => $tenantPayload,
            'entity_types' => $entityTypes,
        ]);
    }

    /**
     * Legacy /admin/logout endpoint.
     */
    public function handleLegacyLogout(): SsrResponse
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }

        unset($_SESSION['claudriel_account_uuid']);
        session_regenerate_id(true);

        return $this->jsonResponse(['logged_out' => true]);
    }

    /**
     * @return array{uuid: string, name: string, default_workspace_uuid: string|null}|null
     */
    private function serializeTenant(EntityTypeManager $etm, string $tenantId): ?array
    {
        $ids = $etm->getStorage('tenant')->getQuery()
            ->condition('uuid', $tenantId)
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        $tenant = $etm->getStorage('tenant')->load(reset($ids));
        if (! $tenant instanceof Tenant) {
            return null;
        }

        $metadata = $tenant->get('metadata');
        $workspaceUuid = null;
        if (is_array($metadata)) {
            $val = $metadata['default_workspace_uuid'] ?? null;
            $workspaceUuid = is_string($val) && $val !== '' ? $val : null;
        }

        return [
            'uuid' => (string) $tenant->get('uuid'),
            'name' => (string) $tenant->get('name'),
            'default_workspace_uuid' => $workspaceUuid,
        ];
    }

    /**
     * /api/schema/{type} — returns field definitions as JSON Schema for the admin SPA.
     */
    public function handleSchema(string $type): SsrResponse
    {
        $etm = $this->etm();

        if (! $etm->hasDefinition($type)) {
            return $this->jsonResponse(['error' => "Unknown entity type: {$type}"], 404);
        }

        $definition = $etm->getDefinition($type);
        $fieldDefs = $definition->getFieldDefinitions();
        $keys = $definition->getKeys();
        $label = $definition->getLabel();

        $properties = [];
        $required = [];
        $weight = 0;

        foreach ($fieldDefs as $fieldName => $fieldDef) {
            $prop = [
                'type' => $this->mapFieldType($fieldDef['type'] ?? 'string'),
                'x-label' => $fieldDef['label'] ?? ucfirst(str_replace('_', ' ', $fieldName)),
                'x-weight' => $weight,
            ];

            if (! empty($fieldDef['readOnly'])) {
                $prop['readOnly'] = true;
            }

            if (! empty($fieldDef['required'])) {
                $required[] = $fieldName;
                $prop['x-required'] = true;
            }

            if (($fieldDef['type'] ?? '') === 'text_long') {
                $prop['x-widget'] = 'textarea';
            }

            if (($fieldDef['type'] ?? '') === 'email') {
                $prop['format'] = 'email';
            }

            if (($fieldDef['type'] ?? '') === 'datetime' || ($fieldDef['type'] ?? '') === 'timestamp') {
                $prop['format'] = 'date-time';
            }

            $properties[$fieldName] = $prop;
            $weight += 10;
        }

        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => $label,
            'description' => "{$label} entity",
            'type' => 'object',
            'x-entity-type' => $type,
            'x-translatable' => false,
            'x-revisionable' => false,
            'properties' => $properties,
            'required' => $required,
        ];

        return $this->jsonResponse(['meta' => ['schema' => $schema]]);
    }

    private function mapFieldType(string $waaseyaaType): string
    {
        return match ($waaseyaaType) {
            'integer' => 'integer',
            'float' => 'number',
            'boolean' => 'boolean',
            default => 'string',
        };
    }

    private function jsonResponse(mixed $data, int $statusCode = 200): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}

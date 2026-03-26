<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Controller\IngestController;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class IngestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'mc_event',
            label: 'Event',
            class: McEvent::class,
            keys: ['id' => 'eid', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'eid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'source' => ['type' => 'string'],
                'category' => ['type' => 'string'],
                'subject' => ['type' => 'string'],
                'body' => ['type' => 'text_long'],
                'sender_name' => ['type' => 'string'],
                'sender_email' => ['type' => 'string'],
                'external_id' => ['type' => 'string'],
                'content_hash' => ['type' => 'string'],
                'raw_payload' => ['type' => 'text_long'],
                'occurred_at' => ['type' => 'datetime'],
                'tenant_id' => ['type' => 'string'],
                'workspace_id' => ['type' => 'string'],
                'importance_score' => ['type' => 'float'],
                'access_count' => ['type' => 'integer'],
                'last_accessed_at' => ['type' => 'datetime'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'person',
            label: 'Person',
            class: Person::class,
            keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'pid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'email' => ['type' => 'email', 'required' => true],
                'tier' => ['type' => 'string'],
                'source' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'latest_summary' => ['type' => 'string'],
                'last_interaction_at' => ['type' => 'datetime'],
                'last_inbox_category' => ['type' => 'string'],
                'importance_score' => ['type' => 'float'],
                'access_count' => ['type' => 'integer'],
                'last_accessed_at' => ['type' => 'datetime'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        $ingestRoute = RouteBuilder::create('/api/ingest')
            ->controller(IngestController::class.'::handle')
            ->allowAll()
            ->methods('POST')
            ->build();
        $ingestRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.ingest', $ingestRoute);
    }
}

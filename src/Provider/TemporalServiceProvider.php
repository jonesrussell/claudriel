<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Controller\TemporalNotificationApiController;
use Claudriel\Entity\TemporalNotification;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class TemporalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'temporal_notification',
            label: 'Temporal Notification',
            class: TemporalNotification::class,
            keys: ['id' => 'tnid', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'tnid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'title' => ['type' => 'string'],
                'message' => ['type' => 'text_long'],
                'type' => ['type' => 'string'],
                'state' => ['type' => 'string'],
                'scheduled_at' => ['type' => 'datetime'],
                'delivered_at' => ['type' => 'datetime'],
                'snoozed_until' => ['type' => 'datetime'],
                'workspace_uuid' => ['type' => 'string'],
                'actions' => ['type' => 'text_long'],
                'action_states' => ['type' => 'text_long'],
                'metadata' => ['type' => 'text_long'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        $dismissTemporalNotificationRoute = RouteBuilder::create('/api/temporal-notifications/{uuid}/dismiss')
            ->controller(TemporalNotificationApiController::class.'::dismiss')
            ->allowAll()
            ->methods('POST')
            ->build();
        $dismissTemporalNotificationRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.temporal-notifications.dismiss', $dismissTemporalNotificationRoute);

        $snoozeTemporalNotificationRoute = RouteBuilder::create('/api/temporal-notifications/{uuid}/snooze')
            ->controller(TemporalNotificationApiController::class.'::snooze')
            ->allowAll()
            ->methods('POST')
            ->build();
        $snoozeTemporalNotificationRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.temporal-notifications.snooze', $snoozeTemporalNotificationRoute);

        $updateTemporalNotificationActionRoute = RouteBuilder::create('/api/temporal-notifications/{uuid}/actions/{action}')
            ->controller(TemporalNotificationApiController::class.'::updateAction')
            ->allowAll()
            ->methods('POST')
            ->build();
        $updateTemporalNotificationActionRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.temporal-notifications.actions', $updateTemporalNotificationActionRoute);
    }
}

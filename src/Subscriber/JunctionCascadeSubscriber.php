<?php

declare(strict_types=1);

namespace Claudriel\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;

final class JunctionCascadeSubscriber implements EventSubscriberInterface
{
    /** Maps parent entity type to junction entity types and their foreign key field. */
    private const JUNCTION_MAP = [
        'project' => [
            ['entity_type' => 'project_repo', 'field' => 'project_uuid'],
            ['entity_type' => 'workspace_project', 'field' => 'project_uuid'],
        ],
        'workspace' => [
            ['entity_type' => 'workspace_project', 'field' => 'workspace_uuid'],
            ['entity_type' => 'workspace_repo', 'field' => 'workspace_uuid'],
        ],
        'repo' => [
            ['entity_type' => 'project_repo', 'field' => 'repo_uuid'],
            ['entity_type' => 'workspace_repo', 'field' => 'repo_uuid'],
        ],
    ];

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            EntityEvents::POST_DELETE->value => 'onPostDelete',
        ];
    }

    public function onPostDelete(EntityEvent $event): void
    {
        $entity = $event->entity;
        $entityTypeId = $entity->getEntityTypeId();

        $junctions = self::JUNCTION_MAP[$entityTypeId] ?? [];
        if ($junctions === []) {
            return;
        }

        $uuid = $entity->get('uuid');
        if ($uuid === null || $uuid === '') {
            return;
        }

        foreach ($junctions as $junction) {
            try {
                $storage = $this->entityTypeManager->getStorage($junction['entity_type']);
                $query = $storage->getQuery();
                $query->condition($junction['field'], $uuid);
                $ids = $query->execute();

                if ($ids !== []) {
                    $storage->delete($storage->loadMultiple($ids));
                }
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'JunctionCascadeSubscriber: failed to clean %s.%s=%s: %s',
                    $junction['entity_type'],
                    $junction['field'],
                    $uuid,
                    $e->getMessage(),
                ));
            }
        }
    }
}

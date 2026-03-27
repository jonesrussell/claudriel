<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Entity\Milestone;
use Claudriel\Entity\MilestoneProject;
use Claudriel\Subscriber\JunctionCascadeSubscriber;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class MilestoneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'milestone',
            label: 'Milestone',
            class: Milestone::class,
            keys: ['id' => 'mid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'mid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true, 'maxLength' => 255],
                'description' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'target_date' => ['type' => 'string'],
                'account_id' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'milestone_project',
            label: 'Milestone Project',
            class: MilestoneProject::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'uuid'],
            fieldDefinitions: [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'milestone_uuid' => ['type' => 'string', 'required' => true],
                'project_uuid' => ['type' => 'string', 'required' => true],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }

    public function boot(): void
    {
        $dispatcher = $this->resolve(EventDispatcherInterface::class);
        if ($dispatcher instanceof SymfonyDispatcher) {
            $dispatcher->addSubscriber(new JunctionCascadeSubscriber(
                $this->resolve(EntityTypeManager::class),
            ));
        }
    }
}

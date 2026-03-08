<?php

declare(strict_types=1);

namespace MyClaudia;

use MyClaudia\Entity\Account;
use MyClaudia\Entity\Commitment;
use MyClaudia\Entity\Integration;
use MyClaudia\Entity\McEvent;
use MyClaudia\Entity\Person;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class McClaudiaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'account',
            label: 'Account',
            class: Account::class,
            keys: ['id' => 'aid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        $this->entityType(new EntityType(
            id: 'mc_event',
            label: 'Event',
            class: McEvent::class,
            keys: ['id' => 'eid', 'uuid' => 'uuid'],
        ));

        $this->entityType(new EntityType(
            id: 'person',
            label: 'Person',
            class: Person::class,
            keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        $this->entityType(new EntityType(
            id: 'integration',
            label: 'Integration',
            class: Integration::class,
            keys: ['id' => 'iid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        $this->entityType(new EntityType(
            id: 'commitment',
            label: 'Commitment',
            class: Commitment::class,
            keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title'],
        ));
    }
}

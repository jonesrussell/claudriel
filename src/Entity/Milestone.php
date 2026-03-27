<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Milestone extends ContentEntityBase
{
    protected string $entityTypeId = 'milestone';

    protected array $entityKeys = [
        'id' => 'mid',
        'uuid' => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('account_id') === null) {
            $this->set('account_id', null);
        }
        if ($this->get('tenant_id') === null) {
            $this->set('tenant_id', $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default');
        }
        if ($this->get('name') === null) {
            $this->set('name', '');
        }
        if ($this->get('description') === null) {
            $this->set('description', '');
        }
        if ($this->get('status') === null) {
            $this->set('status', 'active');
        }
        if ($this->get('target_date') === null) {
            $this->set('target_date', null);
        }
    }
}

<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Repo extends ContentEntityBase
{
    protected string $entityTypeId = 'repo';

    protected array $entityKeys = [
        'id' => 'rid',
        'uuid' => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('owner') === null) {
            $this->set('owner', '');
        }
        if ($this->get('name') === null) {
            $this->set('name', '');
        }
        if ($this->get('full_name') === null) {
            $owner = $this->get('owner');
            $name = $this->get('name');
            $this->set('full_name', ($owner !== '' && $name !== '') ? $owner.'/'.$name : '');
        }
        if ($this->get('default_branch') === null) {
            $this->set('default_branch', 'main');
        }
        if ($this->get('local_path') === null) {
            $this->set('local_path', null);
        }
        if ($this->get('account_id') === null) {
            $this->set('account_id', null);
        }
        if ($this->get('tenant_id') === null) {
            $this->set('tenant_id', $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default');
        }
    }
}

<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class MergeAuditLog extends ContentEntityBase
{
    protected string $entityTypeId = 'merge_audit_log';

    protected array $entityKeys = [
        'id' => 'maid',
        'uuid' => 'uuid',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('source_snapshot') === null) {
            $this->set('source_snapshot', null);
        }
        if ($this->get('target_snapshot') === null) {
            $this->set('target_snapshot', null);
        }
        if ($this->get('result_snapshot') === null) {
            $this->set('result_snapshot', null);
        }
    }
}

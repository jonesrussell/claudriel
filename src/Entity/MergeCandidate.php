<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class MergeCandidate extends ContentEntityBase
{
    protected string $entityTypeId = 'merge_candidate';

    protected array $entityKeys = [
        'id' => 'mcid',
        'uuid' => 'uuid',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('status') === null) {
            $this->set('status', 'pending');
        }
        if ($this->get('match_reasons') === null) {
            $this->set('match_reasons', null);
        }
    }
}

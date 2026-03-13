<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class CommitmentExtractionLog extends ContentEntityBase
{
    protected string $entityTypeId = 'commitment_extraction_log';

    protected array $entityKeys = [
        'id' => 'celid',
        'uuid' => 'uuid',
    ];

    public function __construct(array $values = [])
    {
        if (! array_key_exists('created_at', $values)) {
            $values['created_at'] = gmdate('Y-m-d H:i:s');
        }

        parent::__construct($values, 'commitment_extraction_log', $this->entityKeys);
    }
}

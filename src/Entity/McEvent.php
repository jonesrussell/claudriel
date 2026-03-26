<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

/**
 * Immutable ingested fact.
 * Named McEvent to avoid collision with PHP's reserved 'Event' keyword context.
 */
final class McEvent extends ContentEntityBase
{
    protected string $entityTypeId = 'mc_event';

    protected array $entityKeys = [
        'id' => 'eid',
        'uuid' => 'uuid',
        'content_hash' => 'content_hash',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('category') === null) {
            $this->set('category', 'notification');
        }

        if ($this->get('workspace_id') === null) {
            $this->set('workspace_id', null);
        }
        if ($this->get('importance_score') === null) {
            $this->set('importance_score', 1.0);
        }
        if ($this->get('access_count') === null) {
            $this->set('access_count', 0);
        }
        if ($this->get('last_accessed_at') === null) {
            $this->set('last_accessed_at', null);
        }
    }

    /**
     * @return array<string, string>
     */
    public function getEntityKeys(): array
    {
        return $this->entityKeys;
    }
}

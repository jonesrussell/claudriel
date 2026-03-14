<?php

declare(strict_types=1);

namespace Claudriel\Support;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;

final class AdminCatalog
{
    /**
     * @var array<string, array{group: string}>
     */
    private const TYPES = [
        'workspace' => ['group' => 'structure'],
        'person' => ['group' => 'people'],
        'commitment' => ['group' => 'workflows'],
        'schedule_entry' => ['group' => 'workflows'],
        'triage_entry' => ['group' => 'workflows'],
    ];

    /**
     * @return list<array{id: string, label: string, keys: array<string, string>, group: string, disabled: bool}>
     */
    public static function entityTypes(EntityTypeManager $entityTypeManager): array
    {
        $types = [];

        foreach (self::TYPES as $typeId => $meta) {
            if (! $entityTypeManager->hasDefinition($typeId)) {
                continue;
            }

            $definition = $entityTypeManager->getDefinition($typeId);
            if (! $definition instanceof EntityType) {
                continue;
            }

            $types[] = [
                'id' => $typeId,
                'label' => $definition->getLabel(),
                'keys' => $definition->getKeys(),
                'group' => $meta['group'],
                'disabled' => false,
            ];
        }

        return $types;
    }
}

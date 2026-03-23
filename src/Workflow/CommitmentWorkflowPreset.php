<?php

declare(strict_types=1);

namespace Claudriel\Workflow;

use Waaseyaa\Workflows\Workflow;

/**
 * Factory for the commitment lifecycle workflow preset.
 *
 * Creates a Workflow config entity pre-populated with 4 commitment
 * states (pending, active, completed, archived) and 6 transitions.
 *
 * The "activate" transition carries a confidence guard: only
 * commitments with confidence >= 0.7 may transition from pending
 * to active. Guard enforcement is the caller's responsibility
 * (CommitmentHandler checks metadata before calling transition).
 */
final class CommitmentWorkflowPreset
{
    public const string STATE_PENDING = 'pending';

    public const string STATE_ACTIVE = 'active';

    public const string STATE_COMPLETED = 'completed';

    public const string STATE_ARCHIVED = 'archived';

    public const float ACTIVATION_CONFIDENCE_THRESHOLD = 0.7;

    /** All valid workflow states. */
    public const array VALID_STATES = [
        self::STATE_PENDING,
        self::STATE_ACTIVE,
        self::STATE_COMPLETED,
        self::STATE_ARCHIVED,
    ];

    public static function create(): Workflow
    {
        return new Workflow([
            'id' => 'commitment',
            'label' => 'Commitment',
            'states' => [
                'pending' => ['label' => 'Pending', 'weight' => 0],
                'active' => ['label' => 'Active', 'weight' => 1],
                'completed' => ['label' => 'Completed', 'weight' => 2],
                'archived' => ['label' => 'Archived', 'weight' => 3],
            ],
            'transitions' => [
                'activate' => [
                    'label' => 'Activate',
                    'from' => ['pending'],
                    'to' => 'active',
                ],
                'complete' => [
                    'label' => 'Complete',
                    'from' => ['active'],
                    'to' => 'completed',
                ],
                'defer' => [
                    'label' => 'Defer',
                    'from' => ['active'],
                    'to' => 'pending',
                ],
                'reopen' => [
                    'label' => 'Reopen',
                    'from' => ['completed'],
                    'to' => 'active',
                ],
                'archive' => [
                    'label' => 'Archive',
                    'from' => ['pending', 'active', 'completed'],
                    'to' => 'archived',
                ],
                'restore' => [
                    'label' => 'Restore',
                    'from' => ['archived'],
                    'to' => 'pending',
                ],
            ],
        ]);
    }

    /**
     * Check whether the activate transition's confidence guard passes.
     */
    public static function canActivate(float $confidence): bool
    {
        return $confidence >= self::ACTIVATION_CONFIDENCE_THRESHOLD;
    }
}

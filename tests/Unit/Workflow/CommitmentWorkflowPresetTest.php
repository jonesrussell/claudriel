<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Workflow;

use Claudriel\Workflow\CommitmentWorkflowPreset;
use PHPUnit\Framework\TestCase;

final class CommitmentWorkflowPresetTest extends TestCase
{
    public function test_create_returns_workflow_with_four_states(): void
    {
        $workflow = CommitmentWorkflowPreset::create();

        self::assertTrue($workflow->hasState('pending'));
        self::assertTrue($workflow->hasState('active'));
        self::assertTrue($workflow->hasState('completed'));
        self::assertTrue($workflow->hasState('archived'));
        self::assertCount(4, $workflow->getStates());
    }

    public function test_create_returns_workflow_with_six_transitions(): void
    {
        $workflow = CommitmentWorkflowPreset::create();

        self::assertTrue($workflow->hasTransition('activate'));
        self::assertTrue($workflow->hasTransition('complete'));
        self::assertTrue($workflow->hasTransition('defer'));
        self::assertTrue($workflow->hasTransition('reopen'));
        self::assertTrue($workflow->hasTransition('archive'));
        self::assertTrue($workflow->hasTransition('restore'));
        self::assertCount(6, $workflow->getTransitions());
    }

    public function test_activate_transition_from_pending_to_active(): void
    {
        $workflow = CommitmentWorkflowPreset::create();

        self::assertTrue($workflow->isTransitionAllowed('pending', 'active'));
        // completed -> active is allowed via "reopen", but archived -> active is not.
        self::assertFalse($workflow->isTransitionAllowed('archived', 'active'));
    }

    public function test_complete_transition_from_active_to_completed(): void
    {
        $workflow = CommitmentWorkflowPreset::create();

        self::assertTrue($workflow->isTransitionAllowed('active', 'completed'));
        self::assertFalse($workflow->isTransitionAllowed('pending', 'completed'));
    }

    public function test_defer_transition_from_active_to_pending(): void
    {
        $workflow = CommitmentWorkflowPreset::create();

        self::assertTrue($workflow->isTransitionAllowed('active', 'pending'));
    }

    public function test_reopen_transition_from_completed_to_active(): void
    {
        $workflow = CommitmentWorkflowPreset::create();

        self::assertTrue($workflow->isTransitionAllowed('completed', 'active'));
    }

    public function test_archive_from_any_non_archived_state(): void
    {
        $workflow = CommitmentWorkflowPreset::create();

        self::assertTrue($workflow->isTransitionAllowed('pending', 'archived'));
        self::assertTrue($workflow->isTransitionAllowed('active', 'archived'));
        self::assertTrue($workflow->isTransitionAllowed('completed', 'archived'));
    }

    public function test_restore_from_archived_to_pending(): void
    {
        $workflow = CommitmentWorkflowPreset::create();

        self::assertTrue($workflow->isTransitionAllowed('archived', 'pending'));
        self::assertFalse($workflow->isTransitionAllowed('archived', 'active'));
    }

    public function test_can_activate_with_sufficient_confidence(): void
    {
        self::assertTrue(CommitmentWorkflowPreset::canActivate(0.7));
        self::assertTrue(CommitmentWorkflowPreset::canActivate(0.95));
        self::assertTrue(CommitmentWorkflowPreset::canActivate(1.0));
    }

    public function test_cannot_activate_with_low_confidence(): void
    {
        self::assertFalse(CommitmentWorkflowPreset::canActivate(0.69));
        self::assertFalse(CommitmentWorkflowPreset::canActivate(0.0));
        self::assertFalse(CommitmentWorkflowPreset::canActivate(0.5));
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $workflow = CommitmentWorkflowPreset::create();

        // Cannot go directly from pending to completed (must go through active).
        self::assertFalse($workflow->isTransitionAllowed('pending', 'completed'));
        // Cannot go from archived directly to active (must restore to pending first).
        self::assertFalse($workflow->isTransitionAllowed('archived', 'active'));
        self::assertFalse($workflow->isTransitionAllowed('archived', 'completed'));
    }
}

<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Workflow\CommitmentWorkflowPreset;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;
use Waaseyaa\Workflows\Workflow;

/**
 * PATCH /commitments/{uuid} controller.
 *
 * Accepts either `workflow_state` (preferred) or `status` (legacy)
 * in the request body. Validates that the transition is allowed by
 * the commitment workflow state machine.
 */
final class CommitmentUpdateController
{
    private readonly Workflow $workflow;

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {
        $this->workflow = CommitmentWorkflowPreset::create();
    }

    public function update(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $uuid = $params['uuid'] ?? '';

        $storage = $this->entityTypeManager->getStorage('commitment');
        $ids = $storage->getQuery()->condition('uuid', $uuid)->execute();
        $commitment = ! empty($ids) ? $storage->load(reset($ids)) : null;

        if (! $commitment instanceof ContentEntityInterface) {
            return new SsrResponse(
                content: json_encode(['error' => 'Not found.']),
                statusCode: 404,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        $raw = $httpRequest?->getContent() ?? '';
        $body = json_decode($raw, true) ?? [];
        $targetState = $body['workflow_state'] ?? $body['status'] ?? null;

        if (! is_string($targetState) || ! in_array($targetState, CommitmentWorkflowPreset::VALID_STATES, true)) {
            return new SsrResponse(
                content: json_encode(['error' => sprintf('Invalid workflow_state. Use: %s', implode(', ', CommitmentWorkflowPreset::VALID_STATES))]),
                statusCode: 422,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        $currentState = (string) ($commitment->get('workflow_state') ?? $commitment->get('status') ?? CommitmentWorkflowPreset::STATE_PENDING);

        if ($currentState !== $targetState && ! $this->workflow->isTransitionAllowed($currentState, $targetState)) {
            return new SsrResponse(
                content: json_encode(['error' => sprintf('Transition from "%s" to "%s" is not allowed.', $currentState, $targetState)]),
                statusCode: 422,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        // Apply confidence guard for activate transition.
        if ($targetState === CommitmentWorkflowPreset::STATE_ACTIVE && $currentState === CommitmentWorkflowPreset::STATE_PENDING) {
            $confidence = (float) ($commitment->get('confidence') ?? 1.0);
            if (! CommitmentWorkflowPreset::canActivate($confidence)) {
                return new SsrResponse(
                    content: json_encode(['error' => sprintf('Cannot activate: confidence %.2f is below threshold %.2f.', $confidence, CommitmentWorkflowPreset::ACTIVATION_CONFIDENCE_THRESHOLD)]),
                    statusCode: 422,
                    headers: ['Content-Type' => 'application/json'],
                );
            }
        }

        $commitment->set('workflow_state', $targetState);
        $commitment->set('status', $targetState);
        $storage->save($commitment);

        return new SsrResponse(
            content: json_encode(['uuid' => $uuid, 'workflow_state' => $targetState, 'status' => $targetState]),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}

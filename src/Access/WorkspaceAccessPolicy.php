<?php

declare(strict_types=1);

namespace Claudriel\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

/**
 * Personal: only owner can CRUD. Workspaces are not shared within a tenant.
 */
#[PolicyAttribute(entityType: 'workspace')]
final class WorkspaceAccessPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'workspace';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if (! $account->isAuthenticated()) {
            return AccessResult::unauthenticated('Authentication required.');
        }

        if ($account->hasPermission('administer content')) {
            return AccessResult::allowed('Admin permission.');
        }

        if (! $this->isOwner($entity, $account)) {
            return AccessResult::forbidden('Workspaces are personal. Only the owner can access.');
        }

        return match ($operation) {
            'view', 'update', 'delete' => AccessResult::allowed('Owner can manage their workspace.'),
            default => AccessResult::neutral('Unknown operation.'),
        };
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if (! $account->isAuthenticated()) {
            return AccessResult::unauthenticated('Authentication required.');
        }

        return AccessResult::allowed('Authenticated user can create workspaces.');
    }

    /**
     * Match workspace owner by numeric ID or UUID.
     *
     * The agent subprocess creates workspaces with account_id set to the
     * account UUID (from the HMAC token), while the admin SPA session
     * resolves $account->id() as the numeric entity ID. Accept either.
     */
    private function isOwner(EntityInterface $entity, AccountInterface $account): bool
    {
        $storedId = $entity->get('account_id');
        if ($storedId === null || $storedId === '') {
            return false;
        }

        $storedId = (string) $storedId;

        // Match numeric entity ID
        if ($storedId === (string) $account->id()) {
            return true;
        }

        // Match UUID (agent subprocess path)
        if ($account instanceof AuthenticatedAccount && $storedId === $account->getUuid()) {
            return true;
        }

        return false;
    }
}

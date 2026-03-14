<?php

declare(strict_types=1);

namespace Claudriel\Support;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Entity\Account;

final class AdminAccess
{
    private const PRIVILEGED_ROLES = [
        'tenant_owner',
        'admin',
        'administrator',
    ];

    private const PRIVILEGED_PERMISSIONS = [
        'access admin ui',
        'administer site',
    ];

    public static function allows(mixed $account): bool
    {
        $roles = [];
        $permissions = [];

        if ($account instanceof AuthenticatedAccount) {
            $roles = $account->getRoles();
            $permissions = self::permissionNames($account->account());
        } elseif ($account instanceof Account) {
            $roles = $account->getRoles();
            $permissions = self::permissionNames($account);
        }

        if (array_intersect(self::PRIVILEGED_ROLES, $roles) !== []) {
            return true;
        }

        return array_intersect(self::PRIVILEGED_PERMISSIONS, $permissions) !== [];
    }

    /**
     * @return string[]
     */
    private static function permissionNames(Account $account): array
    {
        $permissions = $account->get('permissions');

        return is_array($permissions) ? array_values(array_filter($permissions, is_string(...))) : [];
    }
}

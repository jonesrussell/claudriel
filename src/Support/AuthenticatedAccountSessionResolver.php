<?php

declare(strict_types=1);

namespace Claudriel\Support;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Entity\Account;
use Waaseyaa\Entity\EntityTypeManager;

final class AuthenticatedAccountSessionResolver
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function resolve(): ?AuthenticatedAccount
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }

        $accountUuid = $_SESSION['claudriel_account_uuid'] ?? null;
        if (! is_string($accountUuid) || $accountUuid === '') {
            return null;
        }

        $ids = $this->entityTypeManager->getStorage('account')->getQuery()
            ->condition('uuid', $accountUuid)
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        $account = $this->entityTypeManager->getStorage('account')->load(reset($ids));
        if (! $account instanceof Account || ! $account->isVerified()) {
            return null;
        }

        return new AuthenticatedAccount($account);
    }
}

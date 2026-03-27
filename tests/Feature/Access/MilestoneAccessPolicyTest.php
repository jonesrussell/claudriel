<?php

declare(strict_types=1);

namespace Claudriel\Tests\Feature\Access;

use Claudriel\Access\MilestoneAccessPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MilestoneAccessPolicy::class)]
final class MilestoneAccessPolicyTest extends TestCase
{
    use AccessPolicyTestHelpers;

    private MilestoneAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new MilestoneAccessPolicy;
    }

    #[Test]
    public function applies_to_milestone_entity_type(): void
    {
        self::assertTrue($this->policy->appliesTo('milestone'));
        self::assertFalse($this->policy->appliesTo('project'));
    }

    #[Test]
    public function unauthenticated_user_is_denied(): void
    {
        $entity = $this->createEntity('milestone', ['account_id' => '1', 'tenant_id' => 'tenant-1']);
        $account = $this->createAnonymousAccount();

        $result = $this->policy->access($entity, 'view', $account);

        self::assertTrue($result->isUnauthenticated());
    }

    #[Test]
    public function owner_can_view_update_delete(): void
    {
        $entity = $this->createEntity('milestone', ['account_id' => '42', 'tenant_id' => 'tenant-1']);
        $account = $this->createAuthenticatedAccount(42, 'tenant-1');

        foreach (['view', 'update', 'delete'] as $operation) {
            $result = $this->policy->access($entity, $operation, $account);
            self::assertTrue($result->isAllowed(), "Owner should be allowed to {$operation}.");
        }
    }

    #[Test]
    public function tenant_member_can_view_update_and_delete(): void
    {
        $entity = $this->createEntity('milestone', ['account_id' => '42', 'tenant_id' => 'tenant-1']);
        $account = $this->createAuthenticatedAccount(99, 'tenant-1');

        foreach (['view', 'update', 'delete'] as $operation) {
            $result = $this->policy->access($entity, $operation, $account);
            self::assertTrue($result->isAllowed(), "Tenant member should be allowed to {$operation}.");
        }
    }

    #[Test]
    public function non_tenant_user_gets_neutral(): void
    {
        $entity = $this->createEntity('milestone', ['account_id' => '42', 'tenant_id' => 'tenant-1']);
        $account = $this->createAuthenticatedAccount(99, 'tenant-2');

        $result = $this->policy->access($entity, 'view', $account);

        self::assertTrue($result->isNeutral());
    }

    #[Test]
    public function create_access_allowed_for_authenticated_with_tenant(): void
    {
        $account = $this->createAuthenticatedAccount(1, 'tenant-1');

        $result = $this->policy->createAccess('milestone', 'milestone', $account);

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function create_access_forbidden_without_tenant(): void
    {
        $account = $this->createAuthenticatedAccount(1, null);

        $result = $this->policy->createAccess('milestone', 'milestone', $account);

        self::assertTrue($result->isForbidden());
    }

    #[Test]
    public function create_access_denied_for_anonymous(): void
    {
        $account = $this->createAnonymousAccount();

        $result = $this->policy->createAccess('milestone', 'milestone', $account);

        self::assertTrue($result->isUnauthenticated());
    }
}

<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Temporal;

use Claudriel\Entity\Account;
use Claudriel\Entity\Workspace;
use Claudriel\Temporal\TimezoneResolver;
use PHPUnit\Framework\TestCase;

final class TimezoneResolverTest extends TestCase
{
    public function test_prefers_request_timezone_override(): void
    {
        $resolver = new TimezoneResolver;
        $workspace = new Workspace(['timezone' => 'America/Toronto']);
        $account = new Account(['timezone' => 'Europe/Berlin']);

        $resolved = $resolver->resolve($account, $workspace, 'America/Los_Angeles');

        self::assertSame([
            'timezone' => 'America/Los_Angeles',
            'source' => 'request',
        ], $resolved->toArray());
    }

    public function test_prefers_workspace_timezone_before_account_timezone(): void
    {
        $resolver = new TimezoneResolver;
        $workspace = new Workspace(['timezone' => 'America/Toronto']);
        $account = new Account(['timezone' => 'Europe/Berlin']);

        $resolved = $resolver->resolve($account, $workspace);

        self::assertSame('America/Toronto', $resolved->timezone()->getName());
        self::assertSame('workspace.timezone', $resolved->source());
    }

    public function test_resolves_timezone_from_workspace_metadata_for_workspace_scoped_requests(): void
    {
        $resolver = new TimezoneResolver;
        $workspace = new Workspace([
            'metadata' => json_encode(['timezone' => 'America/Chicago'], JSON_THROW_ON_ERROR),
        ]);

        $resolved = $resolver->resolve(null, $workspace);

        self::assertSame('America/Chicago', $resolved->timezone()->getName());
        self::assertSame('workspace.metadata.timezone', $resolved->source());
    }

    public function test_resolves_timezone_from_account_preferences_for_user_scoped_requests(): void
    {
        $resolver = new TimezoneResolver;
        $account = new Account([
            'preferences' => json_encode(['timezone' => 'Europe/Paris'], JSON_THROW_ON_ERROR),
        ]);

        $resolved = $resolver->resolve($account, null);

        self::assertSame('Europe/Paris', $resolved->timezone()->getName());
        self::assertSame('account.preferences.timezone', $resolved->source());
    }

    public function test_falls_back_to_documented_default_timezone(): void
    {
        $resolver = new TimezoneResolver('UTC');

        $resolved = $resolver->resolve();

        self::assertSame('UTC', $resolved->timezone()->getName());
        self::assertSame('default', $resolved->source());
    }
}

<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Support;

use Claudriel\Support\PublicAccountDeployValidationScript;
use PHPUnit\Framework\TestCase;

final class PublicAccountDeployValidationScriptTest extends TestCase
{
    public function test_builds_non_destructive_signup_and_login_probe_script(): void
    {
        $script = (new PublicAccountDeployValidationScript)->build('https://claudriel.northcloud.one');

        self::assertStringContainsString('/signup', $script);
        self::assertStringContainsString('/login', $script);
        self::assertStringContainsString('Create Your Claudriel Account', $script);
        self::assertStringContainsString('Log in to Claudriel', $script);
        self::assertStringContainsString('Name, email, and password are required.', $script);
        self::assertStringContainsString('Invalid credentials.', $script);
    }
}

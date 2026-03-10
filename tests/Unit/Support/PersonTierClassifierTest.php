<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Support;

use Claudriel\Support\PersonTierClassifier;
use PHPUnit\Framework\TestCase;

final class PersonTierClassifierTest extends TestCase
{
    public function test_classifies_noreply_as_automated(): void
    {
        $this->assertSame('automated', PersonTierClassifier::classify('noreply@example.com'));
    }

    public function test_classifies_notifications_as_automated(): void
    {
        $this->assertSame('automated', PersonTierClassifier::classify('notifications@github.com'));
    }

    public function test_classifies_regular_email_as_contact(): void
    {
        $this->assertSame('contact', PersonTierClassifier::classify('jane@example.com'));
    }

    public function test_classification_is_case_insensitive(): void
    {
        $this->assertSame('automated', PersonTierClassifier::classify('NoReply@Example.COM'));
    }
}

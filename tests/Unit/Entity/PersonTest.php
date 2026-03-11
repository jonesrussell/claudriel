<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Entity;

use Claudriel\Entity\Person;
use PHPUnit\Framework\TestCase;

final class PersonTest extends TestCase
{
    public function test_entity_type_id(): void
    {
        $person = new Person(['email' => 'jane@example.com', 'name' => 'Jane']);
        self::assertSame('person', $person->getEntityTypeId());
    }

    public function test_tier_defaults_to_contact(): void
    {
        $person = new Person;
        $this->assertSame('contact', $person->get('tier'));
    }

    public function test_tier_can_be_set(): void
    {
        $person = new Person;
        $person->set('tier', 'creator');
        $this->assertSame('creator', $person->get('tier'));
    }

    public function test_last_interaction_at_defaults_to_null(): void
    {
        $person = new Person;
        self::assertNull($person->get('last_interaction_at'));
    }

    public function test_source_defaults_to_gmail(): void
    {
        $person = new Person;
        self::assertSame('gmail', $person->get('source'));
    }

    public function test_metadata_defaults_to_empty_json(): void
    {
        $person = new Person;
        self::assertSame('{}', $person->get('metadata'));
    }
}

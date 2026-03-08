<?php

declare(strict_types=1);

namespace MyClaudia\Tests\Unit\Entity;

use MyClaudia\Entity\McEvent;
use PHPUnit\Framework\TestCase;

final class McEventTest extends TestCase
{
    public function testEntityTypeId(): void
    {
        $event = new McEvent(['source' => 'gmail', 'type' => 'message.received', 'payload' => '{}']);
        self::assertSame('mc_event', $event->getEntityTypeId());
    }

    public function testSourceAndType(): void
    {
        $event = new McEvent(['source' => 'gmail', 'type' => 'message.received', 'payload' => '{}']);
        self::assertSame('gmail', $event->get('source'));
        self::assertSame('message.received', $event->get('type'));
    }
}

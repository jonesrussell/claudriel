<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Support;

use Claudriel\Support\ContentHasher;
use PHPUnit\Framework\TestCase;

final class ContentHasherTest extends TestCase
{
    public function test_hashes_calendar_event_by_title_start_time_calendar_id(): void
    {
        $payload = [
            'source' => 'google-calendar',
            'title' => 'Job Applications',
            'start_time' => '2026-03-10T08:00:00',
            'calendar_id' => 'primary',
        ];
        $hash = ContentHasher::hash($payload);
        $this->assertSame(64, strlen($hash));
        $this->assertSame($hash, ContentHasher::hash($payload));
    }

    public function test_hashes_gmail_message_by_message_id(): void
    {
        $payload = [
            'source' => 'gmail',
            'message_id' => 'msg-abc-123',
        ];
        $hash = ContentHasher::hash($payload);
        $this->assertSame(64, strlen($hash));
        $this->assertSame($hash, ContentHasher::hash($payload));
    }

    public function test_hashes_generic_source_by_source_type_body(): void
    {
        $payload = [
            'source' => 'webhook',
            'type' => 'notification',
            'body' => 'Hello world',
        ];
        $hash = ContentHasher::hash($payload);
        $this->assertSame(64, strlen($hash));
        $this->assertSame($hash, ContentHasher::hash($payload));
    }

    public function test_different_sources_produce_different_hashes(): void
    {
        $gmail = ContentHasher::hash(['source' => 'gmail', 'message_id' => 'abc']);
        $calendar = ContentHasher::hash(['source' => 'google-calendar', 'title' => 'abc', 'start_time' => '', 'calendar_id' => '']);
        $this->assertNotSame($gmail, $calendar);
    }

    public function test_empty_payload_does_not_throw(): void
    {
        $hash = ContentHasher::hash([]);
        $this->assertSame(64, strlen($hash));
    }
}

<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\BroadcastController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BroadcastControllerTest extends TestCase
{
    #[Test]
    public function stream_loop_emits_connected_event_and_keepalive(): void
    {
        $controller = new BroadcastController;
        $chunks = [];
        $flushCount = 0;
        $shouldStopCalls = 0;
        $sleepCalls = 0;

        $controller->streamLoop(
            ['admin'],
            outputCallback: static function (string $data) use (&$chunks): void {
                $chunks[] = $data;
            },
            flushCallback: static function () use (&$flushCount): void {
                $flushCount++;
            },
            shouldStop: static function () use (&$shouldStopCalls): bool {
                return $shouldStopCalls++ >= 1;
            },
            sleepCallback: static function () use (&$sleepCalls): void {
                $sleepCalls++;
            },
        );

        $output = implode('', $chunks);
        self::assertStringContainsString("retry: 3000\n\n", $output);
        self::assertStringContainsString("event: connected\n", $output);
        self::assertStringContainsString('"event":"connected"', $output);
        self::assertStringContainsString(': keepalive ', $output);
        self::assertGreaterThan(0, $flushCount);
        self::assertSame(1, $sleepCalls);
    }
}

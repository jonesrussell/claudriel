<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Access\AccountInterface;

final class BroadcastController
{
    /**
     * GET /api/broadcast - minimal SSE stream to prevent client reconnect loops.
     */
    public function stream(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): StreamedResponse
    {
        $rawChannels = is_string($query['channels'] ?? null) ? (string) $query['channels'] : 'admin';
        $channels = array_values(array_filter(array_map('trim', explode(',', $rawChannels)), static fn (string $channel): bool => $channel !== ''));
        if ($channels === []) {
            $channels = ['admin'];
        }

        return new StreamedResponse(
            function () use ($channels): void {
                set_time_limit(0);
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }

                $this->streamLoop($channels);
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /**
     * @param  list<string>  $channels
     */
    public function streamLoop(
        array $channels,
        ?\Closure $outputCallback = null,
        ?\Closure $flushCallback = null,
        ?\Closure $shouldStop = null,
        ?\Closure $sleepCallback = null,
    ): void {
        $output = $outputCallback ?? static function (string $data): void {
            echo $data;
        };
        $flush = $flushCallback ?? static function (): void {
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        };
        $shouldStop = $shouldStop ?? static fn (): bool => connection_aborted() === 1;
        $sleep = $sleepCallback ?? static function (): void {
            sleep(30);
        };

        $primaryChannel = $channels[0] ?? 'admin';
        $connectedPayload = json_encode([
            'channel' => $primaryChannel,
            'event' => 'connected',
            'data' => ['channels' => $channels],
            'timestamp' => time(),
        ], JSON_THROW_ON_ERROR);

        $output("retry: 3000\n\n");
        $output("event: connected\ndata: {$connectedPayload}\n\n");
        $flush();

        $startTime = time();
        $maxDurationSeconds = 300;

        while (! $shouldStop()) {
            $output(': keepalive '.time()."\n\n");
            $flush();

            if ((time() - $startTime) >= $maxDurationSeconds) {
                break;
            }

            $sleep();
        }
    }
}

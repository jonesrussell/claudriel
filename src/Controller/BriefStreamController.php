<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Support\BriefSignal;
use Claudriel\Support\DriftDetector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class BriefStreamController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly mixed $twig = null,
    ) {}

    /**
     * GET /stream/brief -- SSE stream that pushes brief updates when signal file changes.
     */
    public function stream(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): StreamedResponse|SsrResponse
    {
        $requestId = $this->resolveRequestId($httpRequest, $query);
        $userId = $this->resolveUserId($account);

        if (($query['transport'] ?? null) === 'fallback') {
            $payload = $this->buildFallbackPayload();
            $this->logTransport('brief_stream_fallback', [
                'request_id' => $requestId,
                'user_id' => $userId,
                'workspace_count' => count($payload['workspaces']),
            ]);

            return $this->json($payload);
        }

        $storageDir = getenv('CLAUDRIEL_STORAGE') ?: dirname(__DIR__, 2).'/storage';
        $signalFile = $storageDir.'/brief-signal.txt';
        $context = [
            'request_id' => $requestId,
            'user_id' => $userId,
        ];

        return new StreamedResponse(
            function () use ($signalFile, $context): void {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }

                try {
                    $payload = $this->buildFallbackPayload();
                    $this->logTransport('brief_stream_start', $context + [
                        'workspace_count' => count($payload['workspaces']),
                    ]);
                    $this->streamLoop($signalFile, initialPayload: $payload['briefs']);
                    $this->logTransport('brief_stream_end', $context + [
                        'workspace_count' => count($payload['workspaces']),
                    ]);
                } catch (\Throwable $e) {
                    $fallbackPayload = $this->buildFallbackPayload();
                    $this->logTransport('brief_stream_error', $context + [
                        'workspace_count' => count($fallbackPayload['workspaces']),
                        'error' => $e->getMessage(),
                    ]);
                }
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /**
     * The SSE loop. Extracted for testability: all I/O goes through callbacks.
     */
    public function streamLoop(
        string $signalFile,
        ?\Closure $outputCallback = null,
        ?\Closure $flushCallback = null,
        ?\Closure $shouldStop = null,
        ?\Closure $sleepCallback = null,
        ?array $initialPayload = null,
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
            usleep(2_000_000);
        };

        $signal = new BriefSignal($signalFile);
        $lastMtime = 0;
        $lastKeepalive = time();
        $startTime = time();
        $maxDuration = 300; // 5 minutes

        $output("retry: 3000\n\n");
        $flush();

        // Emit initial brief immediately
        $briefJson = json_encode($initialPayload ?? $this->buildFallbackPayload()['briefs'], JSON_THROW_ON_ERROR);
        $output("event: brief-update\ndata: {$briefJson}\n\n");
        $flush();
        $lastMtime = $signal->lastModified();

        while (! $shouldStop()) {
            // Check for signal changes
            if ($signal->hasChangedSince($lastMtime)) {
                $lastMtime = $signal->lastModified();
                $briefJson = json_encode($this->buildFallbackPayload()['briefs'], JSON_THROW_ON_ERROR);
                $output("event: brief-update\ndata: {$briefJson}\n\n");
                $flush();
                ($sleepCallback ?? static function (): void {
                    usleep(200_000);
                })();
            }

            // Keepalive every 15 seconds
            $now = time();
            if (($now - $lastKeepalive) >= 15) {
                $output(": keepalive\n\n");
                $flush();
                $lastKeepalive = $now;
            }

            // Disconnect after max duration
            if (($now - $startTime) >= $maxDuration) {
                break;
            }

            $sleep();
        }
    }

    public function buildFallbackPayload(): array
    {
        $eventStorage = $this->entityTypeManager->getStorage('mc_event');
        $commitmentStorage = $this->entityTypeManager->getStorage('commitment');
        $skillStorage = $this->entityTypeManager->getStorage('skill');

        $eventRepo = new StorageRepositoryAdapter($eventStorage);
        $commitmentRepo = new StorageRepositoryAdapter($commitmentStorage);
        $skillRepo = new StorageRepositoryAdapter($skillStorage);
        $driftDetector = new DriftDetector($commitmentRepo);

        $personRepo = null;
        try {
            $personRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('person'));
        } catch (\Throwable) {
        }

        $workspaceRepo = null;
        try {
            $workspaceRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('workspace'));
        } catch (\Throwable) {
        }

        $assembler = new DayBriefAssembler($eventRepo, $commitmentRepo, $driftDetector, $personRepo, $skillRepo, $workspaceRepo);
        $brief = $assembler->assemble('default', new \DateTimeImmutable('-24 hours'));

        $briefs = $brief;
        $briefs['commitments']['pending'] = array_map(fn ($c) => $c->toArray(), $brief['commitments']['pending']);
        $briefs['commitments']['drifting'] = array_map(fn ($c) => $c->toArray(), $brief['commitments']['drifting']);
        $briefs['matched_skills'] = array_map(fn ($s) => $s->toArray(), $brief['matched_skills']);

        return [
            'workspaces' => $briefs['workspaces'] ?? [],
            'briefs' => $briefs,
            'updated_at' => (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM),
        ];
    }

    private function resolveRequestId(mixed $httpRequest, array $query): string
    {
        $headerId = null;
        if ($httpRequest instanceof Request) {
            $headerId = $httpRequest->headers->get('X-Request-Id');
        }

        $queryId = $query['request_id'] ?? null;
        $requestId = is_string($headerId) && $headerId !== '' ? $headerId : (is_string($queryId) && $queryId !== '' ? $queryId : bin2hex(random_bytes(8)));

        return $requestId;
    }

    private function resolveUserId(mixed $account): ?string
    {
        if (is_object($account)) {
            foreach (['id', 'getId', 'uuid', 'getUuid'] as $property) {
                if (property_exists($account, $property) && is_scalar($account->{$property})) {
                    return (string) $account->{$property};
                }
                if (method_exists($account, $property)) {
                    $value = $account->{$property}();
                    if (is_scalar($value)) {
                        return (string) $value;
                    }
                }
            }
        }

        if (is_scalar($account)) {
            return (string) $account;
        }

        return null;
    }

    private function logTransport(string $event, array $context): void
    {
        error_log(json_encode([
            'event' => $event,
            'channel' => 'brief_stream_transport',
            'context' => $context,
        ], JSON_THROW_ON_ERROR));
    }

    private function json(array $data, int $statusCode = 200): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
